import React from "react";
import { Empty } from "antd";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import { RATING_LEGEND, SECTION_LABELS, VIZ } from "~/utils/constants";
import StatusRing from "~/components/charts/StatusRing";

const TYPE_LABELS = { office: "Office", program: "Program" };

const BAND_COLOR = {
    Outstanding: VIZ.good,
    "Very Satisfactory": VIZ.series[2],
    Satisfactory: VIZ.series[0],
    Unsatisfactory: VIZ.warning,
    Poor: VIZ.critical,
};

const FLOW_STATUS = [
    { key: "Completed", color: "#7BC96A", href: () => reportPath("commitments") },
    { key: "Ongoing", color: "#D4920A", href: () => reportPath("commitments") },
    { key: "Not started", color: "#C4B6F2", href: () => reportPath("commitments") },
    { key: "Overdue", color: "#E7A0C4", href: () => "/reports?late=1" },
];

const FLOW_COLOR = {
    strategic: "#7A6AF0",
    core: "#3DDC97",
    support: "#F0A05A",
};

const FLOW_SHORT = {
    strategic: "Strategic",
    core: "Core",
    support: "Support",
};

function hexRgb(hex) {
    const value = Number.parseInt(hex.slice(1), 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

function rgbHsl(r, g, b) {
    const red = r / 255;
    const green = g / 255;
    const blue = b / 255;
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const light = (max + min) / 2;

    if (max === min) return [0, 0, light];

    const delta = max - min;
    const sat = light > 0.5 ? delta / (2 - max - min) : delta / (max + min);
    let hue = 0;

    if (max === red) hue = (green - blue) / delta + (green < blue ? 6 : 0);
    else if (max === green) hue = (blue - red) / delta + 2;
    else hue = (red - green) / delta + 4;

    return [hue / 6, sat, light];
}

function hslHex(hue, sat, light) {
    const channel = (p, q, t) => {
        let turn = t;

        if (turn < 0) turn += 1;
        if (turn > 1) turn -= 1;
        if (turn < 1 / 6) return p + (q - p) * 6 * turn;
        if (turn < 1 / 2) return q;
        if (turn < 2 / 3) return p + (q - p) * (2 / 3 - turn) * 6;

        return p;
    };

    const q = light < 0.5 ? light * (1 + sat) : light + sat - light * sat;
    const p = 2 * light - q;
    const toHex = (part) => Math.round(part * 255).toString(16).padStart(2, "0");

    return `#${toHex(channel(p, q, hue + 1 / 3))}${toHex(channel(p, q, hue))}${toHex(channel(p, q, hue - 1 / 3))}`;
}

/** Keeps the middle of a ribbon vivid, the way the reference blends purple into gold and green into rose. */
function mixRibbon(from, to) {
    const [h1, s1, l1] = rgbHsl(...hexRgb(from));
    const [h2, , l2] = rgbHsl(...hexRgb(to));
    let turn = h2 - h1;

    if (turn > 0.5) turn -= 1;
    if (turn < -0.5) turn += 1;

    return hslHex((h1 + turn * 0.5 + 1) % 1, Math.max(s1, 0.42), (l1 + l2) / 2);
}

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

function FlowMark({ name }) {
    if (name === "Completed") {
        return (
            <svg viewBox="0 0 16 16" width="15" height="15">
                <path d="M3.2 8.4 6.2 11.4 12.8 4.6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (name === "Ongoing") {
        return (
            <svg viewBox="0 0 16 16" width="15" height="15">
                <path d="M4 8h8M9 5l3 3-3 3" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }

    if (name === "Overdue") {
        return (
            <svg viewBox="0 0 16 16" width="15" height="15">
                <path d="M8 4.2v4.4M8 11.6h.01" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
        );
    }

    return (
        <svg viewBox="0 0 16 16" width="15" height="15">
            <circle cx="8" cy="8" r="3.2" fill="none" stroke="currentColor" strokeWidth="1.8" />
        </svg>
    );
}

function WorkFlow({ lines, sections, onOpen }) {
    const model = React.useMemo(() => {
        const matrix = new Map();

        for (const line of lines) {
            const source = line.section && FLOW_SHORT[line.section] ? line.section : "other";
            const target = statusOf(line);
            const key = `${source}|${target}`;
            matrix.set(key, (matrix.get(key) ?? 0) + 1);
        }

        const total = lines.length || 1;
        const sources = [
            ...sections.map((section) => ({
                key: section.section,
                label: FLOW_SHORT[section.section] ?? section.label,
                full: section.label,
                color: section.color,
                href: section.href,
            })),
            {
                key: "other",
                label: "Other",
                full: "Other",
                color: "#9aa0ab",
                href: reportPath("commitments"),
            },
        ]
            .map((source) => ({
                ...source,
                value: FLOW_STATUS.reduce((sum, status) => sum + (matrix.get(`${source.key}|${status.key}`) ?? 0), 0),
            }))
            .filter((source) => source.value > 0)
            .map((source) => ({ ...source, pct: percent(source.value, total) ?? 0 }));

        const targets = FLOW_STATUS.map((status) => ({
            ...status,
            href: status.href(),
            value: sources.reduce((sum, source) => sum + (matrix.get(`${source.key}|${status.key}`) ?? 0), 0),
        })).filter((status) => status.value > 0);

        const gap = 10;
        const band = 168;

        const stack = (nodes) => {
            const sum = nodes.reduce((count, node) => count + node.value, 0) || 1;
            const room = band - gap * Math.max(nodes.length - 1, 0);
            let y = 0;

            return nodes.map((node, index) => {
                const h = Math.max((node.value / sum) * room, 36);
                const placed = { ...node, y, h };
                y += h + (index < nodes.length - 1 ? gap : 0);

                return placed;
            });
        };

        const left = stack(sources);
        const right = stack(targets);
        const leftAt = new Map(left.map((node) => [node.key, node.y]));
        const rightAt = new Map(right.map((node) => [node.key, node.y]));
        const ribbons = [];

        for (const source of left) {
            for (const target of right) {
                const value = matrix.get(`${source.key}|${target.key}`) ?? 0;

                if (!value) continue;

                const h1 = (value / source.value) * source.h;
                const h2 = (value / target.value) * target.h;
                const y1 = leftAt.get(source.key);
                const y2 = rightAt.get(target.key);
                leftAt.set(source.key, y1 + h1);
                rightAt.set(target.key, y2 + h2);
                ribbons.push({
                    key: `${source.key}-${target.key}`,
                    gid: `flow-${source.key}-${target.key}`.replace(/[^a-z0-9-]+/gi, ""),
                    source,
                    target,
                    value,
                    y1,
                    h1,
                    y2,
                    h2,
                });
            }
        }

        const bottom = (nodes) => (nodes.length ? nodes[nodes.length - 1].y + nodes[nodes.length - 1].h : 0);

        return { left, right, ribbons, height: Math.max(bottom(left), bottom(right), 48) };
    }, [lines, sections]);

    const frame = React.useRef(null);
    const [span, setSpan] = React.useState(220);

    React.useLayoutEffect(() => {
        const node = frame.current;

        if (!node) return undefined;

        const measure = () => {
            const next = Math.round(node.getBoundingClientRect().width);

            if (next > 0) setSpan(next);
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => observer.disconnect();
    }, [model.ribbons.length]);

    if (!model.ribbons.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No commitments to trace yet." />;
    }

    const width = span;

    const pathFor = (ribbon) => {
        const r1 = Math.min(ribbon.h1 / 2, 16);
        const r2 = Math.min(ribbon.h2 / 2, 16);
        const x1 = 0;
        const x2 = width;
        const bend = (x2 - x1) * 0.5;
        const y1 = ribbon.y1;
        const y1b = ribbon.y1 + ribbon.h1;
        const y2 = ribbon.y2;
        const y2b = ribbon.y2 + ribbon.h2;

        return [
            `M ${x1} ${y1 + r1}`,
            `A ${r1} ${r1} 0 0 1 ${x1 + r1} ${y1}`,
            `C ${x1 + bend} ${y1}, ${x2 - bend} ${y2}, ${x2 - r2} ${y2}`,
            `A ${r2} ${r2} 0 0 1 ${x2} ${y2 + r2}`,
            `L ${x2} ${y2b - r2}`,
            `A ${r2} ${r2} 0 0 1 ${x2 - r2} ${y2b}`,
            `C ${x2 - bend} ${y2b}, ${x1 + bend} ${y1b}, ${x1 + r1} ${y1b}`,
            `A ${r1} ${r1} 0 0 1 ${x1} ${y1b - r1}`,
            "Z",
        ].join(" ");
    };

    return (
        <div className="pms-flow">
            <div className="pms-flow-side is-left" style={{ height: model.height }}>
                {model.left.map((node) => (
                    <button
                        key={node.key}
                        type="button"
                        style={{ height: node.h }}
                        title={node.full}
                        onClick={() => onOpen(node.href)}
                    >
                        <strong>{node.pct}%</strong>
                        <span>{node.label}</span>
                    </button>
                ))}
            </div>
            <svg ref={frame} className="pms-flow-svg" viewBox={`0 0 ${width} ${model.height}`} style={{ height: model.height }} role="img" aria-label="Where commitments move">
                <defs>
                    {model.ribbons.map((ribbon) => (
                        <linearGradient key={ribbon.key} id={ribbon.gid} x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stopColor={ribbon.source.color} />
                            <stop offset="50%" stopColor={mixRibbon(ribbon.source.color, ribbon.target.color)} />
                            <stop offset="100%" stopColor={ribbon.target.color} />
                        </linearGradient>
                    ))}
                </defs>
                {model.ribbons.map((ribbon) => (
                    <path
                        key={ribbon.key}
                        d={pathFor(ribbon)}
                        fill={`url(#${ribbon.gid})`}
                        onClick={() => onOpen(ribbon.source.href)}
                    >
                        <title>{`${ribbon.source.full} → ${ribbon.target.key} · ${ribbon.value}`}</title>
                    </path>
                ))}
            </svg>
            <div className="pms-flow-side is-right" style={{ height: model.height }}>
                {model.right.map((node) => (
                    <button key={node.key} type="button" style={{ height: node.h }} onClick={() => onOpen(node.href)}>
                        <span className="pms-flow-copy">
                            <strong>{node.value}</strong>
                            <em>{node.key}</em>
                        </span>
                        <span className="pms-flow-badge" style={{ color: node.color }} aria-hidden="true">
                            <FlowMark name={node.key} />
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function statusOf(line) {
    if (line.progress_status === "completed") return "Completed";
    if (line.is_overdue) return "Overdue";
    if (line.progress_status === "not_started") return "Not started";

    return "Ongoing";
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

export default function PresidentGlance({ data }) {
    const navigate = useNavigate();
    const totals = data?.totals ?? {};
    const collegePct = totals.progress_pct ?? null;
    const lines = data?.commitments ?? [];

    const open = (href) => navigate(href);

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
            href: "/reports?late=1",
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

    const sections = (data?.sections ?? []).map((section) => ({
        ...section,
        label: SECTION_LABELS[section.section] ?? section.section,
        href: reportPath("commitments", section.section),
        color: FLOW_COLOR[section.section] ?? "#9aa0ab",
    }));

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

    const sized = sections.filter((section) => section.commitments > 0).sort((a, b) => b.commitments - a.commitments);
    const sectionNote =
        sized.length > 1
            ? `${sized[0].label} takes the largest share, ${sized[sized.length - 1].label} the smallest.`
            : sized.length === 1
              ? `${sized[0].label} holds the commitments recorded so far.`
              : "No section has commitments yet.";

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
                        <span>Paspas</span>
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
                        <span>Wala pa</span>
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

            <BoardCard title="Where the work sits" note={sectionNote} className="pms-board-work">
                <WorkFlow lines={lines} sections={sections} onOpen={open} />
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
