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
    Tooltip,
    Typography,
    message,
} from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
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
import TargetRating from "~/pages/TargetRating";
import IndicatorRow from "~/components/IndicatorRow";
import AccomplishmentView, { AccomplishmentCell } from "~/components/AccomplishmentView";

function RatingLegend({ user }) {
    const bands = user?.workflow?.rating?.bands?.length ? user.workflow.rating.bands : RATING_LEGEND;

    return (
        <Space wrap className="pms-rate-legend">
            <Typography.Text type="secondary">Legend:</Typography.Text>
            {bands.map((band) => (
                <Tag key={band.label} color={ADJECTIVAL_COLORS[band.label]}>
                    {band.value} — {band.label}
                    {band.range ? ` (${band.range})` : ""}
                </Tag>
            ))}
        </Space>
    );
}

function samePerson(left, right) {
    return Number(left) === Number(right);
}

function attendanceRate(category, attendance, periodId) {
    if (!category || !attendance || !periodId) {
        return null;
    }

    return (attendance[periodId] ?? attendance[String(periodId)])?.[category] ?? null;
}

function AttendanceEvidence({ category, attendance, periodId }) {
    const rate = attendanceRate(category, attendance, periodId);

    if (!rate) {
        return null;
    }

    const label = category === "daily" ? "Days present" : "Attendance";
    const excused = rate.leave ?? rate.excused ?? 0;
    const excusedWord = category === "daily" ? "leave" : "excused";
    const tip = (
        <div className="pms-attendance-tip">
            <div>{rate.present ?? 0} present</div>
            <div>{rate.absent ?? 0} absent</div>
            <div>{excused} {excusedWord}</div>
            {rate.counted > 0 ? (
                <div>
                    {rate.pct}% → Suggested {rate.suggested ?? "—"}
                </div>
            ) : (
                <div>{excusedWord} stays out of the percentage.</div>
            )}
            <div>You can still change the score.</div>
        </div>
    );

    if (!rate.counted) {
        return (
            <Tooltip title={tip}>
                <div className="pms-attendance-evidence">{label} —</div>
            </Tooltip>
        );
    }

    return (
        <Tooltip title={tip}>
            <div className="pms-attendance-evidence">
                {label} {rate.pct}%<span>{rate.present}/{rate.counted}</span>
                {rate.suggested != null && <span className="pms-attendance-suggested">Suggested {rate.suggested}</span>}
            </div>
        </Tooltip>
    );
}

function arrivalTime(form) {
    const stamp = form.stage_arrived_at || form.submitted_at || form.created_at;
    const time = stamp ? dayjs(stamp).valueOf() : Number.POSITIVE_INFINITY;

    return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time;
}

