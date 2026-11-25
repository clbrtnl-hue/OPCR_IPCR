import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Alert,
    Button,
    Card,
    Empty,
    Segmented,
    Select,
    Space,
    Tag,
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
    nextReviewStop,
} from "~/utils/constants";
import { averageOf, dimensionPhrase, scoreOptions } from "~/utils/ratingScale";
import PageHeader from "~/components/PageHeader";
import ReturnPlacesModal from "~/components/ReturnPlacesModal";
import TargetRating from "~/pages/TargetRating";
import FormEditorPage from "~/pages/FormEditorPage";
import { adjectivalOf, draftSummary } from "~/utils/draftRating";
import { qaIpcrWithPresident } from "~/utils/formReviewer";
import { formYearId } from "~/components/FormListFilters";

function countsTowardRating(line) {
    if (line?.closed_at || line?.replacement_status === "pending") return false;

    return !line?.needs_commitment_approval || Boolean(line?.line_approved_at);
}

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

function arrivalTime(form) {
    const stamp = form.stage_arrived_at || form.submitted_at || form.created_at;
    const time = stamp ? dayjs(stamp).valueOf() : Number.POSITIVE_INFINITY;

    return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time;
}

export function scoreable(form, user) {
    if (!form || !user || user.role === "admin") return false;
    if (qaIpcrWithPresident(form)) return user.role === "president";
    const step = form.review_steps?.find((item) => item.status === form.status);
    if (step?.reviewer?.id && samePerson(step.reviewer.id, user.id)) return true;
    if (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) return true;
    if (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)) return true;
    if (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id)) return true;
    const raters = user.workflow?.rater_roles ?? ["qa"];
    if (raters.includes(user.role) && form.status === "qa_rating") return true;

    return false;
}

