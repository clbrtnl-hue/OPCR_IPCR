import React, { useEffect, useMemo, useRef, useState } from "react";
import { Button, Card, Drawer, Empty, Select, Space, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api, { errorText } from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";
import { ADJECTIVAL_COLORS, RATING_LEGEND, ROLE_LABELS, statusMeta } from "~/utils/constants";
import { scoreOptions } from "~/utils/ratingScale";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import AccomplishmentView, { AccomplishmentCell } from "~/components/AccomplishmentView";
import { ScoreCells, ScoreFields, ScoreHeaders } from "~/components/SheetScoreCells";

function linePeriodId(line, chosen) {
    if (line?.form?.rating_period_id) return line.form.rating_period_id;

    return chosen ?? line?.form?.periods?.find((period) => period.is_active)?.id ?? line?.form?.periods?.[0]?.id ?? null;
}

function accomplishmentFor(line, periodId) {
    return (line.accomplishments ?? []).find((row) => row.rating_period_id === periodId) ?? null;
}

const TARGET_KIND_LABELS = {
    opcr: "OPCR",
    ipcr: "IPCR",
    vp: "VP",
    program_head: "Head",
};

const TARGET_KIND_ORDER = ["opcr", "ipcr", "vp", "program_head"];

function targetKindLabel(kind) {
    return TARGET_KIND_LABELS[kind] || ROLE_LABELS[kind] || "Other";
}

function targetGroupKind(item, role) {
    const kind = item.kind || "opcr";

    if (role === "qa" && kind !== "opcr") return "ipcr";

    return kind;
}

export default function TargetRating({ periodFilter = null, yearFilter, ahead = null }) {
    const { user } = useAuth();
    const organization = useOrganization();
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState(null);
    const [chosenPeriodId, setChosenPeriodId] = useState(null);
    const [scores, setScores] = useState({});
    const [saveError, setSaveError] = useState("");
    const [openLineId, setOpenLineId] = useState(null);
    const dirtyScores = useRef(new Set());
    const scoresChoices = scoreOptions(user);

    const { data: targets = [], isLoading } = useQuery({
        queryKey: ["rating-targets", user?.id, periodFilter],
        queryFn: () => {
            const query = periodFilter ? `?rating_period_id=${periodFilter}` : "";

            return api.get(`pcr-ratings/targets${query}`).then((response) => response.data);
        },
    });

    const visibleTargets = useMemo(
        () =>
            yearFilter === undefined
                ? targets
                : targets
                      .map((item) => ({
                          ...item,
                          lines: (item.lines ?? []).filter(
                              (line) => yearFilter != null && Number(line.form?.school_year_id) === Number(yearFilter)
                          ),
                      }))
                      .filter((item) => item.lines.length > 0),
        [targets, yearFilter]
    );

    useEffect(() => {
        if (selectedId && visibleTargets.some((item) => item.id === selectedId)) return;

        setSelectedId(visibleTargets[0]?.id ?? null);
        setChosenPeriodId(null);
        setOpenLineId(null);
    }, [selectedId, visibleTargets]);

    const target = visibleTargets.find((item) => item.id === selectedId) ?? null;
    const lines = target?.lines ?? [];
    const needsPeriod = lines.some((line) => !line.form.rating_period_id);
    const periodChoices = lines.find((line) => !line.form.rating_period_id)?.form.periods ?? [];
    const activeChosen = chosenPeriodId ?? periodChoices.find((period) => period.is_active)?.id ?? periodChoices[0]?.id ?? null;

    useEffect(() => {
        dirtyScores.current = new Set();
    }, [target?.id, activeChosen]);

    useEffect(() => {
        if (!target) return;

        setScores((prev) => {
            const next = {};

            lines.forEach((line) => {
                if (dirtyScores.current.has(line.id) && prev[line.id]) {
                    next[line.id] = prev[line.id];

                    return;
                }

                const periodId = linePeriodId(line, activeChosen);
                const existing = (line.ratings ?? []).find((row) => row.rating_period_id === periodId);

                next[line.id] = {
                    q: existing?.q ?? null,
                    e: existing?.e ?? null,
                    t: existing?.t ?? null,
                    remarks: existing?.remarks ?? "",
                };
            });

            return next;
        });
    }, [target, lines, activeChosen]);

    const openLine = useMemo(() => lines.find((line) => line.id === openLineId) ?? null, [lines, openLineId]);

    const update = (indicatorId, field, value) => {
        dirtyScores.current.add(Number(indicatorId));
        setScores((prev) => ({
            ...prev,
            [indicatorId]: { ...prev[indicatorId], [field]: value },
        }));
    };

    const save = useMutation({
        mutationFn: async () => {
            const groups = new Map();

            lines.forEach((line) => {
                if (!line.can_score) return;

                const periodId = linePeriodId(line, activeChosen);

                if (!periodId) return;

                const key = `${line.form.id}:${periodId}`;
                const bucket = groups.get(key) ?? { form_id: line.form.id, rating_period_id: periodId, ratings: [] };

                bucket.ratings.push({ indicator_id: line.id, ...scores[line.id] });
                groups.set(key, bucket);
            });

            await Promise.all([...groups.values()].map((payload) => api.post("pcr-ratings", payload)));
        },
        onSuccess: () => {
            setSaveError("");
            message.success("Ratings saved.");
            dirtyScores.current = new Set();
            queryClient.invalidateQueries({ queryKey: ["rating-targets"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form"] });
        },
        onError: (error) => setSaveError(errorText(error)),
    });

    const scoreableCount = lines.filter((line) => line.can_score).length;
    const bands = user?.workflow?.rating?.bands?.length ? user.workflow.rating.bands : RATING_LEGEND;
    const lineScore = (line) => ({
        canEdit: Boolean(line.can_score),
        scores,
        choices: scoresChoices,
        onChange: update,
    });

    const targetLabel = (item) => {
        const text = toPlainText(item.description) || item.output || "Untitled target";

        if ((item.kind || "opcr") === "opcr") return text;

        const college = organization.name || "Opol Community College";
        const office = item.office && item.office !== college ? `${item.office} — ` : "";

        return `${office}${text}`;
    };

    const targetOptions = useMemo(() => {
        const buckets = new Map();

        visibleTargets.forEach((item) => {
            const kind = targetGroupKind(item, user?.role);
            const bucket = buckets.get(kind) ?? [];

            bucket.push({
                value: item.id,
                label: targetLabel(item),
                title: targetLabel(item),
            });
            buckets.set(kind, bucket);
        });

        return [...buckets.keys()]
            .sort((left, right) => {
                const leftAt = TARGET_KIND_ORDER.indexOf(left);
                const rightAt = TARGET_KIND_ORDER.indexOf(right);

                return (leftAt === -1 ? TARGET_KIND_ORDER.length : leftAt) - (rightAt === -1 ? TARGET_KIND_ORDER.length : rightAt)
                    || targetKindLabel(left).localeCompare(targetKindLabel(right));
            })
            .map((kind) => ({
                label: targetKindLabel(kind),
                options: buckets.get(kind),
            }));
    }, [visibleTargets, organization.name, user?.role]);

    return (
        <>
            <div className="pms-rate-stick">
                {ahead}
                <Card className="pms-rate-picker" size="small" style={{ marginBottom: 16 }}>
                    <Space wrap className="pms-rate-pick">
                        <span>Target waiting for a rating:</span>
                        <Select
                            className="pms-rate-form"
                            classNames={{ popup: { root: "pms-rate-form-popup" } }}
                            popupMatchSelectWidth
                            loading={isLoading}
                            placeholder="Select a target"
                            value={selectedId}
                            onChange={(value) => {
                                setSelectedId(value);
                                setChosenPeriodId(null);
                                setOpenLineId(null);
                            }}
                            options={targetOptions}
                        />
                        {needsPeriod && periodChoices.length === 1 && (
                            <Tag color="blue">{periodChoices[0].label}</Tag>
                        )}
                        {needsPeriod && periodChoices.length > 1 && (
                            <Select
                                style={{ minWidth: 180 }}
                                value={activeChosen}
                                onChange={setChosenPeriodId}
                                options={periodChoices.map((period) => ({ value: period.id, label: period.label }))}
                            />
                        )}
                    </Space>
                </Card>
            </div>

            {!target ? (
                <Card>
                    <Empty
                        description={
                            visibleTargets.length === 0
                                ? "No commitments are linked to a target you can see."
                                : "Pick a target above to rate the commitments linked to it."
                        }
                    />
                </Card>
            ) : (
                <Card className="pms-rate-card">
                    {saveError && (
                        <Typography.Paragraph type="danger" style={{ marginTop: 0 }}>
                            {saveError}
                        </Typography.Paragraph>
                    )}
                    <div className="pms-form-actions" style={{ display: "flex", justifyContent: "flex-end", marginBottom: 12 }}>
                        <Button
                            loading={save.isPending}
                            disabled={scoreableCount === 0 || (needsPeriod && !activeChosen)}
                            onClick={() => save.mutate()}
                        >
                            Save progress
                        </Button>
                    </div>
                    <div className="pms-desktop-only pms-sheet-wrap">
                        <table className="pms-sheet">
                            <thead>
                                <tr>
                                    <th style={{ width: 180 }}>Commitment</th>
                                    <th style={{ width: 300 }}>
                                        Success Indicator
                                        <div className="pms-sheet-subhead">(Target + Measure)</div>
                                    </th>
                                    <th style={{ width: 110 }}>Target Date</th>
                                    <th style={{ width: 280 }}>Actual Accomplishments</th>
                                    <ScoreHeaders scoreEdit={{ canEdit: scoreableCount > 0 }} />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line) => {
                                    const periodId = linePeriodId(line, activeChosen);
                                    const accomplishment = accomplishmentFor(line, periodId);
                                    const stamp = line.target_date ? dayjs(line.target_date) : null;

                                    return (
                                        <tr key={line.id}>
                                            <td>
                                                <Tag color={line.form.type === "opcr" ? "geekblue" : "blue"}>
                                                    {line.form.type.toUpperCase()}
                                                </Tag>
                                                <Tag color={statusMeta(line.form.status).color}>
                                                    {statusMeta(line.form.status).label}
                                                </Tag>
                                                <div style={{ marginTop: 4 }}>{line.form.owner || line.form.org_unit}</div>
                                            </td>
                                            <td>
                                                <RichTextView html={line.description} />
                                            </td>
                                            <td>
                                                <span className="pms-review-date">
                                                    {stamp?.isValid() ? stamp.format("MMM D, YYYY") : "—"}
                                                </span>
                                            </td>
                                            <td className="pms-sheet-muted">
                                                <AccomplishmentCell
                                                    html={accomplishment?.actual_accomplishment}
                                                    evidenceCount={accomplishment?.attachments?.length ?? 0}
                                                    onOpen={() => setOpenLineId(line.id)}
                                                />
                                            </td>
                                            <ScoreCells line={line} scoreEdit={lineScore(line)} />
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <Space wrap className="pms-rate-legend" style={{ marginTop: 12 }}>
                        <Typography.Text type="secondary">Legend:</Typography.Text>
                        {bands.map((band) => (
                            <Tag key={band.label} color={ADJECTIVAL_COLORS[band.label]}>
                                {band.value} — {band.label}
                                {band.range ? ` (${band.range})` : ""}
                            </Tag>
                        ))}
                    </Space>
                    <Typography.Text className="pms-sheet-hint" type="secondary" style={{ fontSize: 12 }}>
                        These are the commitments linked to this target. Score the ones waiting on you. Return, send on, and finalize stay on By form.
                    </Typography.Text>
                    <div className="pms-mobile-only pms-rate-rows">
                        {lines.map((record) => (
                            <div key={record.id} className="pms-rate-row">
                                <span className="pms-rate-summary">
                                    {record.form.owner || record.form.org_unit} — {toPlainText(record.description) || "Untitled line"}
                                </span>
                                <Button type="text" className="pms-rate-open" onClick={() => setOpenLineId(record.id)}>
                                    Open
                                </Button>
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <Drawer
                className="pms-rate-drawer"
                title="Rating"
                placement="right"
                width={420}
                open={Boolean(openLine)}
                onClose={() => setOpenLineId(null)}
            >
                {openLine && (
                    <>
                        <div className="pms-rate-field">
                            <span>Commitment</span>
                            <div>
                                {openLine.form.owner || openLine.form.org_unit}
                                <Tag style={{ marginLeft: 8 }} color={statusMeta(openLine.form.status).color}>
                                    {statusMeta(openLine.form.status).label}
                                </Tag>
                            </div>
                        </div>
                        <div className="pms-rate-field">
                            <span>Success indicator</span>
                            <div>{toPlainText(openLine.description) || "Untitled line"}</div>
                        </div>
                        <div className="pms-rate-field">
                            <span>Target date</span>
                            <div>
                                <span className="pms-review-date">
                                    {openLine.target_date && dayjs(openLine.target_date).isValid()
                                        ? dayjs(openLine.target_date).format("MMM D, YYYY")
                                        : "—"}
                                </span>
                            </div>
                        </div>
                        <div className="pms-rate-field">
                            <span>Actual accomplishment</span>
                            <div>
                                <AccomplishmentView
                                    html={accomplishmentFor(openLine, linePeriodId(openLine, activeChosen))?.actual_accomplishment}
                                    attachments={[]}
                                    empty="No accomplishment yet"
                                />
                            </div>
                        </div>
                        <ScoreFields line={openLine} scoreEdit={lineScore(openLine)} />
                    </>
                )}
            </Drawer>
        </>
    );
}
