import React from "react";
import { Empty, Popconfirm, message } from "antd";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import { VIZ } from "~/utils/constants";
import StatusRing from "~/components/charts/StatusRing";
import ColumnChart from "~/components/charts/ColumnChart";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";

const STAGE_SHORT = {
    approval: "Approver",
    head: "Head",
    dean: "Dean",
    vp: "VP",
};

const WAIT_SHORT = {
    commitment_review: "Approver",
    head_review: "Head",
    dean_review: "Dean",
    director_review: "Director",
    vp_review: "VP",
    qa_rating: "QA",
};

const NAME_SKIP = new Set(["of", "the", "and", "for", "in", "a", "an"]);

function paceAgainst(pct, collegePct, overdue = 0) {
    if (overdue > 0) return VIZ.critical;
    if (pct == null) return VIZ.muted;
    if (collegePct == null || pct >= collegePct) return VIZ.good;

    return VIZ.warning;
}

function shortPerson(name) {
    const parts = String(name || "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    return parts[parts.length - 1] || "—";
}

function percent(part, whole) {
    if (!whole) return null;

    return Math.round((part / whole) * 100);
}

function reportPath(tab, q, extra) {
    const params = new URLSearchParams({ tab });

    if (q) params.set("q", q);

    for (const [key, value] of Object.entries(extra ?? {})) {
        if (value != null && value !== "") params.set(key, String(value));
    }

    return `/reports?${params.toString()}`;
}

function initials(name) {
    const parts = String(name || "")
        .trim()
        .split(/\s+/)
        .slice(0, 2);

    const mark = parts.map((part) => part[0]?.toUpperCase() ?? "").join("");

    return mark || "?";
}

/** Saved unit code, otherwise the initials of the name. */
function unitTag(name, code) {
    const saved = String(code || "").trim();

    if (saved) {
        const short = saved.split("-").filter(Boolean).pop() ?? saved;
        return short.toUpperCase();
    }

    const words = String(name || "")
        .split(/[^A-Za-z0-9]+/)
        .filter((word) => word && !NAME_SKIP.has(word.toLowerCase()));

    if (!words.length) return "—";
    if (words.length === 1) return words[0].slice(0, 4).toUpperCase();

    return words
        .map((word) => word[0])
        .join("")
        .toUpperCase()
        .slice(0, 6);
}

function dueFigure(unit) {
    if (unit.overdue > 0) return String(unit.overdue);
    if (!unit.nextDue) return "—";

    const days = dayjs(unit.nextDue).startOf("day").diff(dayjs().startOf("day"), "day");

    return `${Math.max(0, days)}d`;
}

function dueTitle(unit) {
    if (unit.overdue > 0) return `${unit.name} — ${unit.overdue} overdue`;
    if (unit.nextDue) return `${unit.name} — ${dayjs(unit.nextDue).format("MMM D, YYYY")}`;

    return unit.name;
}

/** Same person-average the report uses before a parent office absorbs its children. */
function ownProgress(lines) {
    if (!lines.length) {
        return { progress_pct: null, commitments: 0, completed: 0, overdue: 0 };
    }

    const byPerson = new Map();

    for (const line of lines) {
        const key = line.user_id || `form-${line.form_id}`;
        const group = byPerson.get(key) ?? [];
        group.push(Number(line.progress_pct ?? 0));
        byPerson.set(key, group);
    }

    const personAverages = [...byPerson.values()].map((pcts) =>
        Math.round(pcts.reduce((sum, pct) => sum + pct, 0) / pcts.length)
    );

    return {
        progress_pct: Math.round(personAverages.reduce((sum, pct) => sum + pct, 0) / personAverages.length),
        commitments: lines.length,
        completed: lines.filter((line) => line.progress_status === "completed").length,
        overdue: lines.filter((line) => line.is_overdue).length,
    };
}

function hasWorkingDescendant(id, childrenOf, stats) {
    for (const childId of childrenOf.get(id) ?? []) {
        if ((stats.get(childId)?.commitments ?? 0) > 0 || hasWorkingDescendant(childId, childrenOf, stats)) {
            return true;
        }
    }

    return false;
}

function BoardCard({ title, children, className, aside }) {
    return (
        <section className={["pms-board-card", className].filter(Boolean).join(" ")}>
            <header className="pms-board-card-head">
                <h3>{title}</h3>
                {aside}
            </header>
            {children}
        </section>
    );
}

export default function PresidentGlance({ data, yearId = null, periodId = null, periodLocked = false }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { user } = useAuth();
    const totals = data?.totals ?? {};
    const collegePct = totals.progress_pct ?? null;
    const lines = data?.commitments ?? [];

    const open = (href, options) => navigate(href, options);
    const toReport = (tab, q, extra) =>
        reportPath(tab, q, {
            ...(yearId ? { year: yearId } : {}),
            ...(periodId ? { period: periodId } : {}),
            ...extra,
        });

    const nudge = useMutation({
        mutationFn: (formId) => api.post(`pcr-forms/${formId}/nudge`),
        onSuccess: ({ data: payload }) => {
            message.success(payload?.message ?? "Follow-up sent.");
            queryClient.invalidateQueries({ queryKey: ["report-summary"] });
        },
        onError: (error) => {
            message.error(error?.response?.data?.message ?? "Could not send the follow-up.");
        },
    });

    const ranked = React.useMemo(() => {
        const units = data?.units ?? [];
        const linesByUnit = new Map();

        for (const line of data?.commitments ?? []) {
            const id = Number(line.unit_id);
            const group = linesByUnit.get(id) ?? [];
            group.push(line);
            linesByUnit.set(id, group);
        }

        const stats = new Map(units.map((unit) => [unit.id, ownProgress(linesByUnit.get(unit.id) ?? [])]));
        const childrenOf = new Map();
        const tags = new Map(units.map((unit) => [unit.id, unitTag(unit.name, unit.code)]));

        for (const unit of units) {
            if (!unit.parent_id) continue;

            const list = childrenOf.get(unit.parent_id) ?? [];
            list.push(unit.id);
            childrenOf.set(unit.parent_id, list);
        }

        return units
            .filter((unit) => unit.type === "office" || unit.type === "program")
            .map((unit) => ({ unit, own: stats.get(unit.id), lines: linesByUnit.get(unit.id) ?? [] }))
            .filter(({ unit, own }) => own.commitments > 0 || !hasWorkingDescendant(unit.id, childrenOf, stats))
            .map(({ unit, own, lines: unitLines }) => {
                let pace = "idle";

                if (own.progress_pct != null) {
                    pace = collegePct == null || own.progress_pct >= collegePct ? "ahead" : "behind";
                }

                const people = [...new Set(unitLines.map((line) => line.owner).filter(Boolean))];
                const nextDue = unitLines
                    .filter((line) => line.progress_status !== "completed" && line.target_date)
                    .map((line) => line.target_date)
                    .sort()[0];

                return {
                    ...unit,
                    ...own,
                    pace,
                    people,
                    nextDue: nextDue ?? null,
                    tag: tags.get(unit.id),
                    parentTag: unit.parent_id ? (tags.get(unit.parent_id) ?? null) : null,
                };
            })
            .sort((a, b) => {
                if (a.progress_pct == null && b.progress_pct == null) return a.name.localeCompare(b.name);
                if (a.progress_pct == null) return 1;
                if (b.progress_pct == null) return -1;

                return b.progress_pct - a.progress_pct || a.name.localeCompare(b.name);
            });
    }, [data, collegePct]);

    const completed = lines.filter((line) => line.progress_status === "completed").length;
    const overdue = lines.filter((line) => line.is_overdue).length;
    const notStarted = lines.filter((line) => line.progress_status === "not_started" && !line.is_overdue).length;
    const ongoing = Math.max(0, lines.length - completed - overdue - notStarted);
    const workTotal = completed + ongoing + notStarted + overdue;
    const onTime = percent(Math.max(0, lines.length - overdue), lines.length);

    const people = data?.people ?? [];
    const aheadPeople = people
        .filter(
            (person) =>
                person.progress_pct != null &&
                person.progress_pct > 0 &&
                (collegePct == null || person.progress_pct >= collegePct)
        )
        .sort(
            (a, b) =>
                b.progress_pct - a.progress_pct || String(a.name).localeCompare(String(b.name))
        );
    const idlePeople = people.filter((person) => person.progress_pct == null || person.progress_pct === 0);
    const peopleAhead = aheadPeople.length;
    const peopleIdle = idlePeople.length;

    const headByUnit = new Map((data?.heads ?? []).filter((row) => row.head).map((row) => [row.unit, row.head]));
    const unitByName = new Map((data?.units ?? []).map((unit) => [unit.name, unit]));
    const leaderOf = (person) => {
        const own = headByUnit.get(person.unit);

        if (own && own !== person.name) return own;

        const parentName = unitByName.get(person.unit)?.parent_name;

        return (parentName && headByUnit.get(parentName)) || null;
    };

    const workSlices = [
        {
            key: "completed",
            label: "Completed",
            value: completed,
            color: VIZ.good,
            href: toReport("commitments", null, { progress: "completed" }),
            hint: `${completed} finished`,
        },
        {
            key: "ongoing",
            label: "Ongoing",
            value: ongoing,
            color: VIZ.series[0],
            href: toReport("commitments", null, { progress: "ongoing", standing: "ontime" }),
            hint: `${ongoing} in progress`,
        },
        {
            key: "not_started",
            label: "Not started",
            value: notStarted,
            color: VIZ.muted,
            href: toReport("commitments", null, { progress: "not_started", standing: "ontime" }),
            hint: `${notStarted} not started`,
        },
        {
            key: "overdue",
            label: "Overdue",
            value: overdue,
            color: VIZ.critical,
            href: toReport("commitments", null, { late: 1 }),
            hint: `${overdue} commitment${overdue === 1 ? "" : "s"} past the target date`,
        },
    ];

    const vpBars = React.useMemo(() => {
        const groups = new Map();

        for (const row of data?.vps ?? []) {
            const name = row.vp || "No VP";
            const group = groups.get(name) ?? { name, pcts: [], units: [], overdue: 0 };
            if (row.progress_pct != null) group.pcts.push(Number(row.progress_pct));
            if (row.unit) group.units.push(row.unit);
            group.overdue += Number(row.overdue ?? 0);
            groups.set(name, group);
        }

        return [...groups.values()]
            .map((group) => {
                const value = group.pcts.length
                    ? Math.round(group.pcts.reduce((sum, pct) => sum + pct, 0) / group.pcts.length)
                    : null;

                return {
                    key: group.name,
                    label: shortPerson(group.name),
                    value,
                    color: paceAgainst(value, collegePct, group.overdue),
                    href: toReport("vps", group.name),
                    hint:
                        value != null
                            ? `${group.name} — ${value}% · ${group.units.join(", ")}`
                            : `${group.name} — no commitments yet`,
                };
            })
            .sort((a, b) => (b.value ?? -1) - (a.value ?? -1) || a.label.localeCompare(b.label));
    }, [data, collegePct, yearId, periodId]);

    const formRows = data?.forms ?? [];
    const ipcrForms = formRows.filter((form) => form.type === "IPCR");
    const ipcrSent = ipcrForms.filter((form) => form.status && form.status !== "draft").length;
    const opcr = formRows.find((form) => form.type === "OPCR");
    const opcrChip =
        opcr?.status === "published"
            ? "Published"
            : opcr?.status === "final"
              ? "Final"
              : opcr?.status === "approved"
                ? "Approved"
                : opcr?.status === "draft"
                  ? "Draft"
                  : opcr?.status === "returned"
                    ? "Returned"
                    : opcr
                      ? "In review"
                      : null;
    const sections = ["strategic", "core", "support"].map((key) => {
        const row = (data?.sections ?? []).find((item) => item.section === key) ?? {};

        return {
            key,
            label: key === "strategic" ? "Strategic" : key === "core" ? "Core" : "Support",
            progress_pct: row.progress_pct ?? null,
            commitments: Number(row.commitments ?? 0),
        };
    });
    const ipcrTotal = Number(totals.ipcr ?? ipcrForms.length);

    const deadline = data?.deadlines?.active ?? null;
    const daysLeft = deadline?.days_left ?? null;

    const waitingStages = (data?.turnaround?.waiting ?? [])
        .filter((row) => row.forms > 0 && Number(row.oldest_days ?? 0) >= 3)
        .map((row) => {
            const oldest = Number(row.oldest_days);
            const tone = oldest >= 7 ? "late" : "slow";

            return {
                key: row.status,
                label: WAIT_SHORT[row.status] ?? row.label,
                forms: row.forms,
                oldest,
                tone,
                href: toReport("forms", row.status),
            };
        });
    const pendingReviewers = [...(data?.turnaround?.reviewers ?? [])]
        .filter((row) => row.pending > 0 && row.oldest_form_id && Number(row.oldest_pending_days ?? 0) >= 3)
        .sort((a, b) => (b.oldest_pending_days ?? 0) - (a.oldest_pending_days ?? 0));

    return (
        <div className="pms-president">
            <BoardCard title="Units" className="pms-board-units">
                {ranked.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No offices or programs to compare yet." />
                ) : (
                    <div className="pms-board-table">
                        <div className="pms-board-table-head" aria-hidden="true">
                            <span>Unit</span>
                            <span>People</span>
                            <span>Progress</span>
                            <span>Due</span>
                            <span>Score</span>
                        </div>
                        <div className="pms-board-table-body">
                            {ranked.map((unit) => (
                                <button
                                    key={unit.id}
                                    type="button"
                                    className="pms-board-table-row"
                                    title={unit.parent_name ? `${unit.name} · ${unit.parent_name}` : unit.name}
                                    onClick={() => open(toReport("units", unit.name))}
                                >
                                    <span className="pms-board-unit">
                                        <span className="pms-board-unit-name">{unit.tag}</span>
                                        {unit.parentTag && (
                                            <span className="pms-board-unit-meta" title={unit.parent_name}>
                                                {unit.parentTag}
                                            </span>
                                        )}
                                    </span>
                                    <span className="pms-board-people">
                                        <span className="pms-board-faces" aria-hidden="true">
                                            {unit.people.slice(0, 3).map((name) => (
                                                <span key={name} title={name}>
                                                    {initials(name)}
                                                </span>
                                            ))}
                                        </span>
                                        <strong>{unit.people.length || "—"}</strong>
                                    </span>
                                    <span className="pms-board-progress">
                                        <span className="pms-board-segments" aria-hidden="true">
                                            {Array.from({ length: 8 }, (_, index) => (
                                                <i
                                                    key={index}
                                                    style={
                                                        unit.progress_pct != null &&
                                                        index < Math.round(unit.progress_pct / 12.5)
                                                            ? {
                                                                  background: paceAgainst(
                                                                      unit.progress_pct,
                                                                      collegePct,
                                                                      unit.overdue
                                                                  ),
                                                              }
                                                            : undefined
                                                    }
                                                />
                                            ))}
                                        </span>
                                        <strong
                                            style={{
                                                color: paceAgainst(unit.progress_pct, collegePct, unit.overdue),
                                            }}
                                        >
                                            {unit.progress_pct != null ? `${unit.progress_pct}%` : "—"}
                                        </strong>
                                    </span>
                                    <span className={unit.overdue > 0 ? "pms-board-due is-late" : "pms-board-due"} title={dueTitle(unit)}>
                                        {dueFigure(unit)}
                                    </span>
                                    <span className="pms-board-score">
                                        {unit.average != null ? Number(unit.average).toFixed(1) : "—"}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </BoardCard>

            <BoardCard
                title="Status"
                className="pms-board-status-card"
                aside={
                    <span className="pms-board-chips">
                        {opcrChip && (
                            <button
                                type="button"
                                className="pms-board-chip is-link"
                                title="Open OPCR"
                                onClick={() =>
                                    opcr?.id
                                        ? open(`/forms/${opcr.id}`, { state: { from: "/" } })
                                        : open(toReport("forms", null, { type: "OPCR" }))
                                }
                            >
                                OPCR · {opcrChip}
                            </button>
                        )}
                        <button
                            type="button"
                            className="pms-board-chip is-link"
                            title="IPCRs submitted"
                            onClick={() => open(toReport("forms", null, { type: "IPCR" }))}
                        >
                            IPCR · {ipcrSent}/{ipcrTotal}
                        </button>
                        {deadline && daysLeft != null && (
                            <span
                                className={daysLeft <= 7 ? "pms-board-chip is-late" : "pms-board-chip"}
                                title={deadline.closes_at ? `${deadline.label} closes ${dayjs(deadline.closes_at).format("MMM D, YYYY")}` : deadline.label}
                            >
                                {deadline.label} · {daysLeft >= 0 ? `${daysLeft}d left` : `${Math.abs(daysLeft)}d past`}
                            </span>
                        )}
                    </span>
                }
            >
                <div className="pms-status-top">
                    <StatusRing
                        slices={workSlices}
                        total={workTotal}
                        caption="commitments"
                        onSelect={(slice) => open(slice.href)}
                    />
                    <div className="pms-status-legend">
                        {workSlices.map((slice) => (
                            <button
                                key={slice.key}
                                type="button"
                                title={slice.hint}
                                disabled={slice.value === 0}
                                onClick={() => slice.value > 0 && open(slice.href)}
                            >
                                <i style={{ background: slice.color }} />
                                <span>{slice.label}</span>
                                <strong>{slice.value}</strong>
                            </button>
                        ))}
                    </div>
                </div>
                <button
                    type="button"
                    className="pms-status-meter"
                    title="On time"
                    onClick={() => open(toReport("commitments", null, { standing: "ontime" }))}
                >
                    <span className="pms-status-meter-label">On time</span>
                    <span className="pms-status-meter-track">
                        <span className="pms-status-meter-fill" style={{ width: `${onTime ?? 0}%` }}>
                            <span className="pms-status-meter-pill">{onTime != null ? `${onTime}%` : "—"}</span>
                        </span>
                    </span>
                </button>
                <div className="pms-status-piles">
                    <button type="button" title="Ahead of the college" onClick={() => open(toReport("people", null, { pace: "ahead" }))}>
                        <i className="is-ahead" />
                        <span className="pms-board-faces" aria-hidden="true">
                            {aheadPeople.slice(0, 3).map((person) => (
                                <span key={person.id} title={person.name}>
                                    {initials(person.name)}
                                </span>
                            ))}
                        </span>
                        <span>Ahead</span>
                        <strong>+{peopleAhead}</strong>
                    </button>
                    <button type="button" title="Not started" onClick={() => open(toReport("people", null, { pace: "idle" }))}>
                        <i />
                        <span className="pms-pile-list">
                            {idlePeople.slice(0, 3).map((person) => {
                                const leader = leaderOf(person);

                                return (
                                    <span
                                        key={person.id}
                                        className="pms-pile-row"
                                        title={leader ? `${person.name} · under ${leader}` : person.name}
                                    >
                                        <span className="pms-pile-avatar" aria-hidden="true">{initials(person.name)}</span><b>{shortPerson(person.name)}</b>
                                        {leader && <em>↳ {shortPerson(leader)}</em>}
                                    </span>
                                );
                            })}
                        </span>
                        <span>Not started</span>
                        <strong>+{peopleIdle}</strong>
                    </button>
                </div>
                <div className="pms-status-sections">
                    {sections.map((section) => (
                        <button
                            key={section.key}
                            type="button"
                            title={`${section.label} · ${section.commitments} commitments`}
                            onClick={() => open(toReport("commitments", section.key))}
                        >
                            <em>{section.label}</em>
                            <strong style={{ color: paceAgainst(section.progress_pct, collegePct) }}>
                                {section.progress_pct != null ? `${section.progress_pct}%` : "—"}
                            </strong>
                        </button>
                    ))}
                </div>
            </BoardCard>

            <BoardCard title="Queue" className="pms-board-queue">
                {waitingStages.length === 0 && pendingReviewers.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing needs a reminder right now." />
                ) : (
                    <div className="pms-board-queue-body">
                        {waitingStages.length > 0 && (
                            <div className="pms-board-pipeline">
                                {waitingStages.map((row, index) => (
                                    <button
                                        key={row.key}
                                        type="button"
                                        className={`pms-board-pipe is-${row.tone}`}
                                        title={
                                            row.oldest != null
                                                ? `${row.label}: ${row.forms} form${row.forms === 1 ? "" : "s"} — oldest ${Math.round(row.oldest)}d`
                                                : `${row.label}: ${row.forms} form${row.forms === 1 ? "" : "s"}`
                                        }
                                        onClick={() => open(row.href)}
                                    >
                                        <span className="pms-board-pipe-step" aria-hidden="true">
                                            {index + 1}
                                        </span>
                                        <span className="pms-board-pipe-copy">
                                            <strong>{row.label}</strong>
                                            <em>{row.oldest != null ? `${Math.round(row.oldest)}d` : "—"}</em>
                                        </span>
                                        <span className="pms-board-pipe-count">{row.forms}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {pendingReviewers.length > 0 && (
                            <div className="pms-board-queue-people">
                                {pendingReviewers.map((row) => {
                                    const oldestDays = Math.round(row.oldest_pending_days ?? 0);
                                    const owner = row.oldest_owner_name ?? "a staff member";
                                    const who = row.name ?? "Unassigned";
                                    const onMyStep = Number(row.id) === Number(user?.id);
                                    const canNudgeRow =
                                        Boolean(row.oldest_form_id) &&
                                        !nudge.isPending &&
                                        !periodLocked &&
                                        !onMyStep;

                                    return (
                                        <div key={`${row.stage}-${row.id}`} className="pms-board-queue-row">
                                            <button
                                                type="button"
                                                className="pms-board-queue-person"
                                                onClick={() => row.name && open(toReport("people", row.name))}
                                                disabled={!row.name}
                                                title={`${who} · ${row.pending} waiting · oldest ${oldestDays}d`}
                                            >
                                                <span className="pms-board-faces" aria-hidden="true">
                                                    <span>{initials(row.name)}</span>
                                                </span>
                                                <span className="pms-board-queue-who">
                                                    <strong title={who}>{shortPerson(who)}</strong>
                                                    <em>{STAGE_SHORT[row.stage] ?? row.stage}</em>
                                                </span>
                                                <span className="pms-board-queue-wait">
                                                    <strong>{row.pending}</strong>
                                                    <em>{oldestDays}d</em>
                                                </span>
                                            </button>
                                            <Popconfirm
                                                title="Send a follow-up?"
                                                description={
                                                    periodLocked
                                                        ? "This review period is locked. Unlock it before sending a follow-up."
                                                        : `Ask ${who} about ${owner}'s form — waiting ${oldestDays} day${oldestDays === 1 ? "" : "s"}.`
                                                }
                                                okText="Remind"
                                                cancelText="Cancel"
                                                disabled={!canNudgeRow}
                                                onConfirm={() => nudge.mutate(row.oldest_form_id)}
                                            >
                                                <button
                                                    type="button"
                                                    className="pms-board-queue-nudge"
                                                    disabled={!canNudgeRow}
                                                    title={
                                                        onMyStep
                                                            ? "This form is already waiting on you."
                                                            : periodLocked
                                                              ? "Unlock the review period before sending a follow-up."
                                                              : "Ask this reviewer to check the oldest waiting form"
                                                    }
                                                    onClick={(event) => event.stopPropagation()}
                                                >
                                                    Remind
                                                </button>
                                            </Popconfirm>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}
            </BoardCard>

            <BoardCard title="VPs" className="pms-board-team">
                {vpBars.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No VP is named on a unit yet." />
                ) : (
                    <div className="pms-board-chart">
                        <ColumnChart
                            data={vpBars.map((row) => ({ ...row, value: row.value ?? 0 }))}
                            height={240}
                            max={100}
                            showValues
                            maxWidth={44}
                            valueName="Progress"
                            format={(value) => `${value}%`}
                            onSelect={(datum) => open(datum?.href ?? toReport("vps"))}
                        />
                    </div>
                )}
            </BoardCard>
        </div>
    );
}
