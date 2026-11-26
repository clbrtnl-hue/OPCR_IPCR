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

export default function AttendanceGlance({ data, onOpen }) {
    const rows = data?.rows ?? [];

    if (!rows.length) {
        return null;
    }

    const open = () => onOpen("/attendance");

    const bars = [
        { key: "daily", label: "Daily", hint: "Daily attendance", pct: data.daily_pct, color: "#1baf7a" },
        { key: "activities", label: "Activities", hint: "All activities", pct: data.activity_pct, color: "#2a78d6" },
        ...(data.categories ?? []).map((item) => ({
            key: item.key,
            label: SHORT[item.key] ?? item.label,
            hint: item.label,
            pct: item.pct,
            color: "#1e3a72",
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
        <section className="pms-team-chart pms-att" aria-label="Attendance">
            <div className="pms-team-chart-head">
                <div>
                    <div className="pms-play-kicker">Attendance</div>
                    <h2>
                        {data.daily_pct == null ? "No daily record yet" : `${data.daily_pct}% daily on average`}
                    </h2>
                </div>
                <button type="button" onClick={open}>
                    Attendance
                </button>
            </div>
            <div className="pms-att-body">
                <div className="pms-att-plot">
                    <div className="pms-att-cols">
                        {bars.map((bar) => (
                            <button
                                key={bar.key}
                                type="button"
                                className="pms-att-col"
                                title={`${bar.hint}: ${bar.pct == null ? "no record" : `${bar.pct}%`}`}
                                onClick={open}
                            >
                                <strong>{bar.pct == null ? "—" : `${bar.pct}%`}</strong>
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
                </div>
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
                                onClick={open}
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
            </div>
        </section>
    );
}
