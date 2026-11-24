import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Badge,
    Button,
    Drawer,
    Input,
    Modal,
    Select,
    Popconfirm,
    Space,
    Tag,
    Tooltip,
    Typography,
    message,
} from "antd";
import {
    ArrowDownOutlined,
    ArrowUpOutlined,
    CloseOutlined,
    DeleteOutlined,
    PaperClipOutlined,
    PlusOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import SheetCell from "~/components/SheetCell";
import { AccomplishmentField } from "~/components/AccomplishmentView";
import LineDrawerTitle, { formWhen } from "~/components/LineDrawerTitle";
import { toPlainText } from "~/components/RichTextView";
import { usePerson } from "~/hooks/usePerson";
import UserAvatar from "~/components/UserAvatar";
import AssignDrawer from "~/components/AssignDrawer";
import ProgressCell from "~/components/ProgressCell";
import OpcrLineDrawer from "~/components/OpcrLineDrawer";
import LineCards from "~/components/LineCards";
import { ADJECTIVAL_COLORS, DELAY_META, NARRATIVE_MAX, SECTION_LABELS } from "~/utils/constants";
import { returnMark } from "~/utils/returnMarks";
import { ratingRules } from "~/utils/ratingScale";
import { ScoreCells, ScoreHeaders } from "~/components/SheetScoreCells";
import AttendancePct from "~/components/AttendancePct";
import { useAuth } from "~/hooks/useAuth";

const SECTIONS = ["strategic", "core", "support"];

function lacksLinkedCommitment(line) {
    return (line.assignments ?? []).length > 0 && (line.children ?? []).length === 0;
}

function localParentId(output, ids) {
    return output.parent_output_id && ids.has(output.parent_output_id) ? output.parent_output_id : null;
}

function siblingsOf(outputs, output) {
    const ids = new Set(outputs.map((row) => row.id));
    const parent = localParentId(output, ids);

    return outputs.filter((row) => localParentId(row, ids) === parent);
}

/**
 * The OPCR as the college already knows it: one page, the same grid that gets
 * printed, filled in place. The president works down the sheet adding rows
 * rather than opening a dialog for every line — the paper form is the mental
 * model, so the screen should not fight it.
 */
export default function OpcrSheet({ form, periodId, canEdit, canAssign, canRecordProgress, showScores = false, showProgress = true, scoreEdit = null, scoreLegend = null, showAccomplishments = true, summary, focusLineId = null, focusOutputId = null, openFocusedLine = true, returnMarks = [], onExpand, onReplaceUi = null }) {
    const { user } = useAuth();
    const dimensions = ratingRules(user).dimensions;
    const queryClient = useQueryClient();
    const [assigning, setAssigning] = useState(null);   // an output, or an indicator
    const [justAdded, setJustAdded] = useState(null);   // { kind, id }
    const [openLineId, setOpenLineId] = useState(null);
    const [editing, setEditing] = useState(false);
    const [drafts, setDrafts] = useState({});
    const [sendBackOpen, setSendBackOpen] = useState(false);
    const [sendBackNote, setSendBackNote] = useState("");
    const focusedLine = useRef(null);
    const focusedOutput = useRef(null);
    const { openPerson } = usePerson();

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
    const opcrRules = user?.workflow?.opcr ?? {
        publisher_roles: ["president"],
        approver_roles: ["qa"],
    };
    const canReplace = form.status === "published" && (opcrRules.publisher_roles ?? []).includes(user?.role);
    const canDecideReplacement = (opcrRules.approver_roles ?? []).includes(user?.role);

    const propose = useMutation({
        mutationFn: (lines) => api.post("target-replacements/batch", { lines }),
        onSuccess: () => {
            message.success("Sent to QA. Highlighted cells stay as they are until they approve.");
            setEditing(false);
            setDrafts({});
            refresh();
        },
    });

    const decideReplacement = useMutation({
        mutationFn: ({ approve, note }) =>
            api.post(`pcr-forms/${form.id}/target-replacements`, {
                approve,
                ...(note ? { note } : {}),
            }),
        onSuccess: (_data, { approve }) => {
            message.success(approve ? "Changes approved." : "Changes sent back.");
            setSendBackOpen(false);
            setSendBackNote("");
            refresh();
        },
    });

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: Boolean(assigning || editing),
        staleTime: 5 * 60 * 1000,
    });

    const saveOutput = useMutation({
        mutationFn: (values) => api.post("pcr-outputs", { form_id: form.id, ...values }),
        onSuccess: async ({ data }, values) => {
            const saved = data?.output;
            const key = ["pcr-form", String(form.id)];

            await queryClient.cancelQueries({ queryKey: key });

            if (saved) {
                queryClient.setQueryData(key, (current) =>
                    current?.outputs
                        ? {
                              ...current,
                              outputs: current.outputs.map((output) =>
                                  output.id === saved.id
                                      ? {
                                            ...output,
                                            title: saved.title,
                                            section: saved.section,
                                            parent_output_id: saved.parent_output_id,
                                            sort_order: saved.sort_order,
                                        }
                                      : output
                              ),
                          }
                        : current
                );
            }

            // A row added to the foot of a long sheet is easy to miss, so say
            // it happened and take the person to it.
            if (!values.id) {
                message.success("MFO/PPA added — name it and add its success indicators.");
                setJustAdded({ kind: "output", id: data.output.id });
            }

            refresh();
        },
    });

    /** Numbering is positional, so moving a row is how you renumber it. */
    const swapOrder = useMutation({
        mutationFn: async ({ kind, rows, index, offset }) => {
            const a = rows[index];
            const b = rows[index + offset];

            const url = kind === "output" ? "pcr-outputs" : "pcr-indicators";
            const base = (row) =>
                kind === "output"
                    ? { form_id: form.id, section: row.section, title: row.title, parent_output_id: row.parent_output_id }
                    : {
                          output_id: row.output_id,
                          description: row.description,
                          allotted_budget: row.allotted_budget,
                          accountable: row.accountable,
                          target_date: row.target_date,
                          rating_period_id: row.rating_period_id,
                          // Without this a reorder silently unhooks the line
                          // from whatever was delegated against it.
                          parent_indicator_id: row.parent_indicator_id,
                      };

            await api.post(url, { ...base(a), id: a.id, sort_order: b.sort_order });
            await api.post(url, { ...base(b), id: b.id, sort_order: a.sort_order });
        },
        onSuccess: refresh,
    });

    const removeOutput = useMutation({
        mutationFn: (id) => api.delete(`pcr-outputs/${id}`),
        onSuccess: () => {
            message.success("Row removed.");
            refresh();
        },
    });

    const saveIndicator = useMutation({
        mutationFn: (values) => api.post("pcr-indicators", { rating_period_id: null, ...values }),
        onSuccess: async ({ data }, values) => {
            const saved = data?.indicator;
            const key = ["pcr-form", String(form.id)];

            await queryClient.cancelQueries({ queryKey: key });

            if (saved) {
                queryClient.setQueryData(key, (current) =>
                    current?.outputs
                        ? {
                              ...current,
                              outputs: current.outputs.map((output) => ({
                                  ...output,
                                  indicators: (output.indicators ?? []).map((line) =>
                                      line.id === saved.id
                                          ? {
                                                ...line,
                                                description: saved.description,
                                                target_date: saved.target_date,
                                                allotted_budget: saved.allotted_budget,
                                                accountable: saved.accountable,
                                                parent_indicator_id: saved.parent_indicator_id,
                                                sort_order: saved.sort_order,
                                                rating_period_id: saved.rating_period_id,
                                            }
                                          : line
                                  ),
                              })),
                          }
                        : current
                );
            }

            if (!values.id) {
                message.success("Success indicator added.");
                setJustAdded({ kind: "indicator", id: data.indicator.id });
            }

            refresh();
        },
    });

    /**
     * The API replaces a line wholesale, so a cell that only knows its own
     * value has to send its neighbours along or it would blank them.
     */
    const patchLine = (line, outputId, changes) =>
        saveIndicator.mutate({
            id: line.id,
            output_id: outputId,
            description: line.description,
            allotted_budget: line.allotted_budget,
            accountable: line.accountable,
            target_date: line.target_date,
            parent_indicator_id: line.parent_indicator_id,
            ...changes,
        });

    const removeIndicator = useMutation({
        mutationFn: (id) => api.delete(`pcr-indicators/${id}`),
        onSuccess: () => {
            message.success("Row removed.");
            refresh();
        },
    });

    const withdraw = useMutation({
        mutationFn: (childId) => api.delete(`pcr-assignments/${childId}`),
        onSuccess: () => {
            message.success("Assignment withdrawn.");
            refresh();
        },
    });

    const assign = useMutation({
        mutationFn: (ids) =>
            api.post(
                assigning.kind === "output"
                    ? `pcr-outputs/${assigning.id}/assign`
                    : `pcr-indicators/${assigning.id}/assign`,
                { user_ids: ids, rating_period_id: periodId }
            ),
        onSuccess: ({ data }) => {
            message.success(
                data.assigned === 0
                    ? "They already have this one."
                    : `Assigned to ${data.assigned} ${data.assigned === 1 ? "person" : "people"}.`
            );
            setAssigning(null);
            refresh();
        },
    });

    useEffect(() => {
        if (!focusLineId || focusedLine.current === focusLineId) return;

        const exists = (form.outputs ?? []).some((output) =>
            (output.indicators ?? []).some((line) => line.id === focusLineId)
        );

        if (!exists) return;

        focusedLine.current = focusLineId;

        if (openFocusedLine) {
            setOpenLineId(focusLineId);
        }

        const row = document.querySelector(`[data-row="indicator-${focusLineId}"]`);

        if (!row) return;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        const timer = setTimeout(() => row.classList.remove("is-new"), 2200);

        return () => clearTimeout(timer);
    }, [focusLineId, form.outputs, openFocusedLine]);

    useEffect(() => {
        if (!focusOutputId || focusedOutput.current === focusOutputId) return;

        const nodes = [...document.querySelectorAll(`[data-output-id="${focusOutputId}"]`)];
        const cell = nodes.find((node) => node.offsetParent !== null) ?? nodes[0];

        if (!cell) return;

        focusedOutput.current = focusOutputId;
        const row = cell.closest("tr") ?? cell;
        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        const timer = setTimeout(() => row.classList.remove("is-new"), 2200);

        return () => clearTimeout(timer);
    }, [focusOutputId, form.outputs]);

    useEffect(() => {
        if (!justAdded) return;

        const key = `${justAdded.kind}-${justAdded.id}`;
        const row =
            document.querySelector(`[data-row="${key}"]`) ??
            document.querySelector(`[data-output-row="${key}"]`) ??
            document.querySelector(`[data-output-id="${justAdded.id}"]`);

        if (!row) return;   // not rendered yet; the next data pass finds it

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        setJustAdded(null);

        // No cleanup cancelling these: a refetch re-runs this effect and would
        // otherwise cancel the click before it ever fired.
        setTimeout(() => row.querySelector(".pms-cell")?.click(), 400);
        setTimeout(() => row.classList.remove("is-new"), 2200);
    }, [justAdded, form.outputs]);

