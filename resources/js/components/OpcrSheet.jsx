import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Badge,
    Button,
    Drawer,
    Modal,
    Popconfirm,
    Select,
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
    ExpandOutlined,
    MessageOutlined,
    PaperClipOutlined,
    PlusOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import SheetCell from "~/components/SheetCell";
import { toPlainText } from "~/components/RichTextView";
import { usePerson } from "~/hooks/usePerson";
import UserAvatar from "~/components/UserAvatar";
import ProgressCell from "~/components/ProgressCell";
import OpcrLineDrawer from "~/components/OpcrLineDrawer";
import LineCards from "~/components/LineCards";
import CommentThread from "~/components/CommentThread";
import { ADJECTIVAL_COLORS, DELAY_META, NARRATIVE_MAX, SECTION_LABELS } from "~/utils/constants";

const SECTIONS = ["strategic", "core", "support"];

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
export default function OpcrSheet({ form, periodId, canEdit, canAssign, canRecordProgress, summary, focusLineId = null }) {
    const queryClient = useQueryClient();
    const [assigning, setAssigning] = useState(null);   // an output, or an indicator
    const [picked, setPicked] = useState([]);
    const [justAdded, setJustAdded] = useState(null);   // { kind, id }
    const [openLineId, setOpenLineId] = useState(null);
    const [commentLineId, setCommentLineId] = useState(null);
    const focusedLine = useRef(null);
    const { openPerson } = usePerson();

    const { data: comments = [] } = useQuery({
        queryKey: ["comments", form.id],
        queryFn: () => api.get(`pcr-forms/${form.id}/comments`).then((r) => r.data),
    });

    const commentCounts = useMemo(
        () =>
            comments.reduce((counts, c) => {
                if (c.indicator_id) counts[c.indicator_id] = (counts[c.indicator_id] ?? 0) + 1;
                return counts;
            }, {}),
        [comments]
    );

    const commentLine = useMemo(() => {
        for (const output of form.outputs ?? []) {
            const found = (output.indicators ?? []).find((i) => i.id === commentLineId);

            if (found) return found;
        }

        return null;
    }, [commentLineId, form.outputs]);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: Boolean(assigning),
        staleTime: 5 * 60 * 1000,
    });

    const saveOutput = useMutation({
        mutationFn: (values) => api.post("pcr-outputs", { form_id: form.id, ...values }),
        onSuccess: ({ data }, values) => {
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
        onSuccess: ({ data }, values) => {
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
            setPicked([]);
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
        setCommentLineId(focusLineId);

        const row = document.querySelector(`[data-row="indicator-${focusLineId}"]`);

        if (!row) return;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        const timer = setTimeout(() => row.classList.remove("is-new"), 2200);

        return () => clearTimeout(timer);
    }, [focusLineId, form.outputs]);

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
                        (i) => !i.rating_period_id || i.rating_period_id === periodId
                    ),
                }));
        });

        return map;
    }, [form.outputs, periodId]);

    const rollUp = (lines) => {
        if (!lines.length) return null;

        const pct = Math.round(
            lines.reduce((total, l) => total + Number(l.progress_pct ?? 0), 0) / lines.length
        );
        const status = lines.every((l) => l.progress_status === "completed")
            ? "completed"
            : lines.some((l) => l.progress_status !== "not_started" || Number(l.progress_pct ?? 0) > 0)
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

    const rows = [];

    SECTIONS.forEach((section) => {
        const outputs = bySection[section];

        if (!outputs.length && !canEdit) return;

        rows.push(
            <tr key={`section-${section}`} className="pms-sheet-section">
                <td colSpan={11}>
                    <Space>
                        <span>{SECTION_LABELS[section].toUpperCase()}</span>
                        {canEdit && (
                            <Button
                                size="small"
                                type="text"
                                icon={<PlusOutlined />}
                                onClick={() =>
                                    saveOutput.mutate({ section, title: "New MFO/PPA" })
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
                        onCommit={(title) =>
                            saveOutput.mutate({
                                id: output.id,
                                section,
                                title: title ?? "Untitled",
                                parent_output_id: output.parent_output_id,
                            })
                        }
                    />
                </div>
            );

            const outputCell = (
                <td rowSpan={span} className="pms-sheet-output" data-output-id={output.id}>
                    {numbering}
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
                                            title: "New PPA",
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
                                            description: "New success indicator",
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
                        <td colSpan={10} className="pms-sheet-empty">
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

                rows.push(
                    <tr
                        key={`line-${line.id}`}
                        data-row={`indicator-${line.id}`}
                        data-output-row={lineIndex === 0 ? `output-${output.id}` : undefined}
                    >
                        {lineIndex === 0 && outputCell}

                        <td>
                            <SheetCell
                                value={toPlainText(line.description)}
                                disabled={!canEdit}
                                placeholder="Target + measure + timeframe"
                                maxLength={NARRATIVE_MAX}
                                onCommit={(description) =>
                                    patchLine(line, output.id, {
                                        description: description ?? "Untitled",
                                    })
                                }
                            />
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
                        </td>

                        <td>
                            <SheetCell
                                type="date"
                                value={line.target_date}
                                disabled={!canEdit}
                                placeholder="No date"
                                onCommit={(target_date) => patchLine(line, output.id, { target_date })}
                            />
                            {line.delay && line.delay.state !== "no_date" && (
                                <Tag
                                    color={DELAY_META[line.delay.state]?.color}
                                    style={{ marginTop: 4, fontSize: 10 }}
                                >
                                    {DELAY_META[line.delay.state]?.label}
                                </Tag>
                            )}
                        </td>

                        <td className="pms-sheet-progress">
                            <ProgressCell
                                status={line.progress_status ?? "not_started"}
                                pct={line.progress_pct ?? 0}
                                computed
                                computedHint={
                                    computed
                                        ? "Rolled up from the commitments written against this line."
                                        : "100% once the actual accomplishment is written and a file is attached."
                                }
                            />
                        </td>

                        <td className="pms-sheet-money">
                            <SheetCell
                                type="number"
                                align="right"
                                value={line.allotted_budget}
                                disabled={!canEdit}
                                onCommit={(allotted_budget) => patchLine(line, output.id, { allotted_budget })}
                            />
                        </td>

                        <td>
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
                        </td>

                        <td className="pms-sheet-muted">
                            {accomplishment?.actual_accomplishment && (
                                <div
                                    className="pms-richtext-view"
                                    dangerouslySetInnerHTML={{
                                        __html: accomplishment.actual_accomplishment,
                                    }}
                                />
                            )}

                            {delivery.people > 0 ? (
                                <span
                                    className={
                                        delivery.recorded ? "pms-delivery-count" : "pms-cell-empty"
                                    }
                                >
                                    {delivery.recorded} of {delivery.people} recorded
                                </span>
                            ) : (
                                !accomplishment?.actual_accomplishment && (
                                    <span className="pms-cell-empty">
                                        {Number(output.delegated_outputs_count ?? 0) > 0
                                            ? "Handed out with the MFO/PPA"
                                            : "Not yet recorded"}
                                    </span>
                                )
                            )}

                            <div style={{ marginTop: 4 }}>
                                <Space size={6}>
                                    <Button
                                        size="small"
                                        type="text"
                                        icon={<ExpandOutlined />}
                                        onClick={() => setOpenLineId(line.id)}
                                    >
                                        Open
                                    </Button>
                                    <Badge
                                        count={commentCounts[line.id] ?? 0}
                                        size="small"
                                        color="#1e3a72"
                                        offset={[-4, 2]}
                                    >
                                        <Button
                                            size="small"
                                            type="text"
                                            icon={<MessageOutlined />}
                                            onClick={() => setCommentLineId(line.id)}
                                        >
                                            Comment
                                        </Button>
                                    </Badge>
                                    {delivery.files > 0 && (
                                        <Badge
                                            count={delivery.files}
                                            size="small"
                                            color="#1e3a72"
                                            offset={[4, 0]}
                                        >
                                            <PaperClipOutlined style={{ opacity: 0.6 }} />
                                        </Badge>
                                    )}
                                </Space>
                            </div>
                        </td>

                        <td className="pms-sheet-score">{rating?.q ?? ""}</td>
                        <td className="pms-sheet-score">{rating?.e ?? ""}</td>
                        <td className="pms-sheet-score">{rating?.t ?? ""}</td>
                        <td className="pms-sheet-score">
                            {rating?.a != null ? Number(rating.a).toFixed(2) : ""}
                        </td>
                    </tr>
                );
            });
        });

        // What the paper form prints under each block.
        if (outputs.length) {
            rows.push(
                <tr key={`summary-${section}`} className="pms-sheet-summary">
                    <td colSpan={3}>AVERAGE RATING — {SECTION_LABELS[section]}</td>
                    <td className="pms-sheet-progress">
                        {sectionProgress && (
                            <ProgressCell
                                status={sectionProgress.status}
                                pct={sectionProgress.pct}
                            />
                        )}
                    </td>
                    <td className="pms-sheet-money">
                        {sectionBudget > 0 ? `₱${sectionBudget.toLocaleString()}` : ""}
                    </td>
                    <td colSpan={2} />
                    <td colSpan={3} />
                    <td className="pms-sheet-score">
                        {sectionAverage != null ? Number(sectionAverage).toFixed(2) : ""}
                    </td>
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

    if (rows.length) {
        rows.push(
            <tr key="grand-total" className="pms-sheet-total">
                <td colSpan={3}>TOTAL OVERALL RATING</td>
                <td className="pms-sheet-progress">
                    {overall && <ProgressCell status={overall.status} pct={overall.pct} />}
                </td>
                <td className="pms-sheet-money">
                    {totalBudget > 0 ? `₱${totalBudget.toLocaleString()}` : ""}
                </td>
                <td colSpan={2} />
                <td colSpan={3} />
                <td className="pms-sheet-score">
                    {summary?.final_average != null
                        ? Number(summary.final_average).toFixed(2)
                        : ""}
                </td>
            </tr>,
            <tr key="adjectival" className="pms-sheet-total">
                <td colSpan={3}>ADJECTIVAL RATING</td>
                <td colSpan={7} />
                <td className="pms-sheet-score">
                    {summary?.adjectival ? (
                        <Tag color={ADJECTIVAL_COLORS[summary.adjectival]} style={{ margin: 0 }}>
                            {summary.adjectival}
                        </Tag>
                    ) : (
                        ""
                    )}
                </td>
            </tr>
        );
    }

    return (
        <>
            <LineCards form={form} periodId={periodId} onOpen={setOpenLineId} />

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
                            <th style={{ width: 140 }}>Progress</th>
                            <th style={{ width: 110 }}>Alloted Budget</th>
                            <th style={{ width: 190 }}>Individual / Accountable</th>
                            <th style={{ width: 210 }}>Actual Accomplishments</th>
                            <th style={{ width: 38 }} title="Quality">Q</th>
                            <th style={{ width: 38 }} title="Efficiency">E</th>
                            <th style={{ width: 38 }} title="Timeliness">T</th>
                            <th style={{ width: 46 }} title="Average">A</th>
                        </tr>
                    </thead>
                    <tbody>{rows}</tbody>
                </table>
            </div>

            {canEdit && (
                <Typography.Text className="pms-sheet-hint" type="secondary" style={{ fontSize: 12 }}>
                    Click any cell to edit it. Enter saves, Shift+Enter starts a new line, Escape
                    undoes.
                </Typography.Text>
            )}

            <Drawer
                title={commentLine ? `Comments — ${toPlainText(commentLine.description)}` : ""}
                placement="right"
                width={480}
                open={Boolean(commentLine)}
                onClose={() => setCommentLineId(null)}
            >
                {commentLine && (
                    <CommentThread key={commentLine.id} formId={form.id} indicatorId={commentLine.id} />
                )}
            </Drawer>

            <Drawer
                title={openLine ? toPlainText(openLine.line.description) : ""}
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
                    />
                )}
            </Drawer>

            <Modal
                title={`Assign “${assigning?.title ?? toPlainText(assigning?.description) ?? ""}”`}
                open={Boolean(assigning)}
                onCancel={() => {
                    setAssigning(null);
                    setPicked([]);
                }}
                onOk={() => assign.mutate(picked)}
                okText="Assign"
                okButtonProps={{ disabled: picked.length === 0, loading: assign.isPending }}
            >
                <Typography.Paragraph type="secondary">
                    {assigning?.kind === "output"
                        ? "Each person gets this MFO/PPA in their own IPCR and writes their own success indicators under it."
                        : "They will see this office target on their IPCR and write their own commitments against it. The college wording is not copied."}
                </Typography.Paragraph>
                <Select
                    mode="multiple"
                    style={{ width: "100%" }}
                    placeholder="Search for people"
                    value={picked}
                    onChange={setPicked}
                    optionFilterProp="label"
                    options={people.map((p) => ({
                        value: p.id,
                        label: p.position_title ? `${p.name} — ${p.position_title}` : p.name,
                    }))}
                />
            </Modal>
        </>
    );
}
