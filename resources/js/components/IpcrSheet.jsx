import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Badge,
    Button,
    Drawer,
    Input,
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
    DisconnectOutlined,
    EditOutlined,
    DeleteOutlined,
    LinkOutlined,
    PlusOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import SheetCell from "~/components/SheetCell";
import OpcrTargetCell, { isPickedTarget, TargetLink, TargetPicker } from "~/components/OpcrTargetCell";
import { AccomplishmentField } from "~/components/AccomplishmentView";
import IndicatorRow from "~/components/IndicatorRow";
import LineDrawerTitle, { formWhen } from "~/components/LineDrawerTitle";
import LineCards from "~/components/LineCards";
import UserAvatar from "~/components/UserAvatar";
import AssignDrawer from "~/components/AssignDrawer";
import { usePerson } from "~/hooks/usePerson";
import { toPlainText } from "~/components/RichTextView";
import ProgressCell from "~/components/ProgressCell";
import { ADJECTIVAL_COLORS, DELAY_META, NARRATIVE_MAX, SECTION_LABELS } from "~/utils/constants";
import { returnMark } from "~/utils/returnMarks";
import { ratingRules } from "~/utils/ratingScale";
import { ScoreCells, ScoreFields, ScoreHeaders } from "~/components/SheetScoreCells";
import AttendancePct from "~/components/AttendancePct";
import { useAuth } from "~/hooks/useAuth";

const SECTIONS = ["strategic", "core", "support"];

function keptAfterReplacement(line, indicators, form) {
    if (!line.closed_at || line.closed_reason !== "target_replaced") return true;

    const next = (indicators ?? []).find(
        (item) => item.replaces_indicator_id === line.id && !item.closed_at
    );

    if (!next) return true;
    if (next.line_approved_at) return false;
    if (next.needs_commitment_approval) return true;

    const formApproved = Boolean(form.commitment_approved_at)
        || !["draft", "returned", "commitment_review"].includes(form.status);

    return !formApproved;
}

function sheetError(error, fallback) {
    message.error(error?.response?.data?.message ?? fallback);
}