/** Only this period's lines, and only sections that have something in them. */
    const bySection = useMemo(() => {
        const map = {};

        SECTIONS.forEach((section) => {
            map[section] = (form.outputs ?? [])
                .filter((o) => o.section === section)
                .map((o) => ({
                    ...o,
                    lines: (o.indicators ?? []).filter(
                        (i) =>
                            (!i.rating_period_id || i.rating_period_id === periodId) &&
                            i.replacement_status !== "pending" &&
                            !i.closed_at
                    ),
                }));
        });

        return map;
    }, [form.outputs, periodId]);

    const rollUp = (lines) => {
        const measured = lines.filter((line) => !lacksLinkedCommitment(line));

        if (!measured.length) return null;

        const pct = Math.round(
            measured.reduce((total, l) => total + Number(l.progress_pct ?? 0), 0) / measured.length
        );
        const status = measured.every((l) => l.progress_status === "completed")
            ? "completed"
            : measured.some((l) => l.progress_status !== "not_started" || Number(l.progress_pct ?? 0) > 0)
              ? "ongoing"
              : "not_started";

        return { pct, status };
    };

    /**
     * The people the line was actually handed to. Names come back as people so
     * the column can open them, and fall back to the typed column only for
     * lines nobody has been assigned.
     */
    const accountableFor = (indicator) => {
        const assigned = (indicator.assignments ?? [])
            .filter((row) => row.user)
            .map((row) => ({ ...row.user, assignmentId: row.id, assigned: true }));

        const already = new Set(assigned.map((person) => person.id));

        const fromChildren = (indicator.children ?? [])
            .filter((c) => c.output?.form?.owner && !already.has(c.output.form.owner.id))
            .map((c) => ({
                ...c.output.form.owner,
                childId: c.id,
                assigned: Boolean(c.assigned_by),
            }));

        return [...assigned, ...fromChildren];
    };

    const openLine = useMemo(() => {
        if (!openLineId) return null;

        for (const output of form.outputs ?? []) {
            const found = (output.indicators ?? []).find((i) => i.id === openLineId);

            if (found) return { line: found, output };
        }

        return null;
    }, [openLineId, form.outputs]);

    const columnCount =
        6 - (showProgress ? 0 : 1) + (showAccomplishments ? 1 : 0) + (showScores ? dimensions.length + 1 + (scoreEdit ? 1 : 0) : 0);
    const tailSpan = columnCount - (scoreEdit ? 5 : 4);
    const idsOf = (line) =>
        (line.assignments ?? [])
            .map((row) => row.user_id)
            .filter(Boolean)
            .sort((a, b) => a - b);
    const baseOf = (line) => ({
        description: toPlainText(line.description),
        target_date: line.target_date ? String(line.target_date).slice(0, 10) : "",
        allotted_budget: line.allotted_budget == null ? "" : String(line.allotted_budget),
        user_ids: idsOf(line),
    });
    const viewOf = (line) => ({ ...baseOf(line), ...drafts[line.id] });
    const sameList = (left, right) =>
        left.join(",") === [...right].sort((a, b) => a - b).join(",");
    const fieldChanged = (line, field) => {
        const base = baseOf(line);
        const view = viewOf(line);

        return field === "user_ids"
            ? !sameList(base.user_ids, view.user_ids ?? [])
            : String(base[field] ?? "") !== String(view[field] ?? "");
    };
    const proposalChanged = (line, proposal, field) => {
        if (!proposal) return false;
        if (field === "description") {
            return toPlainText(proposal.description) !== toPlainText(line.description);
        }
        if (field === "target_date") {
            return String(proposal.target_date ?? "").slice(0, 10) !== String(line.target_date ?? "").slice(0, 10);
        }
        if (field === "allotted_budget") {
            return Number(proposal.allotted_budget ?? 0) !== Number(line.allotted_budget ?? 0);
        }

        return !sameList(idsOf(line), idsOf(proposal));
    };
    const keepDraft = (line, patch) => {
        setDrafts((current) => ({
            ...current,
            [line.id]: { ...baseOf(line), ...current[line.id], ...patch },
        }));
    };
    const liveLines = (form.outputs ?? []).flatMap((output) =>
        (output.indicators ?? []).filter((line) => line.replacement_status !== "pending" && !line.closed_at)
    );
    const changedLines = liveLines.filter((line) =>
        ["description", "target_date", "allotted_budget", "user_ids"].some((field) => fieldChanged(line, field))
    );
    const pendingCount = (form.outputs ?? [])
        .flatMap((output) => output.indicators ?? [])
        .filter((line) => line.replacement_status === "pending").length;
    const replaceRun = useRef({});

    replaceRun.current = {
        start: () => {
            setEditing(true);
            setDrafts({});
        },
        cancel: () => {
            setEditing(false);
            setDrafts({});
        },
        send: () =>
            propose.mutate(
                changedLines.map((line) => {
                    const view = viewOf(line);

                    return {
                        indicator_id: line.id,
                        description: view.description,
                        target_date: view.target_date || null,
                        allotted_budget: view.allotted_budget === "" ? null : view.allotted_budget,
                        user_ids: view.user_ids,
                    };
                })
            ),
        approve: () => decideReplacement.mutate({ approve: true }),
        reject: () => {
            setSendBackNote("");
            setSendBackOpen(true);
        },
    };

    useEffect(() => {
        if (!onReplaceUi) return undefined;

        return () => onReplaceUi(null);
    }, [onReplaceUi]);

    useEffect(() => {
        if (!onReplaceUi) return;

        onReplaceUi({
            showReplace: canReplace && !editing && pendingCount === 0,
            editing,
            changedCount: changedLines.length,
            proposePending: propose.isPending,
            showDecide: canDecideReplacement && pendingCount > 0,
            decidePending: decideReplacement.isPending,
            run: (name) => replaceRun.current[name]?.(),
        });
    }, [
        onReplaceUi,
        canReplace,
        canDecideReplacement,
        editing,
        pendingCount,
        changedLines.length,
        propose.isPending,
        decideReplacement.isPending,
    ]);

    const rows = [];

    SECTIONS.forEach((section) => {
        const outputs = bySection[section];

        if (!outputs.length && !canEdit) return;

        rows.push(
            <tr key={`section-${section}`} className="pms-sheet-section">
                <td colSpan={columnCount}>
                    <Space>
                        <span>{SECTION_LABELS[section].toUpperCase()}</span>
                        {canEdit && (
                            <Button
                                size="small"
                                type="text"
                                icon={<PlusOutlined />}
                                onClick={() =>
                                    saveOutput.mutate({ section, title: "" })
                                }
                            >
                                Add MFO/PPA
                            </Button>
                        )}
                    </Space>
                </td>
            </tr>
        );

        const sectionBudget = outputs.reduce(
            (total, output) =>
                total + output.lines.reduce((sum, l) => sum + Number(l.allotted_budget ?? 0), 0),
            0
        );

        const sectionAverage = summary?.[`${section}_average`];
        const sectionProgress = rollUp(outputs.flatMap((output) => output.lines));

        outputs.forEach((output) => {
            const number = output.outline_number ?? "";
            const depth = output.outline_depth ?? 0;
            const isHeader = Number(output.nested_outputs_count ?? 0) > 0;
            const siblings = siblingsOf(outputs, output);
            const siblingIndex = siblings.findIndex((row) => row.id === output.id);
            const lines = output.lines;
            const span = Math.max(lines.length, 1);

            const numbering = (
                <div className="pms-sheet-number" style={{ paddingLeft: depth * 14 }}>
                    <span>{number ? `${number}.` : ""}</span>
                    <SheetCell
                        value={output.title}
                        disabled={!canEdit}
                        placeholder="Name this MFO/PPA"
                        maxLength={NARRATIVE_MAX}
                        mark={returnMark(returnMarks, { outputId: output.id, field: "title" })}
                        onCommit={(title) =>
                            saveOutput.mutate({
                                id: output.id,
                                section,
                                title: title ?? "",
                                parent_output_id: output.parent_output_id,
                            })
                        }
                    />
                </div>
            );

            const outputCell = (
                <td rowSpan={span} className="pms-sheet-output" data-output-id={output.id}>
                    {numbering}
                    {showScores && <AttendancePct form={form} output={output} periodId={periodId} />}
                    {canEdit && (
                        <Space size={2} className="pms-sheet-rowtools">
                            <Tooltip title="Add a PPA under this MFO">
                                <Button
                                    size="small"
                                    type="text"
                                    icon={<PlusOutlined />}
                                    onClick={() =>
                                        saveOutput.mutate({
                                            section,
                                            title: "",
                                            parent_output_id: output.id,
                                        })
                                    }
                                />
                            </Tooltip>
                            <Tooltip title="Add a success indicator under this MFO/PPA">
                                <Button
                                    size="small"
                                    type="text"
                                    icon={<PlusOutlined />}
                                    onClick={() =>
                                        saveIndicator.mutate({
                                            output_id: output.id,
                                            description: "",
                                        })
                                    }
                                />
                            </Tooltip>
                            <Tooltip title="Move up">
                                <Button
                                    size="small"
                                    type="text"
                                    disabled={siblingIndex === 0}
                                    icon={<ArrowUpOutlined />}
                                    onClick={() =>
                                        swapOrder.mutate({
                                            kind: "output",
                                            rows: siblings,
                                            index: siblingIndex,
                                            offset: -1,
                                        })
                                    }
                                />
                            </Tooltip>
                            <Tooltip title="Move down">
                                <Button
                                    size="small"
                                    type="text"
                                    disabled={siblingIndex === siblings.length - 1}
                                    icon={<ArrowDownOutlined />}
                                    onClick={() =>
                                        swapOrder.mutate({
                                            kind: "output",
                                            rows: siblings,
                                            index: siblingIndex,
                                            offset: 1,
                                        })
                                    }
                                />
                            </Tooltip>
                            <Popconfirm
                                title="Remove this MFO/PPA and its lines?"
                                onConfirm={() => removeOutput.mutate(output.id)}
                            >
                                <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                            </Popconfirm>
                        </Space>
                    )}
                </td>
            );

            if (!lines.length) {
                rows.push(
                    <tr key={`output-${output.id}-empty`} data-output-row={`output-${output.id}`}>
                        {outputCell}
                        <td colSpan={columnCount - 1} className="pms-sheet-empty">
                            {canEdit
                                ? isHeader
                                    ? "Grouping row — add a PPA under it, or a success indicator on this MFO."
                                    : "No success indicator yet — use + to add one."
                                : "—"}
                        </td>
                    </tr>
                );

                return;
            }

            lines.forEach((line, lineIndex) => {
                const rating = (line.ratings ?? []).find((r) => r.rating_period_id === periodId);
                const accomplishment = (line.accomplishments ?? []).find(
                    (a) => a.rating_period_id === periodId
                );
                const computed =
                    (line.children ?? []).length > 0 ||
                    (line.assignments ?? []).length > 0 ||
                    Number(output.delegated_outputs_count ?? 0) > 0;
                const delivery = (line.children ?? []).reduce(
                    (totals, child) => ({
                        people: totals.people + 1,
                        files: totals.files + Number(child.attachments_count ?? 0),
                        recorded:
                            totals.recorded +
                            (Number(child.recorded_count ?? 0) > 0 ||
                            Number(child.attachments_count ?? 0) > 0
                                ? 1
                                : 0),
                    }),
                    { people: 0, files: 0, recorded: 0 }
                );
                const accountableMark = returnMark(returnMarks, {
                    indicatorId: line.id,
                    field: "accountable",
                });
                const accomplishmentMark = returnMark(returnMarks, {
                    indicatorId: line.id,
                    field: "actual_accomplishment",
                });
                const evidenceMark = returnMark(returnMarks, {
                    indicatorId: line.id,
                    field: "evidence",
                });
                const lineCanRecord = Boolean(canRecordProgress) && !line.closed_at;
                const proposal = (output.indicators ?? []).find(
                    (item) => item.replaces_indicator_id === line.id && item.replacement_status === "pending"
                );
                const view = viewOf(line);
                const descChanged = editing
                    ? fieldChanged(line, "description")
                    : proposalChanged(line, proposal, "description");
                const dateChanged = editing
                    ? fieldChanged(line, "target_date")
                    : proposalChanged(line, proposal, "target_date");
                const budgetChanged = editing
                    ? fieldChanged(line, "allotted_budget")
                    : proposalChanged(line, proposal, "allotted_budget");
                const peopleChanged = editing
                    ? fieldChanged(line, "user_ids")
                    : proposalChanged(line, proposal, "user_ids");
                const descValue = editing
                    ? view.description
                    : descChanged
                      ? toPlainText(proposal.description)
                      : toPlainText(line.description);
                const dateValue = editing
                    ? view.target_date
                    : dateChanged
                      ? String(proposal.target_date).slice(0, 10)
                      : line.target_date;
                const budgetValue = editing
                    ? view.allotted_budget
                    : budgetChanged
                      ? proposal.allotted_budget
                      : line.allotted_budget;
                const earlier = (changed, text) =>
                    proposal && !editing && changed ? (
                        <Typography.Text
                            type="secondary"
                            style={{ display: "block", marginTop: 4, fontSize: 12, whiteSpace: "pre-wrap" }}
                        >
                            Previous: {text || "None"}
                        </Typography.Text>
                    ) : null;

                rows.push(
                    <tr
                        key={`line-${line.id}`}
                        data-row={`indicator-${line.id}`}
                        data-output-row={lineIndex === 0 ? `output-${output.id}` : undefined}
                    >
                        {lineIndex === 0 && outputCell}

                        <td className={descChanged ? "pms-flag" : undefined}>
                            <SheetCell
                                value={descValue}
                                disabled={!canEdit && !editing}
                                placeholder="Target + measure + timeframe"
                                maxLength={NARRATIVE_MAX}
                                mark={returnMark(returnMarks, {
                                    indicatorId: line.id,
                                    field: "description",
                                })}
                                onCommit={(description) =>
                                    editing
                                        ? keepDraft(line, { description: description ?? "" })
                                        : patchLine(line, output.id, {
                                              description: description ?? "",
                                          })
                                }
                            />
                            {earlier(descChanged, toPlainText(line.description))}
                            {canEdit && (
                                <Space size={2} className="pms-sheet-rowtools">
                                    <Tooltip title="Move up">
                                        <Button
                                            size="small"
                                            type="text"
                                            disabled={lineIndex === 0}
                                            icon={<ArrowUpOutlined />}
                                            onClick={() =>
                                                swapOrder.mutate({
                                                    kind: "indicator",
                                                    rows: lines,
                                                    index: lineIndex,
                                                    offset: -1,
                                                })
                                            }
                                        />
                                    </Tooltip>
                                    <Tooltip title="Move down">
                                        <Button
                                            size="small"
                                            type="text"
                                            disabled={lineIndex === lines.length - 1}
                                            icon={<ArrowDownOutlined />}
                                            onClick={() =>
                                                swapOrder.mutate({
                                                    kind: "indicator",
                                                    rows: lines,
                                                    index: lineIndex,
                                                    offset: 1,
                                                })
                                            }
                                        />
                                    </Tooltip>
                                    <Popconfirm
                                        title="Remove this line?"
                                        onConfirm={() => removeIndicator.mutate(line.id)}
                                    >
                                        <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                                    </Popconfirm>
                                </Space>
                            )}
                            {line.closed_at && (
                                <Tag color="default" style={{ marginTop: 4 }}>
                                    Replaced
                                </Tag>
                            )}
                            {proposal && (
                                <Tag color="gold" style={{ marginTop: 4 }}>
                                    Waiting for QA
                                </Tag>
                            )}
                        </td>

                        <td className={dateChanged ? "pms-flag" : undefined}>
                            <SheetCell
                                type="date"
                                value={dateValue}
                                disabled={!canEdit && !editing}
                                placeholder="No date"
                                mark={returnMark(returnMarks, {
                                    indicatorId: line.id,
                                    field: "target_date",
                                })}
                                onCommit={(target_date) =>
                                    editing
                                        ? keepDraft(line, { target_date: target_date ? String(target_date).slice(0, 10) : "" })
                                        : patchLine(line, output.id, { target_date })
                                }
                            />
                            {earlier(
                                dateChanged,
                                line.target_date ? String(line.target_date).slice(0, 10) : ""
                            )}
                            {line.delay && line.delay.state !== "no_date" && (
                                <Tag
                                    color={DELAY_META[line.delay.state]?.color}
                                    style={{ marginTop: 4, fontSize: 10 }}
                                >
                                    {DELAY_META[line.delay.state]?.label}
                                </Tag>
                            )}
                        </td>

                        {showProgress && (
                        <td className="pms-sheet-progress">
                            <ProgressCell
                                status={line.progress_status ?? "not_started"}
                                pct={line.progress_pct ?? 0}
                                unavailable={lacksLinkedCommitment(line)}
                                computed
                                computedHint={
                                    (line.children ?? []).length > 0
                                        ? "With Linked Commitment. Rolled up from the commitments linked to this target."
                                        : "The narrative counts as 30%. Two files count as 70%, so one file is 35%. A narrative and both files reach 100%."
                                }
                            />
                        </td>
                        )}

                        <td className={budgetChanged ? "pms-sheet-money pms-flag" : "pms-sheet-money"}>
                            <SheetCell
                                type="number"
                                align="right"
                                value={budgetValue}
                                disabled={!canEdit && !editing}
                                mark={returnMark(returnMarks, {
                                    indicatorId: line.id,
                                    field: "allotted_budget",
                                })}
                                onCommit={(allotted_budget) =>
                                    editing
                                        ? keepDraft(line, {
                                              allotted_budget:
                                                  allotted_budget == null || allotted_budget === ""
                                                      ? ""
                                                      : String(allotted_budget),
                                          })
                                        : patchLine(line, output.id, { allotted_budget })
                                }
                            />
                            {earlier(
                                budgetChanged,
                                line.allotted_budget == null || line.allotted_budget === ""
                                    ? ""
                                    : String(line.allotted_budget)
                            )}
                        </td>

                        <td className={accountableMark || peopleChanged ? "pms-flag" : undefined}>
                            {editing && (
                                <Select
                                    mode="multiple"
                                    size="small"
                                    style={{ width: "100%", marginBottom: 6 }}
                                    placeholder="Accountable"
                                    value={view.user_ids}
                                    options={people.map((person) => ({ value: person.id, label: person.name }))}
                                    onChange={(user_ids) => keepDraft(line, { user_ids })}
                                />
                            )}
                            {!editing && peopleChanged && proposal && (
                                <span>
                                    {(proposal.assignments ?? [])
                                        .map((row) => row.user?.name)
                                        .filter(Boolean)
                                        .join(", ") || "Nobody"}
                                </span>
                            )}
                            {earlier(
                                peopleChanged,
                                accountableFor(line)
                                    .map((person) => person.name)
                                    .filter(Boolean)
                                    .join(", ")
                            )}
                            {!editing && !(peopleChanged && proposal) && (
                            <Space size={4} wrap>
                                {accountableFor(line).map((person) => {
                                    const withdrawId = person.assignmentId ?? person.childId;

                                    return (
                                    <span key={`${person.id}-${withdrawId ?? "name"}`} className="pms-person-chip">
                                        <span
                                            className="pms-person-open"
                                            onClick={() => openPerson(person.id)}
                                        >
                                            <UserAvatar user={person} size={18} showTooltip={false} />
                                            <span className="pms-person-link">{person.name}</span>
                                        </span>
                                        {canAssign && person.assigned && withdrawId && (
                                            <Popconfirm
                                                title={`Take this line back from ${person.name}?`}
                                                description="Only possible while they have not written a commitment against it."
                                                onConfirm={() => withdraw.mutate(withdrawId)}
                                            >
                                                <CloseOutlined className="pms-person-remove" />
                                            </Popconfirm>
                                        )}
                                    </span>
                                    );
                                })}
                                {canAssign && (
                                    <Tooltip
                                        title={
                                            accountableFor(line).length
                                                ? "Assign someone else to this line"
                                                : "Assign this line to someone"
                                        }
                                    >
                                        <Button
                                            size="small"
                                            type={accountableFor(line).length ? "text" : "dashed"}
                                            icon={<UserAddOutlined />}
                                            onClick={() => setAssigning({ kind: "indicator", ...line })}
                                        />
                                    </Tooltip>
                                )}
                                {!canAssign && accountableFor(line).length === 0 && (
                                    <span className="pms-cell-empty">Not yet assigned</span>
                                )}
                            </Space>
                            )}
                            {accountableMark && <div className="pms-cell-mark">{accountableMark}</div>}
                        </td>

                        {showAccomplishments && (
                            <td className="pms-sheet-muted">
                                <AccomplishmentField
                                    formId={form.id}
                                    indicatorId={line.id}
                                    periodId={periodId}
                                    html={accomplishment?.actual_accomplishment}
                                    attachments={accomplishment?.attachments}
                                    canRecord={lineCanRecord}
                                    mark={accomplishmentMark}
                                    evidenceMark={evidenceMark}
                                    empty={
                                        lineCanRecord
                                            ? "Click to record"
                                            : delivery.people > 0
                                              ? ""
                                              : Number(output.delegated_outputs_count ?? 0) > 0
                                                ? "Handed out with the MFO/PPA"
                                                : "Not yet recorded"
                                    }
                                    placeholder="What did the college deliver against this target?"
                                    onOpen={() => setOpenLineId(line.id)}
                                    note={
                                        delivery.people > 0 ? (
                                            <span
                                                className={
                                                    delivery.recorded ? "pms-delivery-count" : "pms-cell-empty"
                                                }
                                            >
                                                {delivery.recorded} of {delivery.people} recorded
                                            </span>
                                        ) : null
                                    }
                                    extra={
                                        delivery.files > 0 ? (
                                            <Badge
                                                count={delivery.files}
                                                size="small"
                                                color="#1e3a72"
                                                offset={[4, 0]}
                                            >
                                                <PaperClipOutlined style={{ opacity: 0.6 }} />
                                            </Badge>
                                        ) : null
                                    }
                                />
                            </td>
                        )}

                        {showScores && <ScoreCells line={line} saved={rating} scoreEdit={scoreEdit} />}
                    </tr>
                );
            });
        });

        // What the paper form prints under each block.
        if (outputs.length && showScores) {
            rows.push(
                <tr key={`summary-${section}`} className="pms-sheet-summary">
                    <td colSpan={3}>AVERAGE RATING — {SECTION_LABELS[section]}</td>
                    {showProgress && (
                    <td className="pms-sheet-progress">
                        {sectionProgress && (
                            <ProgressCell
                                status={sectionProgress.status}
                                pct={sectionProgress.pct}
                            />
                        )}
                    </td>
                    )}
                    <td className="pms-sheet-money">
                        {sectionBudget > 0 ? `₱${sectionBudget.toLocaleString()}` : ""}
                    </td>
                    <td colSpan={showAccomplishments ? 2 : 1} />
                    <td colSpan={dimensions.length} />
                    <td className="pms-sheet-score">
                        {sectionAverage != null ? Number(sectionAverage).toFixed(2) : ""}
                    </td>
                    {scoreEdit && <td />}
                </tr>
            );
        }
    });

    // The whole document's standing, as the form closes.
    const totalBudget = (form.outputs ?? []).reduce(
        (total, output) =>
            total +
            (output.indicators ?? [])
                .filter((i) => !i.rating_period_id || i.rating_period_id === periodId)
                .reduce((sum, l) => sum + Number(l.allotted_budget ?? 0), 0),
        0
    );

    const overall = rollUp(
        SECTIONS.flatMap((section) => bySection[section].flatMap((output) => output.lines))
    );

    if (rows.length && showScores) {
        rows.push(
            <tr key="grand-total" className="pms-sheet-total">
                <td colSpan={3}>TOTAL OVERALL RATING</td>
                {showProgress && (
                <td className="pms-sheet-progress">
                    {overall && <ProgressCell status={overall.status} pct={overall.pct} />}
                </td>
                )}
                <td className="pms-sheet-money">
                    {totalBudget > 0 ? `₱${totalBudget.toLocaleString()}` : ""}
                </td>
                <td colSpan={showAccomplishments ? 2 : 1} />
                <td colSpan={dimensions.length} />
                <td className="pms-sheet-score">
                    {summary?.final_average != null
                        ? Number(summary.final_average).toFixed(2)
                        : ""}
                </td>
                {scoreEdit && <td />}
            </tr>,
            <tr key="adjectival" className="pms-sheet-total">
                <td colSpan={3}>ADJECTIVAL RATING</td>
                <td colSpan={tailSpan} />
                <td className="pms-sheet-score">
                    {summary?.adjectival ? (
                        <Tag color={ADJECTIVAL_COLORS[summary.adjectival]} style={{ margin: 0 }}>
                            {summary.adjectival}
                        </Tag>
                    ) : (
                        ""
                    )}
                </td>
                {scoreEdit && <td />}
            </tr>
        );
    }

    const unlinkedCount = SECTIONS.flatMap((section) =>
        bySection[section].flatMap((output) => output.lines)
    ).filter(lacksLinkedCommitment).length;

    return (
        <>
            {!onReplaceUi && (canReplace || (canDecideReplacement && pendingCount > 0)) && (
                <Space style={{ marginBottom: 12 }} wrap>
                    {canReplace && !editing && pendingCount === 0 && (
                        <Button onClick={() => { setEditing(true); setDrafts({}); }}>Replace</Button>
                    )}
                    {editing && (
                        <>
                            <Typography.Text type="secondary">
                                Edit the cells you want. Highlighted ones will be sent.
                            </Typography.Text>
                            <Button
                                onClick={() => {
                                    setEditing(false);
                                    setDrafts({});
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="primary"
                                disabled={changedLines.length === 0}
                                loading={propose.isPending}
                                onClick={() =>
                                    propose.mutate(
                                        changedLines.map((line) => {
                                            const view = viewOf(line);

                                            return {
                                                indicator_id: line.id,
                                                description: view.description,
                                                target_date: view.target_date || null,
                                                allotted_budget:
                                                    view.allotted_budget === "" ? null : view.allotted_budget,
                                                user_ids: view.user_ids,
                                            };
                                        })
                                    )
                                }
                            >
                                Send to QA{changedLines.length ? ` (${changedLines.length})` : ""}
                            </Button>
                        </>
                    )}
                    {canDecideReplacement && pendingCount > 0 && (
                        <>
                            <Button
                                type="primary"
                                loading={decideReplacement.isPending}
                                onClick={() => decideReplacement.mutate({ approve: true })}
                            >
                                Approve changes
                            </Button>
                            <Button
                                loading={decideReplacement.isPending}
                                onClick={() => {
                                    setSendBackNote("");
                                    setSendBackOpen(true);
                                }}
                            >
                                Send back
                            </Button>
                        </>
                    )}
                </Space>
            )}

            {unlinkedCount > 0 && (
                <div className="pms-unlinked">
                    {unlinkedCount} assigned OPCR target{unlinkedCount === 1 ? "" : "s"} have no linked IPCR
                    commitment{unlinkedCount === 1 ? "" : "s"}.
                </div>
            )}

            <LineCards
                form={form}
                periodId={periodId}
                showScores={showScores}
                showAccomplishments={showAccomplishments}
                onOpen={setOpenLineId}
                onEnter={canEdit && onExpand ? () => onExpand() : undefined}
                addSections={canEdit ? ["strategic", "core", "support"] : []}
                onAdd={(section) => {
                    saveOutput.mutate({ section, title: "" });
                    onExpand?.();
                }}
            />

            <div className="pms-sheet-wrap">
                <table className="pms-sheet">
                    <thead>
                        {/* One row, so a single sticky offset holds while the
                            grid scrolls. The printed form keeps the paper's
                            two-row Rating grouping. */}
                        <tr>
                            <th style={{ width: 190 }}>MFO/PPA</th>
                            <th style={{ width: 300 }}>
                                Success Indicator
                                <div className="pms-sheet-subhead">(Target + Measure)</div>
                            </th>
                            <th style={{ width: 110 }}>Target Date</th>
                            {showProgress && <th style={{ width: 140 }}>Progress</th>}
                            <th style={{ width: 110 }}>Alloted Budget</th>
                            <th style={{ width: 190 }}>Individual / Accountable</th>
                            {showAccomplishments && (
                                <th style={{ width: 280 }}>Actual Accomplishments</th>
                            )}
                            {showScores && <ScoreHeaders scoreEdit={scoreEdit} />}
                        </tr>
                    </thead>
                    <tbody>{rows}</tbody>
                </table>
            </div>

            {scoreLegend ? (
                <div style={{ marginTop: 12 }}>{typeof scoreLegend === "function" ? scoreLegend() : scoreLegend}</div>
            ) : null}

            {canEdit && (
                <Typography.Text className="pms-sheet-hint" type="secondary" style={{ fontSize: 12 }}>
                    Click any cell to edit it. Enter saves, Shift+Enter starts a new line, Escape
                    undoes.
                </Typography.Text>
            )}
            {canRecordProgress && !canEdit && (
                <Typography.Text className="pms-sheet-hint" type="secondary" style={{ fontSize: 12 }}>
                    Click an accomplishment to write it. Enter saves, Shift+Enter starts a new line,
                    Escape undoes.
                </Typography.Text>
            )}

            <Drawer
                title={
                    openLine ? (
                        <LineDrawerTitle
                            name={toPlainText(openLine.line.description)}
                            when={formWhen(form, periodId)}
                        />
                    ) : (
                        ""
                    )
                }
                placement="right"
                width={620}
                open={Boolean(openLine)}
                onClose={() => setOpenLineId(null)}
            >
                {openLine && (
                    <OpcrLineDrawer
                        key={openLine.line.id}
                        line={openLine.line}
                        output={openLine.output}
                        form={form}
                        periodId={periodId}
                        canAssign={canAssign}
                        canRecordProgress={canRecordProgress}
                        showAccomplishments={showAccomplishments}
                        scoreEdit={scoreEdit}
                    />
                )}
            </Drawer>

            <AssignDrawer
                open={Boolean(assigning)}
                kind={assigning?.kind}
                isOpcr
                title={assigning?.title ?? toPlainText(assigning?.description)}
                people={people}
                loading={assign.isPending}
                onClose={() => setAssigning(null)}
                onAssign={(ids) => assign.mutate(ids)}
            />

            <Modal
                title="Send these changes back"
                open={sendBackOpen}
                okText="Send back"
                confirmLoading={decideReplacement.isPending}
                okButtonProps={{ disabled: !sendBackNote.trim() }}
                onOk={() =>
                    decideReplacement.mutate({ approve: false, note: sendBackNote.trim() })
                }
                onCancel={() => setSendBackOpen(false)}
                destroyOnClose
            >
                <Input.TextArea
                    rows={3}
                    value={sendBackNote}
                    maxLength={500}
                    placeholder="Why are you sending this back?"
                    onChange={(event) => setSendBackNote(event.target.value)}
                />
            </Modal>
        </>
    );
}
