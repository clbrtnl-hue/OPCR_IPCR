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
    DisconnectOutlined,
    EditOutlined,
    DeleteOutlined,
    ExpandOutlined,
    LinkOutlined,
    PaperClipOutlined,
    PlusOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import SheetCell from "~/components/SheetCell";
import OpcrTargetCell, { isPickedTarget } from "~/components/OpcrTargetCell";
import { AccomplishmentCell } from "~/components/AccomplishmentView";
import IndicatorRow from "~/components/IndicatorRow";
import UserAvatar from "~/components/UserAvatar";
import { usePerson } from "~/hooks/usePerson";
import { toPlainText } from "~/components/RichTextView";
import ProgressCell from "~/components/ProgressCell";
import { ADJECTIVAL_COLORS, DELAY_META, NARRATIVE_MAX, RATING_LEGEND, SECTION_LABELS } from "~/utils/constants";

const SECTIONS = ["strategic", "core", "support"];

const SCORE_OPTIONS = RATING_LEGEND.map((band) => ({
    value: band.value,
    label: String(band.value),
}));

function averageOf(row) {
    const given = [row?.q, row?.e, row?.t].filter((value) => value != null);

    if (given.length === 0) return null;

    return (given.reduce((sum, value) => sum + value, 0) / given.length).toFixed(2);
}

