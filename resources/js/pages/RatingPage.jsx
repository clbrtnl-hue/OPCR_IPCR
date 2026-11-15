import React, { useEffect, useMemo, useRef, useState } from "react";
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
    nextReviewStop,
    statusMeta,
} from "~/utils/constants";
import { averageOf, dimensionPhrase, ratingRules, scoreOptions } from "~/utils/ratingScale";
import PageHeader from "~/components/PageHeader";
import RichText from "~/components/RichText";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import CommentThread from "~/components/CommentThread";
import ReturnPlacesModal from "~/components/ReturnPlacesModal";
import IndicatorRow from "~/components/IndicatorRow";
import { AccomplishmentCell } from "~/components/AccomplishmentView";

function samePerson(left, right) {
    return Number(left) === Number(right);
}

function scoreable(form, user) {
    if (!form || !user || user.role === "admin") return false;
    const step = form.review_steps?.find((item) => item.status === form.status);
    if (step?.reviewer?.id && samePerson(step.reviewer.id, user.id)) return true;
    if (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) return true;
    if (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)) return true;
    if (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id)) return true;
    const raters = user.workflow?.rater_roles ?? ["qa"];
    if (raters.includes(user.role) && form.status === "qa_rating") return true;

    return false;
}

export default function RatingPage() {
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [scores, setScores] = useState({});
    const [openLineId, setOpenLineId] = useState(null);
    const [returnModal, setReturnModal] = useState(false);
    const dirtyScores = useRef(new Set());

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["qa-queue", viewOnly ? "oversight" : "mine"],
        queryFn: () =>
            api.get(viewOnly ? "pcr-forms?oversight=rating" : "pcr-forms?queue=1").then((r) => r.data),
    });
    const toRate = viewOnly ? queue : queue.filter((f) => scoreable(f, user));

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
    const closesHere = (user?.workflow?.rater_roles ?? ["qa"]).includes(user?.role);
    const scale = ratingRules(user);
    const scoresChoices = scoreOptions(user);
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
        dirtyScores.current = new Set();
    }, [form?.id, activePeriodId]);

    useEffect(() => {
        if (!form || !activePeriodId) return;

        setScores((prev) => {
            const next = {};

            indicators.forEach((indicator) => {
                if (dirtyScores.current.has(indicator.id) && prev[indicator.id]) {
                    next[indicator.id] = prev[indicator.id];

                    return;
                }

                const existing = indicator.ratings?.find((r) => r.rating_period_id === activePeriodId);
                next[indicator.id] = {
                    q: existing?.q ?? null,
                    e: existing?.e ?? null,
                    t: existing?.t ?? null,
                    remarks: existing?.remarks ?? "",
                };
            });

            return next;
        });
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
            dirtyScores.current = new Set();
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
        },
    });

    const sendOn = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/status`, {
                status: nextReviewStop(form).status,
            }),
        onSuccess: () => {
            message.success(`Sent to ${nextReviewStop(form).label}.`);
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            setSelectedId(null);
        },
    });

    const returnForm = useMutation({
        mutationFn: (fixes) =>
            api.post(`pcr-forms/${form.id}/status`, { status: "returned", fixes }),
        onSuccess: () => {
            message.success("Form returned.");
            setReturnModal(false);
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
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

    const update = (indicatorId, field, value) => {
        dirtyScores.current.add(Number(indicatorId));

        setScores((prev) => ({
            ...prev,
            [indicatorId]: { ...prev[indicatorId], [field]: value },
        }));
    };

    const rowAverage = (row) => averageOf(row, user);

    const unrated = Object.values(scores).filter((row) => rowAverage(row) === null).length;

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
        ...scale.dimensions.map((dimension) => ({
            title: dimension.short,
            key: dimension.key,
            width: 90,
            render: (_, record) =>
                canRate ? (
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
                const value = rowAverage(scores[record.id]);
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
                subtitle={
                    viewOnly
                        ? "Scores on forms with QA or already rated. You can read them, but you cannot rate or close a form."
                        : `Score each success indicator on ${dimensionPhrase(user)}. A is their average; the final rating is the mean of the section averages.`
                }
            />

            <Card style={{ marginBottom: 16 }}>
                <Space wrap>
                    <span>{viewOnly ? "Form:" : "Form waiting for a rating:"}</span>
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
                                ? viewOnly
                                    ? "No forms are with QA or already rated."
                                    : "No forms are waiting for a rating."
                                : viewOnly
                                  ? "Pick a form above to read its scores."
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
                                    <Tag color={statusMeta(form.status, form).color}>
                                        {statusMeta(form.status, form).label}
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
                                            <>
                                                <Button danger onClick={() => setReturnModal(true)}>
                                                    Return
                                                </Button>
                                                <Button
                                                    type="primary"
                                                    disabled={unrated > 0}
                                                    loading={finalize.isPending}
                                                    onClick={() => save.mutateAsync().then(() => finalize.mutate())}
                                                >
                                                    Finalize rating
                                                </Button>
                                            </>
                                        ) : (
                                            <Button
                                                type="primary"
                                                disabled={unrated > 0}
                                                loading={sendOn.isPending}
                                                onClick={() => save.mutateAsync().then(() => sendOn.mutate())}
                                            >
                                                {`Send to ${nextReviewStop(form).label}`}
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
                                    message={viewOnly ? "Scores are read-only." : "This rating is closed."}
                                    description={
                                        viewOnly
                                            ? "You can read these scores, but you cannot rate or close a form."
                                            : "The form is no longer waiting for a rating, so the scores and remarks are view-only now."
                                    }
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
                                {(user?.workflow?.rating?.bands?.length
                                    ? user.workflow.rating.bands
                                    : RATING_LEGEND
                                ).map((band) => (
                                    <Tag key={band.label} color={ADJECTIVAL_COLORS[band.label]}>
                                        {band.value} — {band.label}
                                        {band.range ? ` (${band.range})` : ""}
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

                        <ReturnPlacesModal
                            open={returnModal}
                            form={form}
                            kind="writeup"
                            confirming={returnForm.isPending}
                            onCancel={() => setReturnModal(false)}
                            onSubmit={(fixes) => returnForm.mutate(fixes)}
                        />
                    </>
                )
            )}
        </>
    );
}
