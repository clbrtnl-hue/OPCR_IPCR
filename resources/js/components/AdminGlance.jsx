import React from "react";
import { Popconfirm, message } from "antd";
import { CheckCircleOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import BarRows from "~/components/charts/BarRows";
import ColumnChart from "~/components/charts/ColumnChart";
import StatusRing from "~/components/charts/StatusRing";
import { formYearId } from "~/components/FormListFilters";
import { STATUS_META, VIZ } from "~/utils/constants";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";

dayjs.extend(relativeTime);

const WAITING = new Set([
    "commitment_review",
    "head_review",
    "dean_review",
    "director_review",
    "vp_review",
    "qa_approval",
    "qa_rating",
]);

const SHOWN = 5;
const LATE_SHOWN = 7;
const DAYS = 14;

const ACTIONS = {
    create: "Created",
    update: "Updated",
    delete: "Deleted",
    status: "Moved",
    rate: "Rated",
    upload: "Uploaded",
    avatar: "Photo",
    activate: "Activated",
    deactivate: "Deactivated",
    assign: "Assigned",
    lock: "Locked",
    unlock: "Unlocked",
    reset: "Reset",
    repair: "Repaired",
};

/** Raw statuses folded into the few stops a person thinks in. */
const STOPS = [
    { key: "draft", label: "Draft", statuses: ["draft"], color: VIZ.muted },
    {
        key: "review",
        label: "In review",
        statuses: ["commitment_review", "head_review", "dean_review", "director_review", "vp_review", "qa_approval", "appeal"],
        color: VIZ.series[0],
    },
    { key: "qa", label: "With QA", statuses: ["qa_rating"], color: VIZ.warning },
    {
        key: "approved",
        label: "Approved",
        statuses: ["commitments_approved", "approved", "published"],
        color: VIZ.series[2],
    },
    { key: "rated", label: "Rated", statuses: ["rated", "final"], color: VIZ.good },
    { key: "returned", label: "Returned", statuses: ["returned"], color: VIZ.critical },
];

function humanise(description) {
    if (!description) return "";

    return description.replace(/\b([a-z]+_[a-z_]+|draft|rated|final|approved|published|returned)\b/g, (word) =>
        STATUS_META[word] ? STATUS_META[word].label : word.replace(/_/g, " ")
    );
}

function waitedDays(form) {
    const from = form.updated_at || form.submitted_at;

    if (!from) return 0;

    return Math.max(0, dayjs().startOf("day").diff(dayjs(from).startOf("day"), "day"));
}

function inShownPeriod(form, periodId) {
    if (!periodId) return true;

    const id = form.rating_period_id ?? form.rating_period?.id;

    return !id || Number(id) === Number(periodId);
}

export default function AdminGlance({
    data,
    yearId = null,
    periodId = null,
    liveYear = null,
    livePeriod = null,
    yearsFetched = false,
}) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { user } = useAuth();

    const { data: reviewForms = [], isLoading: reviewLoading } = useQuery({
        queryKey: ["review-queue", "oversight", "review", yearId],
        queryFn: () =>
            api
                .get("pcr-forms", {
                    params: { oversight: "review", ...(yearId ? { school_year_id: yearId } : {}) },
                })
                .then((r) => r.data),
        enabled: Boolean(yearId),
    });
    const { data: ratingForms = [], isLoading: ratingLoading } = useQuery({
        queryKey: ["review-queue", "oversight", "rating", yearId],
        queryFn: () =>
            api
                .get("pcr-forms", {
                    params: { oversight: "rating", ...(yearId ? { school_year_id: yearId } : {}) },
                })
                .then((r) => r.data),
        enabled: Boolean(yearId),
    });
    const { data: users = [], isFetched: usersFetched } = useQuery({
        queryKey: ["users"],
        queryFn: () => api.get("users").then((r) => r.data),
    });
    const { data: offices = [], isFetched: officesFetched } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });
    const { data: activity, isLoading: activityLoading } = useQuery({
        queryKey: ["audit-logs", "dashboard"],
        queryFn: () => api.get("audit-logs?limit=300").then((r) => r.data),
    });

    const nudge = useMutation({
        mutationFn: (formId) => api.post(`pcr-forms/${formId}/nudge`),
        onSuccess: ({ data: payload }) => {
            message.success(payload?.message ?? "Follow-up sent.");
            queryClient.invalidateQueries({ queryKey: ["report-summary"] });
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
        },
        onError: (error) => {
            message.error(error?.response?.data?.message ?? "Could not send the follow-up.");
        },
    });

    const waiting = React.useMemo(() => {
        const forms = new Map();

        for (const form of [...reviewForms, ...ratingForms]) {
            if (!WAITING.has(form.status) || !inShownPeriod(form, periodId)) continue;
            if (yearId && formYearId(form) !== Number(yearId)) continue;
            forms.set(form.id, form);
        }

        return [...forms.values()].sort((a, b) => waitedDays(b) - waitedDays(a) || a.id - b.id);
    }, [reviewForms, ratingForms, periodId, yearId]);

    const overdueUnits = React.useMemo(
        () =>
            (data?.units ?? [])
                .filter((unit) => (unit.type === "office" || unit.type === "program") && Number(unit.overdue) > 0)
                .sort((a, b) => Number(b.overdue) - Number(a.overdue) || a.name.localeCompare(b.name)),
        [data]
    );
    const unassigned = users.filter(
        (person) => person.status === "active" && !["admin", "president"].includes(person.role) && !person.org_unit_id
    );
    const headless = offices.filter(
        (unit) =>
            (unit.type === "office" || unit.type === "program") && !unit.head?.id && !unit.vp?.id && !unit.dean?.id
    );
    const setupReady = usersFetched && officesFetched;
    const setupCount = unassigned.length + headless.length;
    const queueLoading = Boolean(yearId) && (reviewLoading || ratingLoading);
    const oldest = waiting[0] ? waitedDays(waiting[0]) : null;
    const shown = waiting.slice(0, SHOWN);
    const rest = Math.max(0, waiting.length - shown.length);

    const logs = React.useMemo(() => (activity?.logs ?? []).filter((log) => log.action !== "login"), [activity]);
    const events = logs.slice(0, SHOWN);

    const perDay = React.useMemo(() => {
        const counts = new Map();

        for (const log of logs) {
            const key = dayjs(log.created_at).format("YYYY-MM-DD");
            counts.set(key, (counts.get(key) ?? 0) + 1);
        }

        return Array.from({ length: DAYS }, (_, index) => {
            const day = dayjs().subtract(DAYS - 1 - index, "day");
            const value = counts.get(day.format("YYYY-MM-DD")) ?? 0;

            return {
                key: day.format("YYYY-MM-DD"),
                label: day.format("D"),
                value,
                hint: `${day.format("dddd, MMM D")} — ${value} change${value === 1 ? "" : "s"}`,
                color: day.isSame(dayjs(), "day") ? VIZ.series[2] : VIZ.series[0],
            };
        });
    }, [logs]);
    const perDayTotal = perDay.reduce((sum, day) => sum + day.value, 0);

    const stops = STOPS.map((stop) => ({
        key: stop.key,
        label: stop.label,
        color: stop.color,
        value: stop.statuses.reduce((sum, status) => sum + Number(data?.by_status?.[status] ?? 0), 0),
    }));
    const stopTotal = stops.reduce((sum, stop) => sum + stop.value, 0);

    const lateBars = overdueUnits.slice(0, LATE_SHOWN).map((unit, index) => ({
        key: unit.id,
        label: unit.code || unit.name,
        value: Number(unit.overdue),
        color: VIZ.critical,
        hint: `${unit.name} — ${unit.overdue} overdue`,
        q: unit.name,
        rank: index + 1,
    }));

    const cycleTitle = !yearsFetched ? "School year" : liveYear?.label ?? "No school year is open";
    const cycleDetail = !yearsFetched
        ? "Checking which cycle is open."
        : !liveYear
          ? "Set the active school year before forms can move."
          : !livePeriod
            ? "No review period is open."
            : livePeriod.is_locked
              ? `${livePeriod.label} is locked. Forms are view only.`
              : livePeriod.closes_at
                ? `${livePeriod.label} is open until ${dayjs(livePeriod.closes_at).format("MMMM D, YYYY")}.`
                : `${livePeriod.label} is open.`;
    const cycleMark = !yearsFetched ? "" : !liveYear || !livePeriod ? "Not open" : livePeriod.is_locked ? "Locked" : "Open";

    const openActivity = (log) => {
        if (log.subject_type === "PcrForm" && log.subject_id) {
            navigate(`/forms/${log.subject_id}`, { state: { from: "/" } });
            return;
        }

        navigate("/admin/audit");
    };

    const stat = (value, label, title, tone, onClick) => (
        <button type="button" className="pms-ops-stat" title={title} onClick={onClick}>
            <b style={tone ? { color: tone } : undefined}>{value}</b>
            <span>{label}</span>
        </button>
    );

    return (
        <div className="pms-ops">
            <div className="pms-ops-bar">
                <button type="button" className="pms-ops-cycle" onClick={() => navigate("/admin/school-years")}>
                    <strong>{cycleTitle}</strong>
                    {cycleMark ? (
                        <em className={cycleMark === "Locked" ? "is-locked" : cycleMark === "Open" ? "is-open" : undefined}>
                            {cycleMark}
                        </em>
                    ) : null}
                    <span title={cycleDetail}>{cycleDetail}</span>
                </button>
                <div className="pms-ops-stats">
                    {stat(
                        !yearId || queueLoading ? "—" : waiting.length,
                        "In queue",
                        oldest == null ? "Nothing is sitting with a reviewer" : `Oldest has waited ${oldest} day${oldest === 1 ? "" : "s"}`,
                        waiting.length > 0 ? VIZ.warning : undefined,
                        () => navigate("/review-queue")
                    )}
                    {stat(
                        data?.units ? overdueUnits.length : "—",
                        "Units late",
                        "Units with commitments past the target date",
                        overdueUnits.length > 0 ? VIZ.critical : undefined,
                        () => navigate("/reports?tab=commitments&late=1")
                    )}
                    {stat(
                        setupReady ? setupCount : "—",
                        "Needs setup",
                        setupReady ? `${unassigned.length} without a unit · ${headless.length} without a lead` : "Checking accounts and offices",
                        setupCount > 0 ? VIZ.warning : undefined,
                        () => navigate(unassigned.length > 0 ? "/admin/users" : "/admin/org-units")
                    )}
                </div>
            </div>

            <div className="pms-ops-charts">
                <section className="pms-ops-panel">
                    <header>
                        <h3>Where forms are</h3>
                        <a onClick={() => navigate("/reports?tab=forms")}>Forms</a>
                    </header>
                    <div className="pms-ops-ring">
                        <StatusRing
                            slices={stops}
                            total={stopTotal}
                            caption="forms"
                            onSelect={() => navigate("/reports?tab=forms")}
                        />
                        <div className="pms-status-legend">
                            {stops.map((slice) => (
                                <button
                                    key={slice.key}
                                    type="button"
                                    disabled={slice.value === 0}
                                    onClick={() => navigate("/reports?tab=forms")}
                                >
                                    <i style={{ background: slice.color }} />
                                    <span>{slice.label}</span>
                                    <strong>{slice.value}</strong>
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="pms-ops-panel">
                    <header>
                        <h3>Changes, last {DAYS} days</h3>
                        <span className="pms-board-chip">{perDayTotal}</span>
                    </header>
                    {activityLoading ? (
                        <p className="pms-board-note">Loading the latest changes.</p>
                    ) : (
                        <ColumnChart
                            data={perDay}
                            height={248}
                            maxWidth={28}
                            valueName="Changes"
                            emptyText="No changes yet."
                        />
                    )}
                </section>

            </div>

            <div className="pms-ops-split">
                <section className="pms-ops-panel">
                    <header>
                        <h3>Waiting</h3>
                        {waiting.length > 0 && <a onClick={() => navigate("/review-queue")}>Review queue</a>}
                    </header>
                    {queueLoading ? (
                        <p className="pms-board-note">Checking the queue.</p>
                    ) : shown.length === 0 ? (
                        <div className="pms-ops-clear">
                            <CheckCircleOutlined />
                            <strong>All clear</strong>
                            <span>Nothing is waiting on a reviewer.</span>
                        </div>
                    ) : (
                        <div className="pms-ops-list">
                            {shown.map((form) => {
                                const days = waitedDays(form);
                                const who = form.owner?.name || form.org_unit?.name || "Office form";
                                const unit = form.org_unit?.name;
                                const status = STATUS_META[form.status]?.label ?? form.status;
                                const locked = Boolean(form.rating_period?.is_locked);
                                const onMyStep = Number(form.owner?.id) === Number(user?.id);
                                const canNudge = !nudge.isPending && !locked && !onMyStep;

                                return (
                                    <div key={form.id} className="pms-ops-wait">
                                        <button
                                            type="button"
                                            onClick={() => navigate(`/forms/${form.id}`, { state: { from: "/" } })}
                                        >
                                            <strong>{who}</strong>
                                            <span>
                                                {unit ? `${unit} · ` : ""}
                                                {status}
                                            </span>
                                        </button>
                                        <b className={days >= 7 ? "is-late" : days >= 3 ? "is-slow" : undefined}>{days}d</b>
                                        <Popconfirm
                                            title="Send a follow-up?"
                                            description={
                                                locked
                                                    ? "This review period is locked. Unlock it before sending a follow-up."
                                                    : `Ask the reviewer about ${who}'s form — waiting ${days} day${days === 1 ? "" : "s"}.`
                                            }
                                            okText="Remind"
                                            cancelText="Cancel"
                                            disabled={!canNudge}
                                            onConfirm={() => nudge.mutate(form.id)}
                                        >
                                            <button type="button" className="pms-board-queue-nudge" disabled={!canNudge}>
                                                Remind
                                            </button>
                                        </Popconfirm>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {rest > 0 && (
                        <p className="pms-board-note">
                            <a onClick={() => navigate("/review-queue")}>{rest} more in the review queue</a>
                        </p>
                    )}
                </section>

                <section className="pms-ops-panel">
                    <header>
                        <h3>Late by unit</h3>
                        <a onClick={() => navigate("/reports?tab=commitments&late=1")}>Open</a>
                    </header>
                    <BarRows
                        data={lateBars}
                        emptyText="No unit is behind on its target dates."
                        onSelect={(row) => navigate(`/reports?tab=units&q=${encodeURIComponent(row.q)}`)}
                    />
                </section>

                <section className="pms-ops-panel">
                    <header>
                        <h3>Latest</h3>
                        <a onClick={() => navigate("/admin/audit")}>Audit trail</a>
                    </header>
                    {activityLoading ? (
                        <p className="pms-board-note">Loading the latest changes.</p>
                    ) : events.length === 0 ? (
                        <p className="pms-board-note">No form or account changes yet.</p>
                    ) : (
                        <div className="pms-ops-list">
                            {events.map((log) => {
                                const who = log.user_name || "The system";
                                const action = ACTIONS[log.action] ?? log.action;
                                const text = humanise(log.description) || action;

                                return (
                                    <button
                                        key={log.id}
                                        type="button"
                                        className="pms-ops-event"
                                        title={`${who} · ${text}`}
                                        onClick={() => openActivity(log)}
                                    >
                                        <strong>{who}</strong>
                                        <span>{text}</span>
                                        <time dateTime={log.created_at}>{dayjs(log.created_at).fromNow()}</time>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}
