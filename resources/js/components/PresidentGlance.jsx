import React from "react";
import { Empty, Popconfirm, message } from "antd";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import { RATING_LEGEND, VIZ } from "~/utils/constants";
import StatusRing from "~/components/charts/StatusRing";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";

const STAGE_SHORT = {
    approval: "Approver",
    head: "Head",
    dean: "Dean",
    vp: "VP",
};

const TYPE_LABELS = { office: "Office", program: "Program" };

const BAND_COLOR = {
    Outstanding: VIZ.good,
    "Very Satisfactory": VIZ.series[2],
    Satisfactory: VIZ.series[0],
    Unsatisfactory: VIZ.warning,
    Poor: VIZ.critical,
};

function paceColor(pct) {
    if (pct == null) return VIZ.muted;
    if (pct >= 75) return VIZ.good;
    if (pct >= 50) return VIZ.series[0];
    if (pct >= 25) return VIZ.warning;

    return VIZ.critical;
}

function percent(part, whole) {
    if (!whole) return null;

    return Math.round((part / whole) * 100);
}

function reportPath(tab, q) {
    const params = new URLSearchParams({ tab });

    if (q) params.set("q", q);

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

function BoardCard({ title, note, children, className }) {
    return (
        <section className={["pms-board-card", className].filter(Boolean).join(" ")}>
            <header className="pms-board-card-head">
                <h3>{title}</h3>
            </header>
            {children}
            {note && <p className="pms-board-note">{note}</p>}
        </section>
    );
}

export default function PresidentGlance({ data, periodLocked = false }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { user } = useAuth();
    const totals = data?.totals ?? {};
    const collegePct = totals.progress_pct ?? null;
    const lines = data?.commitments ?? [];

    const open = (href) => navigate(href);

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

                const people = [
                    ...new Set(unitLines.map((line) => line.owner).filter(Boolean)),
                ];
                const nextDue = unitLines
                    .filter((line) => line.progress_status !== "completed" && line.target_date)
                    .map((line) => line.target_date)
                    .sort()[0];

                return { ...unit, ...own, pace, people, nextDue: nextDue ?? null };
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
    const aheadPeople = people.filter(
        (person) =>
            person.progress_pct != null &&
            person.progress_pct > 0 &&
            (collegePct == null || person.progress_pct >= collegePct)
    );
    const idlePeople = people.filter((person) => person.progress_pct == null || person.progress_pct === 0);
    const peopleAhead = aheadPeople.length;
    const peopleIdle = idlePeople.length;

    const workSlices = [
        {
            key: "completed",
            label: "Completed",
            value: completed,
            color: VIZ.good,
            href: reportPath("commitments"),
            hint: `${completed} commitment${completed === 1 ? "" : "s"} finished`,
        },
        {
            key: "ongoing",
            label: "Ongoing",
            value: ongoing,
            color: VIZ.series[0],
            href: reportPath("commitments"),
            hint: `${ongoing} commitment${ongoing === 1 ? "" : "s"} in progress`,
        },
        {
            key: "not_started",
            label: "Not started",
            value: notStarted,
            color: VIZ.muted,
            href: reportPath("commitments"),
            hint: `${notStarted} commitment${notStarted === 1 ? "" : "s"} not started`,
        },
        {
            key: "overdue",
            label: "Overdue",
            value: overdue,
            color: VIZ.critical,
            href: "/reports?tab=commitments&late=1",
            hint: `${overdue} commitment${overdue === 1 ? "" : "s"} past the target date`,
        },
    ];

    const vpBars = React.useMemo(() => {
        const groups = new Map();

        for (const row of data?.vps ?? []) {
            const name = row.vp || "No VP";
            const group = groups.get(name) ?? { name, pcts: [], units: [] };
            if (row.progress_pct != null) group.pcts.push(Number(row.progress_pct));
            if (row.unit) group.units.push(row.unit);
            groups.set(name, group);
        }

        return [...groups.values()]
            .map((group) => {
                const value = group.pcts.length
                    ? Math.round(group.pcts.reduce((sum, pct) => sum + pct, 0) / group.pcts.length)
                    : null;

                return {
                    key: group.name,
                    label: group.name,
                    value,
                    color: paceColor(value),
                    href: reportPath("vps", group.name),
                    hint: value != null ? `${group.name} — ${value}% across ${group.units.join(", ")}` : `${group.name} — no commitments yet`,
                };
            })
            .sort((a, b) => (b.value ?? -1) - (a.value ?? -1) || a.label.localeCompare(b.label));
    }, [data]);

    const ratedTotal = Number(totals.rated ?? 0);
    const bands = RATING_LEGEND.map((band) => ({
        key: band.label,
        label: band.label,
        value: Number(data?.adjectival?.[band.label] ?? 0),
        color: BAND_COLOR[band.label] ?? VIZ.series[0],
        href: reportPath("ratings", band.label),
    }));

    const fastestVp = vpBars.find((row) => row.value != null);
    const slowestVp = [...vpBars].reverse().find((row) => row.value != null);
    const vpNote =
        fastestVp && slowestVp && fastestVp.key !== slowestVp.key
            ? `${fastestVp.label} is furthest ahead, while ${slowestVp.label} needs the most support.`
            : fastestVp
              ? `${fastestVp.label} is the only VP with commitments recorded.`
              : "No VP has commitments recorded yet.";

    const waitingStages = (data?.turnaround?.waiting ?? [])
        .filter((row) => row.forms > 0)
        .map((row) => {
            const oldest = row.oldest_days != null ? Number(row.oldest_days) : null;
            let tone = "ok";

            if (oldest != null && oldest >= 7) tone = "late";
            else if (oldest != null && oldest >= 3) tone = "slow";

            return {
                key: row.status,
                label: row.label,
                forms: row.forms,
                oldest,
                tone,
                href: reportPath("forms", row.status),
            };
        });
    const pendingReviewers = [...(data?.turnaround?.reviewers ?? [])]
        .filter((row) => row.pending > 0 && row.oldest_form_id)
        .sort((a, b) => (b.oldest_pending_days ?? 0) - (a.oldest_pending_days ?? 0))
        .slice(0, 4);
    const waitingTotal = waitingStages.reduce((sum, row) => sum + row.forms, 0);
    const queueNote =
        waitingTotal > 0
            ? `${waitingTotal} form${waitingTotal === 1 ? "" : "s"} waiting on a reviewer${
                  overdue > 0 ? ` · ${overdue} commitment${overdue === 1 ? "" : "s"} overdue` : ""
              }.`
            : overdue > 0
              ? `No forms are waiting in review · ${overdue} commitment${overdue === 1 ? "" : "s"} overdue.`
              : "Nothing is waiting on a reviewer right now.";

    return (
        <div className="pms-president">
            <BoardCard title="Offices and programs" className="pms-board-units">
                {ranked.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No offices or programs to compare yet." />
                ) : (
                    <div className="pms-board-table">
                        <div className="pms-board-table-head">
                            <span>Office or program</span>
                            <span>People</span>
                            <span>Progress</span>
                            <span>Due</span>
                            <span>Rating</span>
                        </div>
                        <div className="pms-board-table-body">
                            {ranked.map((unit) => (
                                <button
                                    key={unit.id}
                                    type="button"
                                    className="pms-board-table-row"
                                    onClick={() => open(reportPath("units", unit.name))}
                                >
                                    <span className="pms-board-unit">
                                        <span className="pms-board-unit-name">{unit.name}</span>
                                        <span className="pms-board-unit-meta">
                                            {[TYPE_LABELS[unit.type] ?? unit.type, unit.parent_name].filter(Boolean).join(" · ")}
                                        </span>
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
                                                        unit.progress_pct != null && index < Math.round(unit.progress_pct / 12.5)
                                                            ? { background: paceColor(unit.progress_pct) }
                                                            : undefined
                                                    }
                                                />
                                            ))}
                                        </span>
                                        <strong>{unit.progress_pct != null ? `${unit.progress_pct}%` : "—"}</strong>
                                    </span>
                                    <span className={unit.overdue > 0 ? "pms-board-due is-late" : "pms-board-due"}>
                                        {unit.overdue > 0
                                            ? `${unit.overdue} late`
                                            : unit.nextDue
                                              ? dayjs(unit.nextDue).format("MMM D, YYYY")
                                              : "—"}
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

            <BoardCard title="College status" className="pms-board-status-card">
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
                <button type="button" className="pms-status-meter" onClick={() => open(reportPath("commitments"))}>
                    <span className="pms-status-meter-track">
                        <span className="pms-status-meter-fill" style={{ width: `${onTime ?? 0}%` }}>
                            <span className="pms-status-meter-pill">{onTime != null ? `${onTime}%` : "—"}</span>
                        </span>
                    </span>
                </button>
                <div className="pms-status-piles">
                    <button type="button" onClick={() => open(reportPath("people"))}>
                        <i className="is-ahead" />
                        <span>Ahead</span>
                        <span className="pms-board-faces" aria-hidden="true">
                            {aheadPeople.slice(0, 3).map((person) => (
                                <span key={person.id} title={person.name}>
                                    {initials(person.name)}
                                </span>
                            ))}
                        </span>
                        <strong>+{peopleAhead}</strong>
                    </button>
                    <button type="button" onClick={() => open(reportPath("people"))}>
                        <i />
                        <span>Not started</span>
                        <span className="pms-board-faces" aria-hidden="true">
                            {idlePeople.slice(0, 3).map((person) => (
                                <span key={person.id} title={person.name}>
                                    {initials(person.name)}
                                </span>
                            ))}
                        </span>
                        <strong>+{peopleIdle}</strong>
                    </button>
                </div>
            </BoardCard>

            <BoardCard title="Where forms wait" note={queueNote} className="pms-board-queue">
                {waitingStages.length === 0 && pendingReviewers.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing is waiting on a reviewer." />
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
                                            <em>
                                                {row.oldest != null
                                                    ? `oldest ${Math.round(row.oldest)}d`
                                                    : "in queue"}
                                            </em>
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
                                                onClick={() => row.name && open(reportPath("people", row.name))}
                                                disabled={!row.name}
                                                title={`${who} · ${row.pending} waiting · oldest ${oldestDays}d`}
                                            >
                                                <span className="pms-board-faces" aria-hidden="true">
                                                    <span>{initials(row.name)}</span>
                                                </span>
                                                <span className="pms-board-queue-who">
                                                    <strong>{who}</strong>
                                                    <em>{STAGE_SHORT[row.stage] ?? row.stage}</em>
                                                </span>
                                                <span className="pms-board-queue-wait">
                                                    <strong>{row.pending}</strong>
                                                    <em>oldest {oldestDays}d</em>
                                                </span>
                                            </button>
                                            <Popconfirm
                                                title="Send a follow-up?"
                                                description={
                                                    periodLocked
                                                        ? "This review period is locked. Unlock it before sending a follow-up."
                                                        : `Ask ${who} about ${owner}'s form — waiting ${oldestDays} day${oldestDays === 1 ? "" : "s"}.`
                                                }
                                                okText="Nudge"
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
                                                    Nudge
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

            <BoardCard title="By VP" note={vpNote} className="pms-board-team">
                {vpBars.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No VP is named on a unit yet." />
                ) : (
                    <div className="pms-board-bars">
                        {vpBars.map((row) => (
                            <button key={row.key} type="button" onClick={() => open(row.href)} title={row.hint}>
                                <span>{row.label}</span>
                                <span className="pms-board-track">
                                    <span style={{ width: `${row.value ?? 0}%`, background: row.color }} />
                                </span>
                                <strong>{row.value != null ? `${row.value}%` : "—"}</strong>
                            </button>
                        ))}
                    </div>
                )}
            </BoardCard>

            <BoardCard title="Rating bands" className="pms-board-ratings">
                <div className="pms-board-bands">
                    {bands.map((band) => (
                        <button key={band.key} type="button" onClick={() => open(band.href)}>
                            <i style={{ background: band.color }} />
                            <span>{band.label}</span>
                            <span className="pms-board-track">
                                <span
                                    style={{
                                        width: `${ratedTotal ? (band.value / ratedTotal) * 100 : 0}%`,
                                        background: band.color,
                                    }}
                                />
                            </span>
                            <strong>
                                {band.value}/{ratedTotal || 0}
                            </strong>
                        </button>
                    ))}
                </div>
            </BoardCard>
        </div>
    );
}