export default function IpcrSheet({
    form,
    periodId,
    canEdit,
    canRecordProgress,
    canAssign,
    canAssignHeadings,
    canScore = false,
    opcrTargets = [],
    summary,
    onAddOutput,
    focusLineId = null,
}) {
    const queryClient = useQueryClient();
    const [openLineId, setOpenLineId] = useState(null);
    const focusedLine = useRef(null);
    const [justAdded, setJustAdded] = useState(null);
    const [pickingLineId, setPickingLineId] = useState(null);
    const [assigning, setAssigning] = useState(null);
    const [picked, setPicked] = useState([]);
    const { openPerson } = usePerson();
    const [draftScores, setDraftScores] = useState({});
    const savingScores = useRef(new Set());

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });

    useEffect(() => {
        const next = {};

        form.outputs?.forEach((output) => {
            output.indicators?.forEach((line) => {
                if (savingScores.current.has(line.id)) return;

                const rating = (line.ratings ?? []).find((row) => row.rating_period_id === periodId);
                next[line.id] = {
                    q: rating?.q ?? null,
                    e: rating?.e ?? null,
                    t: rating?.t ?? null,
                    remarks: rating?.remarks ?? "",
                };
            });
        });

        setDraftScores((prev) => {
            savingScores.current.forEach((lineId) => {
                if (prev[lineId]) next[lineId] = prev[lineId];
            });

            return next;
        });
    }, [form, periodId]);

    const saveScore = (lineId, dimension, value) => {
        const current = draftScores[lineId] ?? { q: null, e: null, t: null, remarks: "" };
        const next = { ...current, [dimension]: value ?? null };

        savingScores.current.add(lineId);
        setDraftScores((prev) => ({ ...prev, [lineId]: next }));

        if (!canScore || !periodId) {
            savingScores.current.delete(lineId);

            return;
        }

        api.post("pcr-ratings", {
            form_id: form.id,
            rating_period_id: periodId,
            ratings: [
                {
                    indicator_id: lineId,
                    q: next.q,
                    e: next.e,
                    t: next.t,
                    remarks: next.remarks,
                },
            ],
        })
            .then(() => refresh())
            .catch((error) => {
                message.error(error.response?.data?.message ?? "The score could not be saved.");
            })
            .finally(() => savingScores.current.delete(lineId));
    };

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
                { user_ids: ids }
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

    const saveOutput = useMutation({
        mutationFn: (values) => api.post("pcr-outputs", { form_id: form.id, ...values }),
        onSuccess: refresh,
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
    });

    const removeOutput = useMutation({
        mutationFn: (id) => api.delete(`pcr-outputs/${id}`),
        onSuccess: () => {
            message.success("Row removed.");
            refresh();
        },
    });

    const saveIndicator = useMutation({
        mutationFn: (values) => api.post("pcr-indicators", { rating_period_id: periodId, ...values }),
        onSuccess: ({ data }, values) => {
            if (!values.id) {
                message.success("Success indicator added.");
                setJustAdded({ kind: "indicator", id: data.indicator.id });
            }

            refresh();
        },
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
    });

    const withdraw = useMutation({
        mutationFn: (childId) => api.delete(`pcr-assignments/${childId}`),
        onSuccess: () => {
            message.success("Assignment withdrawn.");
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
        setOpenLineId(focusLineId);

        const row = document.querySelector(`[data-row="indicator-${focusLineId}"]`);

        if (!row) return;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        const timer = setTimeout(() => row.classList.remove("is-new"), 2200);

        return () => clearTimeout(timer);
    }, [focusLineId, form.outputs]);

    useEffect(() => {
        if (!justAdded) return;

        const row = document.querySelector(`[data-row="indicator-${justAdded.id}"]`);

        if (!row) return;

        row.scrollIntoView({ behavior: "smooth", block: "center" });
        row.classList.add("is-new");
        setJustAdded(null);

        setTimeout(() => row.querySelector(".pms-cell")?.click(), 400);
        setTimeout(() => row.classList.remove("is-new"), 2200);
    }, [justAdded, form.outputs]);

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
    const columnCount = showAccountable ? 10 : 9;

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
                                onClick={() => onAddOutput?.(section)}
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
            const lines = output.lines;
            const span = Math.max(lines.length, 1);
            const fromCollege = Boolean(output.parent_output_id && output.assigned_by);
            const handed = opcrTargets.filter((t) => t.assigned);
            const targets =
                section === "support"
                    ? handed.filter((t) => t.section === "support")
                    : fromCollege
                      ? opcrTargets.filter(
                            (t) =>
                                t.output_id === output.parent_output_id ||
                                (t.assigned && t.section !== "support")
                        )
                      : opcrTargets;

            const outputCell = (
                <td rowSpan={span} className="pms-sheet-output" data-output-id={output.id}>
                    <div className="pms-sheet-number">
                        <span>{number}.</span>
                        <SheetCell
                            value={output.title}
                            disabled={!canEdit}
                            placeholder="Name this MFO/PPA"
                            maxLength={NARRATIVE_MAX}
                            onCommit={(title) =>
                                saveOutput.mutate({ id: output.id, section, title: title ?? "Untitled" })
                            }
                        />
                    </div>
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
                                                description: "New success indicator",
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
                    <tr key={`output-${output.id}-empty`}>
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
                const evidenceCount = accomplishment?.attachments?.length ?? 0;
                const handedTo = accountableFor(line);

                rows.push(
                    <tr key={`line-${line.id}`} data-row={`indicator-${line.id}`}>
                        {lineIndex === 0 && outputCell}

                        <td>
                            <div className="pms-sheet-number">
                                <span>
                                    {number}.{lineIndex + 1}.
                                </span>
                                <OpcrTargetCell
                                    line={line}
                                    targets={targets}
                                    picking={pickingLineId === line.id}
                                    onClose={() => setPickingLineId(null)}
                                    onPick={(parentId) =>
                                        patchLine(line, output.id, { parent_indicator_id: parentId })
                                    }
                                >
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
                                </OpcrTargetCell>
                            </div>
                            {canEdit && (
                                <Space size={2} className="pms-sheet-rowtools">
                                    {targets.length > 0 && !line.assigned_by && (
                                        <>
                                            {isPickedTarget(line) ? (
                                                <>
                                                    <Tooltip title="Change office target">
                                                        <Button
                                                            size="small"
                                                            type="text"
                                                            icon={<EditOutlined />}
                                                            onClick={() => setPickingLineId(line.id)}
                                                        />
                                                    </Tooltip>
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
                                                <Tooltip title="Choose the target this line answers">
                                                    <Button
                                                        size="small"
                                                        type="text"
                                                        className="pms-target-pick"
                                                        icon={<LinkOutlined />}
                                                        onClick={() => setPickingLineId(line.id)}
                                                    />
                                                </Tooltip>
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
                                    (line.children ?? []).length > 0
                                        ? "Rolled up from the commitments written against this line."
                                        : "100% once the actual accomplishment is written and a file is attached."
                                }
                            />
                        </td>

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

                        <td className="pms-sheet-muted">
                            <AccomplishmentCell
                                html={accomplishment?.actual_accomplishment}
                                evidenceCount={evidenceCount}
                                empty={
                                    canRecordProgress
                                        ? "Open the row to record it"
                                        : "Recorded at period end"
                                }
                                label={canRecordProgress ? "Record" : "Open"}
                                onOpen={() => setOpenLineId(line.id)}
                            />
                        </td>

                        {["q", "e", "t"].map((dimension) => (
                            <td key={dimension} className="pms-sheet-score">
                                {canScore ? (
                                    <Select
                                        size="small"
                                        style={{ width: 64 }}
                                        allowClear
                                        value={draftScores[line.id]?.[dimension] ?? undefined}
                                        options={SCORE_OPTIONS}
                                        onChange={(value) => saveScore(line.id, dimension, value)}
                                    />
                                ) : (
                                    rating?.[dimension] ?? ""
                                )}
                            </td>
                        ))}
                        <td className="pms-sheet-score">
                            {canScore
                                ? averageOf(draftScores[line.id]) ?? ""
                                : rating?.a != null
                                  ? Number(rating.a).toFixed(2)
                                  : ""}
                        </td>
                    </tr>
                );
            });
        });

        if (outputs.length) {
            rows.push(
                <tr key={`summary-${section}`} className="pms-sheet-summary">
                    <td colSpan={3}>AVERAGE RATING — {SECTION_LABELS[section]}</td>
                    <td colSpan={columnCount - 4} />
                    <td className="pms-sheet-score">
                        {sectionAverage != null ? Number(sectionAverage).toFixed(2) : ""}
                    </td>
                </tr>
            );
        }
    });

    if (rows.length) {
        rows.push(
            <tr key="grand-total" className="pms-sheet-total">
                <td colSpan={3}>TOTAL OVERALL RATING</td>
                <td colSpan={columnCount - 4} />
                <td className="pms-sheet-score">
                    {summary?.final_average != null
                        ? Number(summary.final_average).toFixed(2)
                        : ""}
                </td>
            </tr>,
            <tr key="adjectival" className="pms-sheet-total">
                <td colSpan={3}>ADJECTIVAL RATING</td>
                <td colSpan={columnCount - 4} />
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
                            <th style={{ width: 140 }}>Progress</th>
                            {showAccountable && <th style={{ width: 180 }}>Accountable</th>}
                            <th style={{ width: 240 }}>Actual Accomplishments</th>
                            <th style={{ width: canScore ? 76 : 38 }} title="Quality">Q</th>
                            <th style={{ width: canScore ? 76 : 38 }} title="Efficiency">E</th>
                            <th style={{ width: canScore ? 76 : 38 }} title="Timeliness">T</th>
                            <th style={{ width: 46 }} title="Average">A</th>
                        </tr>
                    </thead>
                    <tbody>{rows}</tbody>
                </table>
            </div>

            {canEdit && (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Click any cell to edit it. Enter saves, Shift+Enter starts a new line, Escape
                    undoes.
                </Typography.Text>
            )}

            <Drawer
                title={openLine ? toPlainText(openLine.description) : ""}
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
                        canEditCommitment={canEdit}
                        canRecordProgress={canRecordProgress}
                        canAssign={canAssign}
                        isOpcr={false}
                        remarksOpen={openLine.id === focusLineId}
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
                        : "They will see this target on their IPCR and write their own commitments against it. The wording is not copied."}
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
