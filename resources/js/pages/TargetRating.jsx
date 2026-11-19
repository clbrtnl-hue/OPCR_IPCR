import React, { useEffect, useMemo, useRef, useState } from "react";
import { Button, Card, Drawer, Empty, Select, Space, Table, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";
import { ROLE_LABELS, SECTION_LABELS, statusMeta } from "~/utils/constants";
import { averageOf, ratingRules, scoreOptions } from "~/utils/ratingScale";
import RichText from "~/components/RichText";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import AccomplishmentView, { AccomplishmentCell } from "~/components/AccomplishmentView";

function linePeriodId(line, chosen) {
    if (line?.form?.rating_period_id) return line.form.rating_period_id;

    return chosen ?? line?.form?.periods?.find((period) => period.is_active)?.id ?? line?.form?.periods?.[0]?.id ?? null;
}

function accomplishmentFor(line, periodId) {
    return (line.accomplishments ?? []).find((row) => row.rating_period_id === periodId) ?? null;
}

const TARGET_KIND_LABELS = {
    opcr: "OPCR",
    vp: "VP",
    program_head: "Head",
};

const TARGET_KIND_ORDER = ["opcr", "vp", "program_head"];

function targetKindLabel(kind) {
    return TARGET_KIND_LABELS[kind] || ROLE_LABELS[kind] || "Other";
}

export default function TargetRating({ periodFilter = null }) {
    const { user } = useAuth();
    const organization = useOrganization();
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState(null);
    const [chosenPeriodId, setChosenPeriodId] = useState(null);
    const [scores, setScores] = useState({});
    const [openLineId, setOpenLineId] = useState(null);
    const dirtyScores = useRef(new Set());
    const scale = ratingRules(user);
    const scoresChoices = scoreOptions(user);

    const { data: targets = [], isLoading } = useQuery({
        queryKey: ["rating-targets", user?.id, periodFilter],
        queryFn: () => {
            const query = periodFilter ? `?rating_period_id=${periodFilter}` : "";

            return api.get(`pcr-ratings/targets${query}`).then((response) => response.data);
        },
    });

    useEffect(() => {
        if (selectedId && targets.some((target) => target.id === selectedId)) return;

        setSelectedId(targets[0]?.id ?? null);
        setChosenPeriodId(null);
        setOpenLineId(null);
    }, [selectedId, targets]);

    const target = targets.find((item) => item.id === selectedId) ?? null;
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
            message.success("Ratings saved.");
            dirtyScores.current = new Set();
            queryClient.invalidateQueries({ queryKey: ["rating-targets"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form"] });
        },
    });

    const columns = [
        {
            title: "Commitment",
            key: "who",
            width: 180,
            fixed: "left",
            render: (_, record) => (
                <div>
                    <Tag color={record.form.type === "opcr" ? "geekblue" : "blue"}>{record.form.type.toUpperCase()}</Tag>
                    <Tag color={statusMeta(record.form.status).color}>{statusMeta(record.form.status).label}</Tag>
                    <div style={{ marginTop: 4 }}>{record.form.owner || record.form.org_unit}</div>
                </div>
            ),
        },
        {
            title: "Success indicator",
            dataIndex: "description",
            width: 260,
            fixed: "left",
            render: (value) => <RichTextView html={value} />,
        },
        {
            title: "Actual accomplishment",
            key: "accomplishment",
            width: 260,
            render: (_, record) => {
                const accomplishment = accomplishmentFor(record, linePeriodId(record, activeChosen));

                return (
                    <AccomplishmentCell
                        html={accomplishment?.actual_accomplishment}
                        evidenceCount={accomplishment?.attachments?.length ?? 0}
                        onOpen={() => setOpenLineId(record.id)}
                    />
                );
            },
        },
        ...scale.dimensions.map((dimension) => ({
            title: dimension.short,
            key: dimension.key,
            width: 90,
            render: (_, record) =>
                record.can_score ? (
                    <Select
                        size="small"
                        style={{ width: 70 }}
                        allowClear
                        value={scores[record.id]?.[dimension.key] ?? undefined}
                        onChange={(value) => update(record.id, dimension.key, value ?? null)}
                        options={scoresChoices}
                    />
                ) : (
                    <span>{scores[record.id]?.[dimension.key] ?? "—"}</span>
                ),
        })),
        {
            title: "A",
            key: "a",
            width: 70,
            render: (_, record) => {
                const value = averageOf(scores[record.id], user);

                return value ? <Tag color="purple">{value}</Tag> : <Tag>—</Tag>;
            },
        },
        {
            title: "Remarks",
            key: "remarks",
            width: 200,
            render: (_, record) =>
                record.can_score ? (
                    <RichText
                        rows={2}
                        value={scores[record.id]?.remarks ?? ""}
                        onChange={(html) => update(record.id, "remarks", html)}
                    />
                ) : scores[record.id]?.remarks ? (
                    <RichTextView html={scores[record.id].remarks} />
                ) : (
                    <Typography.Text type="secondary">—</Typography.Text>
                ),
        },
    ];

    const scoreableCount = lines.filter((line) => line.can_score).length;

    const targetLabel = (item) => {
        const text = toPlainText(item.description) || item.output || "Untitled target";

        if ((item.kind || "opcr") === "opcr") return text;

        const college = organization.name || "Opol Community College";
        const office = item.office && item.office !== college ? `${item.office} — ` : "";

        return `${office}${text}`;
    };

    const targetOptions = useMemo(() => {
        const buckets = new Map();

        targets.forEach((item) => {
            const kind = item.kind || "opcr";
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
    }, [targets, organization.name]);

    return (
        <>
            <Card style={{ marginBottom: 16 }}>
                <Space wrap className="pms-rate-pick">
                    <span>Target:</span>
                    <Select
                        className="pms-rate-form pms-rate-target"
                        classNames={{ popup: { root: "pms-rate-form-popup pms-rate-target-popup" } }}
                        popupMatchSelectWidth={false}
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
                    {needsPeriod && periodChoices.length > 0 && (
                        <Select
                            value={activeChosen}
                            onChange={setChosenPeriodId}
                            options={periodChoices.map((period) => ({ value: period.id, label: period.label }))}
                        />
                    )}
                </Space>
            </Card>

            {!target ? (
                <Card>
                    <Empty
                        description={
                            targets.length === 0
                                ? "No commitments are linked to a target you can see."
                                : "Pick a target above to rate the commitments linked to it."
                        }
                    />
                </Card>
            ) : (
                <Card
                    className="pms-rate-card"
                    title={
                        <Space wrap>
                            {target.section && <Tag>{SECTION_LABELS[target.section] ?? target.section}</Tag>}
                            <span>{targetLabel(target)}</span>
                            <Tag>{lines.length}</Tag>
                        </Space>
                    }
                    extra={
                        <Button
                            loading={save.isPending}
                            disabled={scoreableCount === 0 || (needsPeriod && !activeChosen)}
                            onClick={() => save.mutate()}
                        >
                            Save progress
                        </Button>
                    }
                >
                    <Typography.Paragraph type="secondary">
                        Score the ones waiting on you. Return, send on, and finalize stay on By form.
                    </Typography.Paragraph>
                    <div className="pms-desktop-only">
                        <Table rowKey="id" dataSource={lines} columns={columns} pagination={false} size="small" scroll={{ x: 1340 }} />
                    </div>
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
                            <span>Actual accomplishment</span>
                            <div>
                                <AccomplishmentView
                                    html={accomplishmentFor(openLine, linePeriodId(openLine, activeChosen))?.actual_accomplishment}
                                    attachments={[]}
                                    empty="No accomplishment yet"
                                />
                            </div>
                        </div>
                        {scale.dimensions.map((dimension) => (
                            <div key={dimension.key} className="pms-rate-field">
                                <span>{dimension.label}</span>
                                <div>
                                    {openLine.can_score ? (
                                        <span className="pms-rate-picks">
                                            {scoresChoices.map((choice) => (
                                                <button
                                                    key={choice.value}
                                                    type="button"
                                                    className={Number(scores[openLine.id]?.[dimension.key]) === choice.value ? "is-on" : undefined}
                                                    onClick={() => update(openLine.id, dimension.key, choice.value)}
                                                >
                                                    {choice.label}
                                                </button>
                                            ))}
                                        </span>
                                    ) : (
                                        <strong>{scores[openLine.id]?.[dimension.key] ?? "—"}</strong>
                                    )}
                                </div>
                            </div>
                        ))}
                        <div className="pms-rate-field">
                            <span>Remarks</span>
                            {openLine.can_score ? (
                                <RichText
                                    rows={3}
                                    value={scores[openLine.id]?.remarks ?? ""}
                                    onChange={(html) => update(openLine.id, "remarks", html)}
                                />
                            ) : scores[openLine.id]?.remarks ? (
                                <RichTextView html={scores[openLine.id].remarks} />
                            ) : (
                                <Typography.Text type="secondary">No remarks yet</Typography.Text>
                            )}
                        </div>
                    </>
                )}
            </Drawer>
        </>
    );
}