export default function RatingPage({ embedded = false, periodFilter = null, yearFilter, ahead = null, focusFormId = null }) {
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const queryClient = useQueryClient();
    const [mode, setMode] = useState("form");
    const [selectedId, setSelectedId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [scores, setScores] = useState({});
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

            if (yearFilter !== undefined && (yearFilter == null || formYearId(form) !== Number(yearFilter))) {
                return false;
            }

            if (viewOnly) return true;

            return scoreable(form, user);
        });

        if (!followsArrival) return filtered;

        return [...filtered].sort((a, b) => arrivalTime(a) - arrivalTime(b) || a.id - b.id);
    }, [queue, periodFilter, yearFilter, viewOnly, user, followsArrival]);
    const toRateRef = useRef(toRate);
    toRateRef.current = toRate;

    const openWaiting = (id) => {
        setSelectedId(id);
        setPeriodId(null);
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
    }, [followsArrival, isLoading, selectedId, toRate, wantedId]);

    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(selectedId)],
        queryFn: () => api.get(`pcr-forms/${selectedId}`).then((r) => r.data),
        enabled: Boolean(selectedId),
    });

    const canRate = scoreable(form, user);
    const readingScores = viewOnly || !["rated", "final"].includes(form?.status);
    const closesHere =
        (user?.workflow?.rater_roles ?? ["qa"]).includes(user?.role) ||
        (user?.role === "president" && qaIpcrWithPresident(form));
    const scoresChoices = scoreOptions(user);
    const periods = form?.school_year?.periods ?? [];
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;
    const activePeriod = periods.find((p) => p.id === activePeriodId) ?? null;
    const periodLocked =
        form?.type === "opcr"
            ? periods.length > 0 && periods.every((p) => p.is_locked)
            : Boolean(activePeriod?.is_locked);
    const [hiddenAlerts, setHiddenAlerts] = useState(() => new Set());

    useEffect(() => {
        setHiddenAlerts(new Set());
    }, [form?.id]);

    const hideAlert = (key) =>
        setHiddenAlerts((prev) => {
            if (prev.has(key)) return prev;

            const next = new Set(prev);
            next.add(key);

            return next;
        });

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

    useEffect(() => {
        dirtyScores.current = new Set();
    }, [form?.id, activePeriodId]);

    useEffect(() => {
        if (!form || !activePeriodId) return;

        setScores((prev) => {
            const next = {};

            indicators.forEach((indicator) => {
                if (!countsTowardRating(indicator)) return;

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
        onError: (error) => message.error(error?.response?.data?.message ?? "Could not save the ratings."),
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
        onError: (error) => message.error(error?.response?.data?.message ?? "Could not send the form."),
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
        onError: (error) => message.error(error?.response?.data?.message ?? "Could not return the form."),
    });

    const finalize = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/finalize-rating`, {
                rating_period_id: activePeriodId,
            }),
        onSuccess: ({ data }) => {
            const score = `Rated ${Number(data.summary.final_average).toFixed(2)} — ${data.summary.adjectival}.`;
            message.success(`${score} It leaves your queue.`);
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
            if (followsArrival) showNextAfter(form.id);
            else setSelectedId(null);
        },
        onError: (error) => message.error(error?.response?.data?.message ?? "Could not finalize the rating."),
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

    const liveSummary = useMemo(() => {
        if (!form || !canRate || !activePeriodId) return null;

        const draft = draftSummary(form, scores, activePeriodId);

        return {
            ...draft,
            adjectival: adjectivalOf(draft.final_average, user?.workflow?.rating?.bands),
        };
    }, [form, canRate, activePeriodId, scores, user]);

    const rateActions = () =>
        canRate ? (
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
                        loading={finalize.isPending || save.isPending}
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
                {unrated > 0 && (
                    <Typography.Text type="secondary">
                        Rate every line before sending this on.
                    </Typography.Text>
                )}
            </Space>
        ) : null;

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
                    yearFilter={yearFilter}
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
                        {!canRate && !hiddenAlerts.has("closed") && (
                            <Alert
                                type="info"
                                showIcon
                                closable
                                onClose={() => hideAlert("closed")}
                                style={{ marginBottom: 16 }}
                                message={readingScores ? "Scores are read-only." : "This rating is closed."}
                                description={
                                    readingScores
                                        ? "You can read these scores, but you cannot rate or close a form."
                                        : "The form is no longer waiting for a rating, so the scores and remarks are view-only now."
                                }
                            />
                        )}

                        {canRate && periodLocked && !hiddenAlerts.has("locked") && (
                            <Alert
                                type="warning"
                                showIcon
                                closable
                                onClose={() => hideAlert("locked")}
                                style={{ marginBottom: 16 }}
                                message={
                                    form.type === "opcr"
                                        ? `${form.school_year?.label || "This school year"} is closed`
                                        : `${activePeriod?.label || "This review period"} is locked`
                                }
                                description="Ratings cannot be saved or finalized until an administrator unlocks the period."
                            />
                        )}

                        {canRate && unrated > 0 && !hiddenAlerts.has("unrated") && (
                            <Alert
                                type="warning"
                                showIcon
                                closable
                                onClose={() => hideAlert("unrated")}
                                style={{ marginBottom: 16 }}
                                message={`${unrated} line${unrated === 1 ? "" : "s"} still unrated`}
                                description={
                                    closesHere
                                        ? "Every success indicator needs at least one score before the rating can be finalized."
                                        : "Every success indicator needs at least one score before this can be sent on."
                                }
                            />
                        )}

                        <FormEditorPage
                            key={form.id}
                            formId={form.id}
                            hideTitle
                            hideLockAlert
                            liveSummary={liveSummary}
                            toolbarExtra={canRate ? rateActions : null}
                            scoreLegend={canRate ? () => <RatingLegend user={user} /> : null}
                            scoreEdit={{
                                canEdit: canRate && !periodLocked,
                                scores,
                                choices: scoresChoices,
                                onChange: update,
                            }}
                        />

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
