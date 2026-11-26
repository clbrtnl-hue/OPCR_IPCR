import React from "react";
import { VIZ } from "~/utils/constants";

const SHORT = {
    meeting: "Meetings",
    school: "School",
    lgu: "LGU",
    convocation: "Convo",
    training: "Training",
};

function bandColor(pct) {
    if (pct == null) return VIZ.muted;
    if (pct >= 90) return "#1baf7a";
    if (pct >= 75) return VIZ.warning;

    return VIZ.critical;
}

export default function AttendanceGlance({ data, yearId, periodId, onOpen }) {
    const isSelf = data?.scope === "self";
    const rows = data?.rows ?? [];

    if (!isSelf && !rows.length) {
        return null;
    }

    const attendanceUrl = (extra = {}) => {
        const params = new URLSearchParams();

        if (extra.tab) params.set("tab", extra.tab);
        if (yearId) params.set("year", String(yearId));
        if (periodId) params.set("period", String(periodId));

        const query = params.toString();

        return query ? `/attendance?${query}` : "/attendance";
    };

    const openDaily = () => onOpen(attendanceUrl());
    const openBar = (bar) =>
        onOpen(bar.key === "daily" ? attendanceUrl() : attendanceUrl({ tab: "calendar" }));

    const bars = [
        ...(data.daily_pct != null
            ? [{ key: "daily", label: "Daily", hint: "Daily attendance", pct: data.daily_pct, color: "#1baf7a" }]
            : []),
        ...(data.categories ?? [])
            .filter((item) => item.pct != null)
            .map((item) => ({
                key: item.key,
                label: SHORT[item.key] ?? item.label,
                hint: item.label,
                pct: item.pct,
                color: "#2a78d6",
            })),
    ];

    // Lowest first, so the people who need a follow-up are the ones in view.
    const people = [...rows].sort((a, b) => {
        if (a.daily_pct == null && b.daily_pct == null) return String(a.name).localeCompare(String(b.name));
        if (a.daily_pct == null) return 1;
        if (b.daily_pct == null) return -1;

        return a.daily_pct - b.daily_pct || String(a.name).localeCompare(String(b.name));
    });
    const below = rows.filter((row) => row.daily_pct != null && row.daily_pct < 75).length;

    return (
        <section
            className={`pms-team-chart pms-att${isSelf ? " is-self" : ""}`}
            aria-label={isSelf ? "Your attendance" : "Attendance"}
        >
            <div className="pms-team-chart-head">
                <div>
                    <div className="pms-play-kicker">{isSelf ? "Your attendance" : "Attendance"}</div>
                    <h2>
                        {data.daily_pct == null
                            ? "No daily record yet"
                            : isSelf
                              ? `${data.daily_pct}% daily attendance`
                              : `${data.daily_pct}% daily on average`}
                    </h2>
                </div>
                <button type="button" onClick={openDaily}>
                    Attendance
                </button>
            </div>
            <div className="pms-att-body">
                <div className="pms-att-plot">
                    {bars.length > 0 ? (
                        <div className="pms-att-cols">
                            {bars.map((bar) => (
                                <button
                                    key={bar.key}
                                    type="button"
                                    className="pms-att-col"
                                    title={`${bar.hint}: ${bar.pct}%`}
                                    onClick={() => openBar(bar)}
                                >
                                    <strong>{`${bar.pct}%`}</strong>
                                    <span className="pms-att-col-track" aria-hidden="true">
                                        <i
                                            style={{
                                                height: `${Math.max(0, Math.min(100, Number(bar.pct ?? 0)))}%`,
                                                background: bar.color,
                                            }}
                                        />
                                    </span>
                                    <em>{bar.label}</em>
                                </button>
                            ))}
                        </div>
                    ) : (
                        <p className="pms-att-empty">No attendance records for this period yet.</p>
                    )}
                </div>
                {!isSelf && (
                    <div className="pms-att-people">
                        <div className="pms-pace-split">
                            {below > 0
                                ? `${below} ${below === 1 ? "person is" : "people are"} below 75% daily`
                                : "Lowest daily attendance first"}
                        </div>
                        <div className="pms-pace-list pms-att-list">
                            {people.map((row) => (
                                <button
                                    key={row.id}
                                    type="button"
                                    className="pms-pace-row"
                                    title={row.name}
                                    onClick={openDaily}
                                >
                                    <span className="pms-pace-name">
                                        <span>{row.name}</span>
                                    </span>
                                    <span className="pms-pace-track viz-bar-track" aria-hidden="true">
                                        <span
                                            className="viz-bar-fill"
                                            style={{
                                                width: `${Math.max(0, Math.min(100, Number(row.daily_pct ?? 0)))}%`,
                                                background: bandColor(row.daily_pct),
                                            }}
                                        />
                                    </span>
                                    <span className="viz-bar-value">
                                        {row.daily_pct == null ? "—" : `${row.daily_pct}%`}
                                    </span>
                                    {row.daily_pct != null && row.daily_pct < 75 ? (
                                        <span className="pms-pace-late" style={{ color: VIZ.critical }}>
                                            Low
                                        </span>
                                    ) : (
                                        <span className="pms-pace-clear" aria-hidden="true" />
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
