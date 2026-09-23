import React, { useEffect, useMemo, useState } from "react";
import {
    Alert,
    Button,
    Card,
    Divider,
    Drawer,
    Empty,
    Input,
    Segmented,
    Select,
    Space,
    Table,
    Tag,
    Typography,
    message,
} from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import {
    ADJECTIVAL_COLORS,
    RATING_LEGEND,
    SECTION_LABELS,
    STATUS_META,
} from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import RichText from "~/components/RichText";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import CommentThread from "~/components/CommentThread";
import IndicatorRow from "~/components/IndicatorRow";
import { AccomplishmentCell } from "~/components/AccomplishmentView";

const SCORE_OPTIONS = RATING_LEGEND.map((band) => ({
    value: band.value,
    label: `${band.value} — ${band.label}`,
}));

function samePerson(left, right) {
    return Number(left) === Number(right);
}

function scoreable(form, user) {
    if (!form || !user) return false;
    if (user.role === "admin") return form.status === "qa_rating";
    if (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) return true;
    if (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id)) return true;
    if (user.role === "qa" && form.status === "qa_rating") return true;

    return false;
}

export default function RatingPage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [scores, setScores] = useState({});
    const [openLineId, setOpenLineId] = useState(null);

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["qa-queue"],
        queryFn: () => api.get("pcr-forms?queue=1").then((r) => r.data),
    });
    const toRate = queue.filter((f) => scoreable(f, user));

    useEffect(() => {
        if (selectedId || toRate.length !== 1) return;

        setSelectedId(toRate[0].id);
    }, [selectedId, toRate]);

    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(selectedId)],
        queryFn: () => api.get(`pcr-forms/${selectedId}`).then((r) => r.data),
        enabled: Boolean(selectedId),
    });

    const canRate = scoreable(form, user);
    const closesHere = user?.role === "qa" || user?.role === "admin";
    const periods = form?.school_year?.periods ?? [];
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const indicators = useMemo(() => {
        if (!form) return [];

        return form.outputs.flatMap((output) =>
            output.indicators.map((indicator) => ({ ...indicator, output }))
        );
    }, [form]);

    const openLine = useMemo(
        () => indicators.find((i) => i.id === openLineId) ?? null,
        [indicators, openLineId]
    );

    useEffect(() => {
        if (!form || !activePeriodId) return;

        const next = {};

        indicators.forEach((indicator) => {
            const existing = indicator.ratings?.find((r) => r.rating_period_id === activePeriodId);
            next[indicator.id] = {
                q: existing?.q ?? null,
                e: existing?.e ?? null,
                t: existing?.t ?? null,
                remarks: existing?.remarks ?? "",
            };
        });

        setScores(next);
    }, [form, activePeriodId, indicators]);

    const save = useMutation({
        mutationFn: () =>
            api.post("pcr-ratings", {
                form_id: form.id,
                rating_period_id: activePeriodId,
                ratings: Object.entries(scores).map(([indicatorId, value]) => ({
                    indicator_id: Number(indicatorId),
                    ...value,
                })),
            }),
        onSuccess: () => {
            message.success("Ratings saved.");
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
        },
    });

    const sendOn = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/status`, {
                status: form.status === "head_review" ? "vp_review" : "qa_rating",
            }),
        onSuccess: () => {
            message.success(form.status === "head_review" ? "Sent to the VP." : "Sent to QA.");
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            setSelectedId(null);
        },
    });

    const finalize = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/finalize-rating`, { rating_period_id: activePeriodId }),
        onSuccess: ({ data }) => {
            message.success(
                `Rated ${Number(data.summary.final_average).toFixed(2)} — ${data.summary.adjectival}.`
            );
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
            setSelectedId(null);
        },
    });

    const update = (indicatorId, field, value) =>
        setScores((prev) => ({
            ...prev,
            [indicatorId]: { ...prev[indicatorId], [field]: value },
        }));

    const averageOf = (row) => {
        const given = [row?.q, row?.e, row?.t].filter((v) => v != null);
        if (given.length === 0) return null;
        return (given.reduce((a, b) => a + b, 0) / given.length).toFixed(2);
    };

    const unrated = Object.values(scores).filter((row) => averageOf(row) === null).length;

    const columns = [
        {
            title: "Output",
            dataIndex: ["output", "title"],
            width: 160,
            // Keep the row's identity in view while the scores scroll.
            fixed: "left",
            render: (title, record) => (
                <div>
                    <Tag>{SECTION_LABELS[record.output.section]}</Tag>
                    <div style={{ marginTop: 4 }}>{title}</div>
                </div>
            ),
        },
        {
            title: "Success indicator",
            dataIndex: "description",
            width: 260,
            fixed: "left",
            render: (v) => <RichTextView html={v} />,
        },
        {
            title: "Actual accomplishment",
            key: "accomplishment",
            width: 260,
            render: (_, record) => {
                const accomplishment = record.accomplishments?.find(
                    (a) => a.rating_period_id === activePeriodId
                );

                return (
                    <AccomplishmentCell
                        html={accomplishment?.actual_accomplishment}
                        evidenceCount={accomplishment?.attachments?.length ?? 0}
                        onOpen={() => setOpenLineId(record.id)}
                    />
                );
            },
        },
        ...["q", "e", "t"].map((dimension) => ({
            title: dimension.toUpperCase(),
            key: dimension,
            width: 90,
            render: (_, record) => (
                <Select
                    size="small"
                    style={{ width: 70 }}
                    allowClear
                    disabled={!canRate}
                    value={scores[record.id]?.[dimension] ?? undefined}
                    onChange={(value) => update(record.id, dimension, value ?? null)}
                    options={SCORE_OPTIONS.map((o) => ({ value: o.value, label: String(o.value) }))}
                />
            ),
        })),
        {
            title: "A",
            key: "a",
            width: 70,
            render: (_, record) => {
                const value = averageOf(scores[record.id]);
                return value ? <Tag color="purple">{value}</Tag> : <Tag>—</Tag>;
            },
        },
        {
            title: "Remarks",
            key: "remarks",
            width: 200,
            render: (_, record) =>
                canRate ? (
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

    return (
        <>
            <PageHeader
                title="Rating"
                subtitle="Score each success indicator on Quality, Efficiency and Timeliness. A is their average; the final rating is the mean of the section averages."
            />

            <Card style={{ marginBottom: 16 }}>
                <Space wrap>
                    <span>Form waiting for a rating:</span>
                    <Select
                        style={{ minWidth: 340 }}
                        loading={isLoading}
                        placeholder="Select a form"
                        value={selectedId}
                        onChange={(value) => {
                            setSelectedId(value);
                            setPeriodId(null);
                            setOpenLineId(null);
                        }}
                        options={toRate.map((f) => ({
                            value: f.id,
                            label: `${f.type.toUpperCase()} — ${f.owner?.name ?? f.org_unit?.name} (${f.school_year?.label})`,
                        }))}
                    />
                    {formPeriodId ? (
                        <Tag color="blue">
                            {periods.find((p) => p.id === formPeriodId)?.label}
                        </Tag>
                    ) : (
                        periods.length > 0 && (
                            <Segmented
                                value={activePeriodId}
                                onChange={setPeriodId}
                                options={periods.map((p) => ({ value: p.id, label: p.label }))}
                            />
                        )
                    )}
                </Space>
            </Card>

            {!selectedId ? (
                <Card>
                    <Empty
                        description={
                            toRate.length === 0
                                ? "No forms are waiting for a rating."
                                : "Pick a form above to start rating."
                        }
                    />
                </Card>
            ) : (
                form && (
                    <>
                        <Card
                            title={
                                <Space>
                                    <span>
                                        {form.type.toUpperCase()} — {form.owner?.name ?? form.org_unit?.name}
                                    </span>
                                    <Tag color={STATUS_META[form.status].color}>
                                        {STATUS_META[form.status].label}
                                    </Tag>
                                </Space>
                            }
                            extra={
                                canRate && (
                                    <Space>
                                        <Button loading={save.isPending} onClick={() => save.mutate()}>
                                            Save progress
                                        </Button>
                                        {closesHere ? (
                                            <Button
                                                type="primary"
                                                disabled={unrated > 0}
                                                loading={finalize.isPending}
                                                onClick={() => save.mutateAsync().then(() => finalize.mutate())}
                                            >
                                                Finalize rating
                                            </Button>
                                        ) : (
                                            <Button
                                                type="primary"
                                                disabled={unrated > 0}
                                                loading={sendOn.isPending}
                                                onClick={() => save.mutateAsync().then(() => sendOn.mutate())}
                                            >
                                                {form.status === "head_review" ? "Send to the VP" : "Send to QA"}
                                            </Button>
                                        )}
                                    </Space>
                                )
                            }
                            style={{ marginBottom: 16 }}
                        >
                            {!canRate && (
                                <Alert
                                    type="info"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message="This rating is closed."
                                    description="The form is no longer waiting for a rating, so the scores and remarks are view-only now."
                                />
                            )}

                            {canRate && unrated > 0 && (
                                <Alert
                                    type="warning"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message={`${unrated} line${unrated === 1 ? "" : "s"} still unrated`}
                                    description="Every success indicator needs at least one score before the rating can be finalized."
                                />
                            )}

                            <Table
                                rowKey="id"
                                dataSource={indicators}
                                columns={columns}
                                pagination={false}
                                size="small"
                                scroll={{ x: 1340 }}
                            />

                            <Divider />

                            <Space wrap>
                                <Typography.Text type="secondary">Legend:</Typography.Text>
                                {RATING_LEGEND.map((band) => (
                                    <Tag key={band.value} color={ADJECTIVAL_COLORS[band.label]}>
                                        {band.value} — {band.label} ({band.range})
                                    </Tag>
                                ))}
                            </Space>
                        </Card>

                        <Card title="Remarks to the ratee">
                            <CommentThread formId={form.id} />
                        </Card>

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
                                    periodId={activePeriodId}
                                    canEditCommitment={false}
                                    canRecordProgress={false}
                                    canAssign={false}
                                    isOpcr={form.type === "opcr"}
                                />
                            )}
                        </Drawer>
                    </>
                )
            )}
        </>
    );
}