export function scoreable(form, user) {
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

export default function RatingPage({ embedded = false, periodFilter = null, ahead = null, focusFormId = null }) {
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const queryClient = useQueryClient();
    const [mode, setMode] = useState("form");
    const [selectedId, setSelectedId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [scores, setScores] = useState({});
    const [openLineId, setOpenLineId] = useState(null);
    const [rateLineId, setRateLineId] = useState(null);
    const [returnModal, setReturnModal] = useState(false);
    const dirtyScores = useRef(new Set());
    const leavingId = useRef(null);
    const followsArrival = !viewOnly;
    const wantedId = focusFormId ? Number(focusFormId) : null;

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["qa-queue", viewOnly ? "oversight" : "mine"],
        queryFn: () =>
            api.get(viewOnly ? "pcr-forms?oversight=rating" : "pcr-forms?queue=1").then((r) => r.data),
    });
    const toRate = useMemo(() => {
        const filtered = queue.filter((form) => {
            if (
                periodFilter &&
                Number(form.rating_period_id ?? form.rating_period?.id) !== Number(periodFilter)
            ) {
                return false;
            }

            if (viewOnly) return true;

            return scoreable(form, user);
        });

        if (!followsArrival) return filtered;

        return [...filtered].sort((a, b) => arrivalTime(a) - arrivalTime(b) || a.id - b.id);
    }, [queue, periodFilter, viewOnly, user, followsArrival]);
    const toRateRef = useRef(toRate);
    toRateRef.current = toRate;

    const openWaiting = (id) => {
        setSelectedId(id);
        setPeriodId(null);
        setOpenLineId(null);
        setRateLineId(null);
    };

    const showNextAfter = (sentId) => {
        leavingId.current = sentId;
        const next = toRateRef.current.find((item) => item.id !== sentId);

        openWaiting(next?.id ?? null);
    };

    useEffect(() => {
        if (isLoading) return;

        if (wantedId && toRate.some((item) => item.id === wantedId)) {
            if (selectedId !== wantedId) {
                openWaiting(wantedId);
            }

            return;
        }

        if (!followsArrival) {
            if (selectedId || toRate.length !== 1) return;

            setSelectedId(toRate[0].id);

            return;
        }

        if (leavingId.current && !toRate.some((item) => item.id === leavingId.current)) {
            leavingId.current = null;
        }

        const waiting = leavingId.current
            ? toRate.filter((item) => item.id !== leavingId.current)
            : toRate;

        if (selectedId && waiting.some((item) => item.id === selectedId)) return;

        const nextId = waiting[0]?.id ?? null;

        if ((selectedId ?? null) === nextId) return;

        setSelectedId(nextId);
        setPeriodId(null);
        setOpenLineId(null);
        setRateLineId(null);
    }, [followsArrival, isLoading, selectedId, toRate, wantedId]);

    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(selectedId)],
        queryFn: () => api.get(`pcr-forms/${selectedId}`).then((r) => r.data),
        enabled: Boolean(selectedId),
    });

    const canRate = scoreable(form, user);
    const readingScores = viewOnly || !["rated", "final"].includes(form?.status);
    const closesHere = (user?.workflow?.rater_roles ?? ["qa"]).includes(user?.role);
    const scale = ratingRules(user);
    const scoresChoices = scoreOptions(user);
    const choiceKey = scoresChoices.map((choice) => choice.value).join(",");
    const dimensionKey = scale.dimensions.map((dimension) => dimension.key).join(",");
    const periods = form?.school_year?.periods ?? [];
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const indicators = useMemo(() => {
        if (!form) return [];

        return form.outputs.flatMap((output) =>
            output.indicators.map((indicator) => {
                const line = { ...indicator, output };
                delete line.children;

                return line;
            })
        );
    }, [form]);

    const openLine = useMemo(
        () => indicators.find((i) => i.id === openLineId) ?? null,
        [indicators, openLineId]
    );
    const rateLine = useMemo(
        () => indicators.find((row) => row.id === rateLineId) ?? null,
        [indicators, rateLineId]
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
                const suggested = canRate
                    ? attendanceRate(indicator.output?.attendance_category, form.attendance, activePeriodId)?.suggested
                    : null;
                const allowed = new Set(choiceKey.split(",").filter(Boolean).map(Number));
                const active = new Set(dimensionKey.split(",").filter(Boolean));
                const offered = suggested != null && allowed.has(Number(suggested)) ? Number(suggested) : null;
                const fill = (key) => {
                    if (existing?.[key] != null) return existing[key];

                    return active.has(key) ? offered : null;
                };
                next[indicator.id] = {
                    q: fill("q"),
                    e: fill("e"),
                    t: fill("t"),
                    remarks: existing?.remarks ?? "",
                };
            });

            return next;
        });
    }, [form, activePeriodId, indicators, canRate, choiceKey, dimensionKey]);

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
            if (followsArrival) showNextAfter(form.id);
            else setSelectedId(null);
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
            if (followsArrival) showNextAfter(form.id);
            else setSelectedId(null);
        },
    });

    const finalize = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/finalize-rating`, { rating_period_id: activePeriodId }),
        onSuccess: ({ data }) => {
            const score = `Rated ${Number(data.summary.final_average).toFixed(2)} — ${data.summary.adjectival}.`;
            message.success(`${score} It leaves your queue.`);
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
            queryClient.invalidateQueries({ queryKey: ["hr-closing"] });
            if (followsArrival) showNextAfter(form.id);
            else setSelectedId(null);
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
            render: (title, record) => (
                <div>
                    <Tag>{SECTION_LABELS[record.output.section]}</Tag>
                    <div style={{ marginTop: 4 }}>{title}</div>
                    <AttendanceEvidence
                        category={record.output.attendance_category}
                        attendance={form?.attendance}
                        periodId={activePeriodId}
                    />
                </div>
            ),
        },
        {
            title: "Success indicator",
            dataIndex: "description",
            width: 220,
            render: (v) => <RichTextView html={v} />,
        },
        {
            title: "Target date",
            dataIndex: "target_date",
            width: 120,
            render: (value) => {
                if (!value) return "—";

                const stamp = dayjs(value);

                return (
                    <span className="pms-review-date">
                        {stamp.isValid() ? stamp.format("MMM D, YYYY") : String(value)}
                    </span>
                );
            },
        },
        {
            title: "Actual accomplishment",
            key: "accomplishment",
            width: 240,
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
            title: "Score note",
            key: "remarks",
            width: 220,
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

    const rateActions = canRate && (
        <Space wrap>
            <Button loading={save.isPending} onClick={() => save.mutate()}>
                Save progress
            </Button>
            <Button danger onClick={() => setReturnModal(true)}>
                Return
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
                    {`Send to ${nextReviewStop(form).label}`}
                </Button>
            )}
        </Space>
    );

    const modeSwitch = !viewOnly ? (
        <Segmented
            value={mode}
            onChange={setMode}
            style={{ marginBottom: 16 }}
            options={[
                { label: "By form", value: "form" },
                { label: "By target", value: "target" },
            ]}
        />
    ) : null;

    if (mode === "target" && !viewOnly) {
        return (
            <>
                {!embedded && (
                    <PageHeader
                        title="Rating"
                        subtitle={`Score each success indicator on ${dimensionPhrase(user)}. A is their average; the final rating is the mean of the section averages.`}
                    />
                )}
                <TargetRating
                    periodFilter={periodFilter}
                    ahead={
                        <>
                            {ahead}
                            {modeSwitch}
                        </>
                    }
                />
            </>
        );
    }

    return (
        <>
            {!embedded && (
                <PageHeader
                    title="Rating"
                    subtitle={
                        viewOnly
                            ? "Scores on forms with QA or already rated. You can read them, but you cannot rate or close a form."
                            : `Score each success indicator on ${dimensionPhrase(user)}. A is their average; the final rating is the mean of the section averages.`
                    }
                />
            )}

            <div className="pms-rate-stick">
                {ahead}
                {modeSwitch}
                <Card className="pms-rate-picker" size="small" style={{ marginBottom: 16 }}>
                    <Space wrap className="pms-rate-pick">
                        <span>{viewOnly ? "Form:" : "Form waiting for a rating:"}</span>
                        <Select
                            className="pms-rate-form"
                            classNames={{ popup: { root: "pms-rate-form-popup" } }}
                            popupMatchSelectWidth
                            loading={isLoading}
                            placeholder="Select a form"
                            value={selectedId}
                            onChange={(value) => {
                                leavingId.current = null;
                                openWaiting(value);
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
                                <Select
                                    style={{ minWidth: 180 }}
                                    value={activePeriodId}
                                    onChange={setPeriodId}
                                    options={periods.map((p) => ({ value: p.id, label: p.label }))}
                                />
                            )
                        )}
                    </Space>
                </Card>
            </div>

            {!selectedId ? (
                <Card>
                    <Empty
                        description={
                            toRate.length === 0
                                ? viewOnly
                                    ? "No forms are in review or already rated."
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
                            className="pms-rate-card"
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
                            extra={rateActions}
                            style={{ marginBottom: 16 }}
                        >
                            {!canRate && (
                                <Alert
                                    type="info"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                    message={readingScores ? "Scores are read-only." : "This rating is closed."}
                                    description={
                                        readingScores
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
                                    description={
                                        closesHere
                                            ? "Every success indicator needs at least one score before the rating can be finalized."
                                            : "Every success indicator needs at least one score before this can be sent on."
                                    }
                                />
                            )}

                            <div className="pms-mobile-only pms-rate-rows">
                                {indicators.map((record) => (
                                    <div key={record.id} className="pms-rate-row">
                                        <span className="pms-rate-summary">
                                            {toPlainText(record.description) || "Untitled line"}
                                        </span>
                                        <Button
                                            type="text"
                                            className="pms-rate-open"
                                            onClick={() => setRateLineId(record.id)}
                                        >
                                            Open
                                        </Button>
                                    </div>
                                ))}
                            </div>
                            <div className="pms-mobile-only pms-rate-actions">{rateActions}</div>
                            <div className="pms-desktop-only">
                                <Table
                                    rowKey="id"
                                    dataSource={indicators}
                                    columns={columns}
                                    pagination={false}
                                    size="small"
                                    scroll={{ x: 1280 }}
                                />
                            </div>

                            <div className="pms-desktop-only">
                                <Divider />
                                <RatingLegend user={user} />
                            </div>
                        </Card>

                        <Card
                            className="pms-rate-feedback"
                            title="Message to ratee"
                        >
                            <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                                Conversation feedback — the ratee sees this under Feedback &amp; history on their form.
                                Score notes above stay on the rating sheet.
                            </Typography.Paragraph>
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

                        <Drawer
                            className="pms-rate-drawer"
                            title="Rating"
                            placement="right"
                            width={420}
                            open={Boolean(rateLine)}
                            onClose={() => setRateLineId(null)}
                        >
                            {rateLine && (
                                <>
                                    <div className="pms-rate-field">
                                        <span>Output</span>
                                        <div>
                                            {toPlainText(rateLine.output?.title || rateLine.output?.description) || "—"}
                                            <AttendanceEvidence
                                                category={rateLine.output?.attendance_category}
                                                attendance={form?.attendance}
                                                periodId={activePeriodId}
                                            />
                                        </div>
                                    </div>
                                    <div className="pms-rate-field">
                                        <span>Success indicator</span>
                                        <div>{toPlainText(rateLine.description) || "Untitled line"}</div>
                                    </div>
                                    <div className="pms-rate-field">
                                        <span>Target date</span>
                                        <div>
                                            <span className="pms-review-date">
                                                {rateLine.target_date && dayjs(rateLine.target_date).isValid()
                                                    ? dayjs(rateLine.target_date).format("MMM D, YYYY")
                                                    : "—"}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="pms-rate-field">
                                        <span>Actual accomplishment</span>
                                        <div>
                                            <AccomplishmentView
                                                html={
                                                    rateLine.accomplishments?.find(
                                                        (row) => row.rating_period_id === activePeriodId
                                                    )?.actual_accomplishment
                                                }
                                                attachments={
                                                    rateLine.accomplishments?.find(
                                                        (row) => row.rating_period_id === activePeriodId
                                                    )?.attachments
                                                }
                                                empty="No accomplishment yet"
                                            />
                                        </div>
                                    </div>
                                    {scale.dimensions.map((dimension) => (
                                        <div key={dimension.key} className="pms-rate-field">
                                            <span>{dimension.label}</span>
                                            <div>
                                                {canRate ? (
                                                    <span className="pms-rate-picks">
                                                        {scoresChoices.map((choice) => (
                                                            <button
                                                                key={choice.value}
                                                                type="button"
                                                                className={
                                                                    Number(scores[rateLine.id]?.[dimension.key]) === choice.value
                                                                        ? "is-on"
                                                                        : undefined
                                                                }
                                                                onClick={() => update(rateLine.id, dimension.key, choice.value)}
                                                            >
                                                                {choice.label}
                                                            </button>
                                                        ))}
                                                    </span>
                                                ) : (
                                                    <strong>{scores[rateLine.id]?.[dimension.key] ?? "—"}</strong>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    <div className="pms-rate-field">
                                        <span>Score note</span>
                                        <div>
                                            {canRate ? (
                                                <RichText
                                                    rows={3}
                                                    value={scores[rateLine.id]?.remarks ?? ""}
                                                    onChange={(html) => update(rateLine.id, "remarks", html)}
                                                />
                                            ) : scores[rateLine.id]?.remarks ? (
                                                <RichTextView html={scores[rateLine.id].remarks} />
                                            ) : (
                                                <Typography.Text type="secondary">No score note yet</Typography.Text>
                                            )}
                                        </div>
                                    </div>
                                    <RatingLegend user={user} />
                                </>
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