export default function IpcrSheet({
    form,
    periodId,
    canEdit,
    canEditRestarted = false,
    canRecordProgress,
    canAssign,
    canAssignHeadings,
    showScores = false,
    showProgress = true,
    scoreEdit = null,
    scoreLegend = null,
    showAccomplishments = true,
    opcrTargets = [],
    vpLinkByTarget = null,
    summary,
    focusLineId = null,
    focusOutputId = null,
    openFocusedLine = true,
    returnMarks = [],
    assignedOnly = false,
    arriveLineId = null,
    arriveOutputId = null,
    arriveOpenOutput = false,
    arriveTick = null,
    onExpand,
    onRestartUi = null,
}) {
    const { user } = useAuth();
    // The leader reviewing at their own stage (head, dean, or director) picks
    // the attendance evidence. Later stages only read the percent.
    const stageReviewerId =
        form?.review_steps?.find((step) => step.status === form?.status)?.reviewer?.id ??
        (form?.status === "head_review" ? form?.head_reviewer_id : null) ??
        (form?.status === "dean_review" ? form?.dean_reviewer_id : null);
    const canLinkAttendance =
        form?.type === "ipcr" &&
        ["head_review", "dean_review", "director_review"].includes(form?.status) &&
        Boolean(stageReviewerId) &&
        Number(stageReviewerId) === Number(user?.id) &&
        Boolean(scoreEdit?.canEdit);
    const { data: attendanceCategories = [] } = useQuery({
        queryKey: ["attendance-categories"],
        queryFn: () => api.get("attendance-categories").then((response) => response.data.categories ?? []),
        enabled: canLinkAttendance,
    });
    const scale = ratingRules(user);
    const queryClient = useQueryClient();
    const [openLineId, setOpenLineId] = useState(null);
    const [returning, setReturning] = useState(null);
    const focusedLine = useRef(null);
    const focusedOutput = useRef(null);
    const [justAdded, setJustAdded] = useState(null);
    const [pickingLineId, setPickingLineId] = useState(null);
    const [assigning, setAssigning] = useState(null);
    const { openPerson } = usePerson();

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });

    const sendRestarts = useMutation({
        mutationFn: () => api.post(`pcr-forms/${form.id}/restart-commitments`),
        onSuccess: () => {
            message.success("New commitments sent for approval.");
            refresh();
        },
        onError: (error) => sheetError(error, "Could not send the new commitments."),
    });

    const decideRestarts = useMutation({
        mutationFn: ({ approve, note }) =>
            api.post(`pcr-forms/${form.id}/restart-commitments/decide`, {
                approve,
                ...(note ? { note } : {}),
            }),
        onSuccess: (_data, { approve }) => {
            message.success(approve ? "New commitments approved." : "Sent back to be rewritten.");
            setReturning(null);
            refresh();
        },
        onError: (error) => sheetError(error, "Could not decide those commitments."),
    });

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: Boolean(assigning),
        staleTime: 5 * 60 * 1000,
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
        onError: (error) => sheetError(error, "Could not assign that."),
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
                                            attendance_category_id: saved.attendance_category_id ?? null,
                                        }
                                      : output
                              ),
                          }
                        : current
                );
            }

            if (!values.id) {
                message.success("MFO/PPA added — name it and add its success indicators.");
                setJustAdded({ kind: "output", id: data.output.id });
            }

            refresh();
        },
        onError: (error) => sheetError(error, "Could not save that row."),
    });

    const swapOrder = useMutation({
        mutationFn: async ({ kind, rows, index, offset }) => {
            const a = rows[index];
            const b = rows[index + offset];

            const url = kind === "output" ? "pcr-outputs" : "pcr-indicators";
            const base = (row) =>
                kind === "output"
                    ? { form_id: form.id, section: row.section, title: row.title }
                    : {
                          output_id: row.output_id,
                          description: row.description,
                          target_date: row.target_date,
                          rating_period_id: row.rating_period_id,
                          parent_indicator_id: row.parent_indicator_id,
                      };

            await api.post(url, { ...base(a), id: a.id, sort_order: b.sort_order });
            await api.post(url, { ...base(b), id: b.id, sort_order: a.sort_order });
        },
        onSuccess: refresh,
        onError: (error) => sheetError(error, "Could not move that row."),
    });

    const removeOutput = useMutation({
        mutationFn: (id) => api.delete(`pcr-outputs/${id}`),
        onSuccess: () => {
            message.success("Row removed.");
            refresh();
        },
        onError: (error) => sheetError(error, "Could not remove that row."),
    });

    const saveIndicator = useMutation({
        mutationFn: (values) => api.post("pcr-indicators", { rating_period_id: periodId, ...values }),
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
        onError: (error) => sheetError(error, "Could not save that line."),
    });

    const patchLine = (line, outputId, changes) =>
        saveIndicator.mutate({
            id: line.id,
            output_id: outputId,
            description: line.description,
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
        onError: (error) => sheetError(error, "Could not remove that line."),
    });

    const withdraw = useMutation({
        mutationFn: (childId) => api.delete(`pcr-assignments/${childId}`),
        onSuccess: () => {
            message.success("Assignment withdrawn.");
            refresh();
        },
        onError: (error) => sheetError(error, "Could not withdraw that assignment."),
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

        if (!row) return;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        setJustAdded(null);

        setTimeout(() => row.querySelector(".pms-cell")?.click(), 400);
        setTimeout(() => row.classList.remove("is-new"), 2200);
    }, [justAdded, form.outputs]);

    useEffect(() => {
        if (!arriveLineId) return;

        const row = document.querySelector(`[data-row="indicator-${arriveLineId}"]`);

        if (!row || row.getClientRects().length === 0) return;

        const outputNodes = arriveOutputId
            ? [...document.querySelectorAll(`[data-output-id="${arriveOutputId}"]`)]
            : [];
        const outputCell =
            outputNodes.find((node) => node.offsetParent !== null) ?? outputNodes[0] ?? null;
        const outputRow = outputCell?.closest("tr") ?? outputCell;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        outputRow?.classList.add("is-new");

        const editCell = arriveOpenOutput
            ? outputCell?.querySelector(".pms-cell") ?? row.querySelector(".pms-cell")
            : row.querySelector(".pms-cell");
        const click = setTimeout(() => editCell?.click(), 400);
        const clear = setTimeout(() => {
            row.classList.remove("is-new");
            outputRow?.classList.remove("is-new");
        }, 2200);

        return () => {
            clearTimeout(click);
            clearTimeout(clear);
        };
    }, [arriveLineId, arriveOutputId, arriveOpenOutput, arriveTick, form.outputs]);

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
                            keptAfterReplacement(i, o.indicators, form)
                    ),
                }));
        });

        return map;
    }, [form, periodId]);

    const openLine = useMemo(() => {
        if (!openLineId) return null;

        for (const output of form.outputs ?? []) {
            const found = (output.indicators ?? []).find((i) => i.id === openLineId);

            if (found) return found;
        }

        return null;
    }, [openLineId, form.outputs]);

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

    const anyDelegated = (form.outputs ?? []).some((o) =>
        (o.indicators ?? []).some((i) => accountableFor(i).length > 0)
    );
    const showAccountable = canAssign || anyDelegated;
    const columnCount =
        (showAccountable ? 5 : 4) -
        (showProgress ? 0 : 1) +
        (showAccomplishments ? 1 : 0) +
        (showScores ? scale.dimensions.length + 1 + (scoreEdit ? 1 : 0) : 0);
    const tailSpan = columnCount - (scoreEdit ? 5 : 4);

    const rows = [];

    SECTIONS.forEach((section) => {
        const outputs = bySection[section];
        const addable = canEdit && section !== "strategic";

        if (!outputs.length && !addable) return;

        rows.push(
            <tr key={`section-${section}`} className="pms-sheet-section">
                <td colSpan={columnCount}>
                    <Space>
                        <span>{SECTION_LABELS[section].toUpperCase()}</span>
                        {addable && (
                            <Button
                                size="small"
                                type="text"
                                icon={<PlusOutlined />}
                                onClick={() => saveOutput.mutate({ section, title: "" })}
                            >
                                Add MFO/PPA
                            </Button>
                        )}
                    </Space>
                </td>
            </tr>
        );

        const sectionAverage = summary?.[`${section}_average`];

        outputs.forEach((output, outputIndex) => {
            const number = outputIndex + 1;
            const isOwner = Number(form.user_id) === Number(user?.id);
            const visible = isOwner
                ? output.lines
                : output.lines.filter((line) => {
                      if (!line.closed_at || line.closed_reason !== "target_replaced") return true;

                      const stillOpen = (output.indicators ?? []).some(
                          (item) =>
                              item.replaces_indicator_id === line.id &&
                              !item.closed_at &&
                              !item.line_approved_at
                      );

                      return !stillOpen;
                  });
            const lines = [...visible].sort((a, b) => {
                const gap = Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0);

                if (gap !== 0) return gap;
                if (a.id === b.replaces_indicator_id) return -1;
                if (b.id === a.replaces_indicator_id) return 1;

                return Number(a.id) - Number(b.id);
            });
            const span = Math.max(lines.length, 1);
            const fromCollege = Boolean(output.parent_output_id && output.assigned_by);
            const handed = opcrTargets.filter((t) => t.assigned);
            const targets =
                section === "support"
                    ? handed.filter((t) => t.section === "support")
                    : fromCollege
                      ? handed.filter((t) => t.output_id === output.parent_output_id)
                      : handed.filter((t) => t.section !== "support");

            const outputCell = (
                <td rowSpan={span} className="pms-sheet-output" data-output-id={output.id}>
                    <div className="pms-sheet-number">
                        <span>{number}.</span>
                        <SheetCell
                            value={output.title}
                            disabled={!canEdit}
                            placeholder="Name this MFO/PPA"
                            maxLength={NARRATIVE_MAX}
                            mark={returnMark(returnMarks, { outputId: output.id, field: "title" })}
                            onCommit={(title) =>
                                saveOutput.mutate({ id: output.id, section, title: title ?? "" })
                            }
                        />
                    </div>
                    {section === "support" && canLinkAttendance && (
                        <Select
                            className="pms-attendance-category"
                            size="small"
                            allowClear
                            placeholder="Attendance evidence"
                            value={output.attendance_category_id ?? undefined}
                            options={attendanceCategories.map((item) => ({
                                value: item.id,
                                label: item.source === "daily" ? `${item.name} · daily` : item.name,
                            }))}
                            onChange={(id) => saveOutput.mutate({
                                id: output.id,
                                section,
                                attendance_category_id: id ?? null,
                            })}
                            popupMatchSelectWidth={false}
                        />
                    )}
                    {showScores && <AttendancePct form={form} output={output} periodId={periodId} />}
                    {(canEdit || canAssignHeadings) && (
                        <Space size={2} className="pms-sheet-rowtools">
                            {canEdit && (
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
                            )}
                            {canAssignHeadings && section !== "support" && (
                                <Tooltip title="Assign this whole MFO/PPA">
                                    <Button
                                        size="small"
                                        type="text"
                                        icon={<UserAddOutlined />}
                                        onClick={() => setAssigning({ kind: "output", ...output })}
                                    />
                                </Tooltip>
                            )}
                            {canEdit && (
                                <>
                                    <Tooltip title="Move up">
                                        <Button
                                            size="small"
                                            type="text"
                                            disabled={outputIndex === 0}
                                            icon={<ArrowUpOutlined />}
                                            onClick={() =>
                                                swapOrder.mutate({
                                                    kind: "output",
                                                    rows: outputs,
                                                    index: outputIndex,
                                                    offset: -1,
                                                })
                                            }
                                        />
                                    </Tooltip>
                                    <Tooltip title="Move down">
                                        <Button
                                            size="small"
                                            type="text"
                                            disabled={outputIndex === outputs.length - 1}
                                            icon={<ArrowDownOutlined />}
                                            onClick={() =>
                                                swapOrder.mutate({
                                                    kind: "output",
                                                    rows: outputs,
                                                    index: outputIndex,
                                                    offset: 1,
                                                })
                                            }
                                        />
                                    </Tooltip>
                                    <Popconfirm
                                        title="Remove this MFO/PPA and its lines?"
                                        description={
                                            fromCollege
                                                ? "This was handed down from the OPCR. Removing it takes it off your IPCR."
                                                : undefined
                                        }
                                        onConfirm={() => removeOutput.mutate(output.id)}
                                    >
                                        <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                                    </Popconfirm>
                                </>
                            )}
                        </Space>
                    )}
                </td>
            );

            if (!lines.length) {
                rows.push(
                    <tr key={`output-${output.id}-empty`} data-output-row={`output-${output.id}`}>
                        {outputCell}
                        <td colSpan={columnCount - 1} className="pms-sheet-empty">
                            {canEdit ? "No success indicator yet — use + to add one." : "—"}
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
                const lineCanRecord =
                    Boolean(canRecordProgress) &&
                    !line.closed_at &&
                    !(line.needs_commitment_approval && !line.line_approved_at);
                const handedTo = accountableFor(line);
                const restarted =
                    Boolean(line.needs_commitment_approval) && !line.line_approved_at && !line.closed_at;
                const lineEditable =
                    !line.closed_at &&
                    (canEdit || (canEditRestarted && restarted && !line.line_submitted_at));
                const markNew =
                    restarted ||
                    (canEdit && !line.closed_at && line.replaces_indicator_id && !line.line_approved_at);
                const replaced = line.replaces_indicator_id
                    ? (output.indicators ?? []).find((item) => item.id === line.replaces_indicator_id)
                    : null;

                rows.push(
                    <tr key={`line-${line.id}`} data-row={`indicator-${line.id}`}>
                        {lineIndex === 0 && outputCell}

                        <td
                            className={
                                (isOwner ? restarted && !line.line_submitted_at : markNew)
                                    ? "pms-flag"
                                    : undefined
                            }
                        >
                            <div className="pms-sheet-number">
                                <span>
                                    {number}.{lineIndex + 1}.
                                </span>
                                <OpcrTargetCell
                                    line={line}
                                    linkLabel={vpLinkByTarget?.[String(line.parent_indicator_id)]}
                                >
                                    <SheetCell
                                        value={toPlainText(line.description)}
                                        disabled={!lineEditable}
                                        placeholder="Target + measure + timeframe"
                                        maxLength={NARRATIVE_MAX}
                                        mark={returnMark(returnMarks, {
                                            indicatorId: line.id,
                                            field: "description",
                                        })}
                                        onCommit={(description) =>
                                            patchLine(line, output.id, {
                                                description: description ?? "",
                                            })
                                        }
                                    />
                                </OpcrTargetCell>
                            </div>
                            {!isOwner && markNew && replaced && (
                                <Typography.Text
                                    type="secondary"
                                    style={{ display: "block", marginTop: 4, fontSize: 12, whiteSpace: "pre-wrap" }}
                                >
                                    Previous: {toPlainText(replaced.description) || "None"}
                                </Typography.Text>
                            )}
                            {line.replaces_indicator_id && !line.closed_at && !restarted && (
                                <Tag style={{ marginTop: 4 }}>New commitment for the replaced target</Tag>
                            )}
                            {line.closed_at && (
                                <Tag color="default" style={{ marginTop: 4 }}>
                                    Target replaced — not rated
                                </Tag>
                            )}
                            {restarted && !line.line_submitted_at && (
                                <Tag color="gold" style={{ marginTop: 4 }}>
                                    Write a new commitment
                                </Tag>
                            )}
                            {restarted && line.line_submitted_at && (
                                <Tag color="processing" style={{ marginTop: 4 }}>
                                    Waiting for approval
                                </Tag>
                            )}
                            {canEdit && !line.closed_at && (
                                <Space size={2} className="pms-sheet-rowtools">
                                    {targets.length > 0 &&
                                        !line.assigned_by &&
                                        (!assignedOnly || isPickedTarget(line)) && (
                                        <>
                                            {isPickedTarget(line) ? (
                                                <>
                                                    <TargetPicker
                                                        open={pickingLineId === line.id}
                                                        targets={targets}
                                                        onOpenChange={(next) =>
                                                            setPickingLineId(next ? line.id : null)
                                                        }
                                                        onPick={(parentId) => {
                                                            patchLine(line, output.id, {
                                                                parent_indicator_id: parentId,
                                                            });
                                                            setPickingLineId(null);
                                                        }}
                                                    >
                                                        <TargetLink title="Change office target" icon={<EditOutlined />} />
                                                    </TargetPicker>
                                                    <Tooltip title="Unlink and type your own">
                                                        <Button
                                                            size="small"
                                                            type="text"
                                                            icon={<DisconnectOutlined />}
                                                            onClick={() =>
                                                                patchLine(line, output.id, {
                                                                    parent_indicator_id: null,
                                                                })
                                                            }
                                                        />
                                                    </Tooltip>
                                                </>
                                            ) : (
                                                <TargetPicker
                                                    open={pickingLineId === line.id}
                                                    targets={targets}
                                                    onOpenChange={(next) =>
                                                        setPickingLineId(next ? line.id : null)
                                                    }
                                                    onPick={(parentId) => {
                                                        patchLine(line, output.id, {
                                                            parent_indicator_id: parentId,
                                                        });
                                                        setPickingLineId(null);
                                                    }}
                                                >
                                                    <TargetLink
                                                        title="Choose the target this line answers"
                                                        className="pms-target-pick"
                                                        icon={<LinkOutlined />}
                                                    />
                                                </TargetPicker>
                                            )}
                                            <span className="pms-sheet-rowtools-divider" />
                                        </>
                                    )}
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
                                disabled={!lineEditable}
                                placeholder="No date"
                                mark={returnMark(returnMarks, {
                                    indicatorId: line.id,
                                    field: "target_date",
                                })}
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

                        {showProgress && (
                        <td className="pms-sheet-progress">
                            <ProgressCell
                                status={line.progress_status ?? "not_started"}
                                pct={line.progress_pct ?? 0}
                                computed
                                computedHint={
                                    (line.children ?? []).length > 0
                                        ? "Rolled up from the commitments written against this line."
                                        : "The narrative counts as 30%. Two files count as 70%, so one file is 35%. A narrative and both files reach 100%."
                                }
                            />
                        </td>
                        )}

                        {showAccountable && (
                            <td>
                                <Space size={4} wrap>
                                    {handedTo.map((person) => {
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
                                                    description="Only possible while they have not worked on it."
                                                    onConfirm={() => withdraw.mutate(withdrawId)}
                                                >
                                                    <CloseOutlined className="pms-person-remove" />
                                                </Popconfirm>
                                            )}
                                        </span>
                                        );
                                    })}
                                    {handedTo.length === 0 && (
                                        <Tag style={{ margin: 0 }}>Mine</Tag>
                                    )}
                                    {canAssign && (
                                        <Tooltip
                                            title={
                                                handedTo.length
                                                    ? "Hand this line to someone else as well"
                                                    : "Hand this line to a member of staff"
                                            }
                                        >
                                            <Button
                                                size="small"
                                                type={handedTo.length ? "text" : "dashed"}
                                                icon={<UserAddOutlined />}
                                                onClick={() => setAssigning({ kind: "indicator", ...line })}
                                            />
                                        </Tooltip>
                                    )}
                                </Space>
                            </td>
                        )}

                        {showAccomplishments && (
                            <td className="pms-sheet-muted">
                                <AccomplishmentField
                                    formId={form.id}
                                    indicatorId={line.id}
                                    periodId={periodId}
                                    html={accomplishment?.actual_accomplishment}
                                    attachments={accomplishment?.attachments}
                                    canRecord={lineCanRecord}
                                    mark={returnMark(returnMarks, {
                                        indicatorId: line.id,
                                        field: "actual_accomplishment",
                                    })}
                                    evidenceMark={returnMark(returnMarks, {
                                        indicatorId: line.id,
                                        field: "evidence",
                                    })}
                                    empty={lineCanRecord ? "Click to record" : "Recorded at period end"}
                                    placeholder="What was actually delivered against this target?"
                                    onOpen={() => setOpenLineId(line.id)}
                                />
                            </td>
                        )}

                        {showScores && <ScoreCells line={line} saved={rating} scoreEdit={scoreEdit} />}
                    </tr>
                );
            });
        });

        if (outputs.length && showScores) {
            rows.push(
                <tr key={`summary-${section}`} className="pms-sheet-summary">
                    <td colSpan={3}>AVERAGE RATING — {SECTION_LABELS[section]}</td>
                    <td colSpan={tailSpan} />
                    <td className="pms-sheet-score">
                        {sectionAverage != null ? Number(sectionAverage).toFixed(2) : ""}
                    </td>
                    {scoreEdit && <td />}
                </tr>
            );
        }
    });

    if (rows.length && showScores) {
        rows.push(
            <tr key="grand-total" className="pms-sheet-total">
                <td colSpan={3}>TOTAL OVERALL RATING</td>
                <td colSpan={tailSpan} />
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

    const restartLines = (form.outputs ?? []).flatMap((output) =>
        (output.indicators ?? []).filter(
            (line) => line.needs_commitment_approval && !line.line_approved_at && !line.closed_at
        )
    );
    const toSend = restartLines.filter((line) => !line.line_submitted_at);
    const waitingForMe = restartLines.filter(
        (line) => line.line_submitted_at && Number(line.line_approver_id) === Number(user?.id)
    );
    const ownsForm = Number(form.user_id) === Number(user?.id);
    const readyToSend = toSend.length > 0 && toSend.every((line) => toPlainText(line.description));
    const restartRun = useRef({});

    restartRun.current = {
        send: () => sendRestarts.mutate(),
        approve: () => decideRestarts.mutate({ approve: true }),
        reject: () => setReturning({ note: "" }),
    };

    useEffect(() => {
        if (!onRestartUi) return undefined;

        return () => onRestartUi(null);
    }, [onRestartUi]);

    useEffect(() => {
        if (!onRestartUi) return;

        onRestartUi({
            showSend: ownsForm && toSend.length > 0,
            count: toSend.length,
            ready: readyToSend,
            sendPending: sendRestarts.isPending,
            showDecide: waitingForMe.length > 0,
            waiting: waitingForMe.length,
            decidePending: decideRestarts.isPending,
            run: (name) => restartRun.current[name]?.(),
        });
    }, [
        onRestartUi,
        ownsForm,
        toSend.length,
        readyToSend,
        sendRestarts.isPending,
        waitingForMe.length,
        decideRestarts.isPending,
    ]);

    return (
        <>
            {!onRestartUi && ((ownsForm && toSend.length > 0) || waitingForMe.length > 0) ? (
                <Space style={{ marginBottom: 12 }} wrap>
                    {ownsForm && toSend.length > 0 && (
                        <>
                            <Button
                                type="primary"
                                disabled={!readyToSend}
                                loading={sendRestarts.isPending}
                                onClick={() => sendRestarts.mutate()}
                            >
                                Send for approval ({toSend.length})
                            </Button>
                            {!readyToSend && (
                                <Typography.Text type="secondary">
                                    Write every new commitment first.
                                </Typography.Text>
                            )}
                        </>
                    )}
                    {waitingForMe.length > 0 && (
                        <>
                            <Button
                                type="primary"
                                loading={decideRestarts.isPending}
                                onClick={() => decideRestarts.mutate({ approve: true })}
                            >
                                Approve ({waitingForMe.length})
                            </Button>
                            <Button
                                loading={decideRestarts.isPending}
                                onClick={() => setReturning({ note: "" })}
                            >
                                Send back
                            </Button>
                        </>
                    )}
                </Space>
            ) : null}
            <LineCards
                form={form}
                periodId={periodId}
                showScores={showScores}
                showAccomplishments={showAccomplishments}
                vpLinkByTarget={vpLinkByTarget}
                onOpen={setOpenLineId}
                onEnter={(canEdit || (canEditRestarted && toSend.length > 0)) && onExpand ? () => onExpand() : undefined}
                addSections={canEdit ? ["core", "support"] : []}
                onAdd={(section) => {
                    saveOutput.mutate({ section, title: "" });
                    onExpand?.();
                }}
            />

            <div className="pms-sheet-wrap">
                <table className="pms-sheet">
                    <thead>
                        <tr>
                            <th style={{ width: 180 }}>MFO/PPA</th>
                            <th style={{ width: 300 }}>
                                Success Indicator
                                <div className="pms-sheet-subhead">(Target + Measure)</div>
                            </th>
                            <th style={{ width: 110 }}>Target Date</th>
                            {showProgress && <th style={{ width: 140 }}>Progress</th>}
                            {showAccountable && <th style={{ width: 180 }}>Accountable</th>}
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
                            name={toPlainText(openLine.description)}
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
                    <IndicatorRow
                        key={openLine.id}
                        indicator={openLine}
                        formId={form.id}
                        periodId={periodId}
                        canEditCommitment={canEdit && !openLine.closed_at}
                        canEditLine={
                            !openLine.closed_at &&
                            (canEdit ||
                                (canEditRestarted &&
                                    Boolean(openLine.needs_commitment_approval) &&
                                    !openLine.line_approved_at &&
                                    !openLine.line_submitted_at))
                        }
                        canRecordProgress={
                            canRecordProgress &&
                            !openLine.closed_at &&
                            !(openLine.needs_commitment_approval && !openLine.line_approved_at)
                        }
                        canAssign={canAssign}
                        showAccomplishments={showAccomplishments}
                        isOpcr={false}
                        linkLabel={vpLinkByTarget?.[String(openLine.parent_indicator_id)]}
                        scoreEdit={scoreEdit}
                        form={form}
                        attendanceOutput={(form.outputs ?? []).find((output) =>
                            (output.indicators ?? []).some((item) => item.id === openLine.id)
                        )}
                        remarksOpen={openLine.id === focusLineId}
                    />
                )}
            </Drawer>

            <AssignDrawer
                open={Boolean(assigning)}
                kind={assigning?.kind}
                title={assigning?.title ?? toPlainText(assigning?.description)}
                people={people}
                loading={assign.isPending}
                onClose={() => setAssigning(null)}
                onAssign={(ids) => assign.mutate(ids)}
            />

            <Modal
                title="Send these commitments back"
                open={Boolean(returning)}
                okText="Send back"
                confirmLoading={decideRestarts.isPending}
                okButtonProps={{ disabled: !returning?.note?.trim() }}
                onOk={() => decideRestarts.mutate({ approve: false, note: returning.note })}
                onCancel={() => setReturning(null)}
                destroyOnClose
            >
                <Input.TextArea
                    rows={3}
                    value={returning?.note ?? ""}
                    placeholder="What needs to be rewritten"
                    onChange={(event) =>
                        setReturning((current) => ({ ...current, note: event.target.value }))
                    }
                />
            </Modal>
        </>
    );
}
