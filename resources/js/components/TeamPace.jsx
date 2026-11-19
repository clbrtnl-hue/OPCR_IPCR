import React from "react";
import { VIZ } from "~/utils/constants";

const SLICES = [
    { key: "done", label: "Done", color: "#1baf7a" },
    { key: "open", label: "Still open", color: "#2a78d6" },
    { key: "late", label: "Past target", color: VIZ.critical },
];

function Donut({ slices, percent }) {
    const active = slices.filter((slice) => slice.value > 0);
    const sum = active.reduce((count, slice) => count + slice.value, 0);
    const radius = 42;
    const circumference = 2 * Math.PI * radius;
    const gap = active.length > 1 ? 10 : 0;
    let cursor = 0;

    return (
        <div className="pms-team-donut" role="img" aria-label={percent == null ? "No team progress yet" : `${percent}% done`}>
            <svg viewBox="0 0 120 120">
                <g transform="rotate(-90 60 60)">
                    {sum === 0 ? (
                        <circle cx="60" cy="60" r={radius} fill="none" stroke="#e6ebf4" strokeWidth="12" />
                    ) : (
                        active.map((slice) => {
                            const length = (slice.value / sum) * circumference;
                            const draw = Math.max(length - gap, 3);
                            const node = (
                                <circle
                                    key={slice.key}
                                    cx="60"
                                    cy="60"
                                    r={radius}
                                    fill="none"
                                    stroke={slice.color}
                                    strokeWidth="12"
                                    strokeLinecap={active.length > 1 ? "round" : "butt"}
                                    strokeDasharray={`${draw} ${circumference - draw}`}
                                    strokeDashoffset={-cursor}
                                />
                            );
                            cursor += length;

                            return node;
                        })
                    )}
                </g>
            </svg>
            <span>
                <strong>{percent == null ? "—" : `${percent}%`}</strong>
                <em>done</em>
            </span>
        </div>
    );
}

function openRow(row, onOpen) {
    if (row.kind === "person" && row.form_id) {
        onOpen(`/forms/${row.form_id}`, { state: { from: "/" } });
        return;
    }

    onOpen("/my-team");
}

export default function TeamPace({ data, onOpen }) {
    const rows = data?.rows ?? [];
    const totals = data?.totals;

    if (!rows.length || !totals) {
        return null;
    }

    const done = totals.completed ?? 0;
    const late = totals.overdue ?? 0;
    const open = Math.max(0, (totals.commitments ?? 0) - done - late);
    const slices = SLICES.map((slice) => ({
        ...slice,
        value: slice.key === "done" ? done : slice.key === "late" ? late : open,
    }));
    const idle = Math.max(0, totals.people - totals.started);

    return (
        <section className="pms-team-chart" aria-label="Your team">
            <div className="pms-team-chart-head">
                <div>
                    <div className="pms-play-kicker">Your team</div>
                    <h2>{data.view === "units" ? "Offices you lead" : "People you lead"}</h2>
                </div>
                <button type="button" onClick={() => onOpen("/my-team")}>
                    My Team
                </button>
            </div>
            <div className="pms-team-chart-top">
                <Donut slices={slices} percent={totals.progress_pct} />
                <div className="pms-team-legend">
                    {slices.map((slice) => (
                        <button key={slice.key} type="button" onClick={() => onOpen("/my-team")}>
                            <i style={{ background: slice.color }} />
                            <span>{slice.label}</span>
                            <strong>{slice.value}</strong>
                        </button>
                    ))}
                    {idle > 0 && (
                        <p>
                            {idle} {idle === 1 ? "person has" : "people have"} not started
                        </p>
                    )}
                </div>
            </div>
            <div className="pms-pace-list">
                {rows.map((row) => {
                    const pct = row.progress_pct;

                    return (
                        <button key={`${row.kind}-${row.id}`} type="button" className="pms-pace-row" onClick={() => openRow(row, onOpen)}>
                            <span className="pms-pace-name">
                                <span title={row.name}>{row.name}</span>
                            </span>
                            <span className="pms-pace-track viz-bar-track" aria-hidden="true">
                                <span
                                    className="viz-bar-fill"
                                    style={{ width: `${Math.max(0, Math.min(100, Number(pct ?? 0)))}%`, background: "#2a78d6" }}
                                />
                            </span>
                            <span className="viz-bar-value">{pct == null ? "—" : `${pct}%`}</span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
