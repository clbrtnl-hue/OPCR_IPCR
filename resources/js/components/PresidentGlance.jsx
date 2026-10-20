import React from "react";
import { Card, Drawer, Empty, List, Segmented, Tag, Typography } from "antd";
import { CheckSquareOutlined, ClockCircleOutlined, RiseOutlined, WarningOutlined } from "@ant-design/icons";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import { VIZ } from "~/utils/constants";
import StatTile from "~/components/charts/StatTile";

const TYPE_LABELS = { office: "Office", program: "Program" };

function paceColor(pct) {
    if (pct == null) return VIZ.muted;
    if (pct >= 75) return VIZ.good;
    if (pct >= 50) return VIZ.series[0];
    if (pct >= 25) return VIZ.warning;

    return VIZ.critical;
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

export default function PresidentGlance({ data }) {
    const navigate = useNavigate();
    const [kind, setKind] = React.useState("all");
    const [openId, setOpenId] = React.useState(null);

    const totals = data?.totals ?? {};
    const collegePct = totals.progress_pct ?? null;

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
            .map((unit) => ({ unit, own: stats.get(unit.id) }))
            .filter(({ unit, own }) => own.commitments > 0 || !hasWorkingDescendant(unit.id, childrenOf, stats))
            .map(({ unit, own }) => {
                let pace = "idle";

                if (own.progress_pct != null) {
                    pace = collegePct == null || own.progress_pct >= collegePct ? "ahead" : "behind";
                }

                return { ...unit, ...own, pace };
            })
            .sort((a, b) => {
                if (a.progress_pct == null && b.progress_pct == null) return a.name.localeCompare(b.name);
                if (a.progress_pct == null) return 1;
                if (b.progress_pct == null) return -1;

                return b.progress_pct - a.progress_pct || a.name.localeCompare(b.name);
            });
    }, [data, collegePct]);

    const rows = ranked.filter((unit) => kind === "all" || unit.type === kind);
    const idleCount = ranked.filter((unit) => unit.pace === "idle").length;
    const open = rows.find((unit) => unit.id === openId) ?? ranked.find((unit) => unit.id === openId) ?? null;

    const lines = (data?.commitments ?? [])
        .filter((line) => open && Number(line.unit_id) === open.id)
        .sort((a, b) => a.progress_pct - b.progress_pct || (b.days_late ?? 0) - (a.days_late ?? 0));

    let markedBehind = false;
    let markedIdle = false;

    return (
        <>
            <div className="pms-cycle-grid">
                <StatTile
                    label="Overall progress"
                    icon={<RiseOutlined />}
                    accent={paceColor(collegePct)}
                    value={collegePct != null ? `${collegePct}%` : "—"}
                    meter={{ pct: collegePct ?? 0, color: paceColor(collegePct) }}
                    hint="Average across every commitment in the college"
                />
                <StatTile
                    label="Commitments completed"
                    icon={<CheckSquareOutlined />}
                    accent="#2a78d6"
                    value={`${totals.completed ?? 0}/${totals.commitments ?? 0}`}
                    hint={
                        totals.commitments
                            ? `${totals.in_flight ?? 0} ongoing · ${totals.not_started ?? 0} not started`
                            : "No commitments recorded yet"
                    }
                />
                <StatTile
                    label="Overdue commitments"
                    icon={<ClockCircleOutlined />}
                    accent={(totals.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    value={totals.overdue ?? 0}
                    tone={(totals.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    hint={`${totals.due_soon ?? 0} more fall due within seven days`}
                />
                <StatTile
                    label="Offices and programs not started"
                    icon={<WarningOutlined />}
                    accent={idleCount > 0 ? VIZ.serious : VIZ.good}
                    value={idleCount}
                    tone={idleCount > 0 ? VIZ.serious : VIZ.good}
                    hint="No commitments of their own, and none under them"
                />
            </div>

            <Card
                className="viz-card"
                style={{ marginBottom: 16 }}
                title="Who is ahead, who is behind"
                extra={
                    <Segmented
                        value={kind}
                        onChange={(value) => {
                            setKind(value);
                            setOpenId(null);
                        }}
                        options={[
                            { value: "all", label: "All" },
                            { value: "office", label: "Offices" },
                            { value: "program", label: "Programs" },
                        ]}
                    />
                }
            >
                {rows.length === 0 ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No offices or programs to compare yet." />
                ) : (
                    <div className="pms-pace-list">
                        {rows.map((unit) => {
                            const marks = [];

                            if (!markedBehind && unit.pace === "behind") {
                                markedBehind = true;
                                marks.push(
                                    <div key={`behind-${unit.id}`} className="pms-pace-split">
                                        Below the college average{collegePct != null ? ` of ${collegePct}%` : ""}
                                    </div>
                                );
                            }

                            if (!markedIdle && unit.pace === "idle") {
                                markedIdle = true;
                                marks.push(
                                    <div key={`idle-${unit.id}`} className="pms-pace-split">
                                        No commitments yet
                                    </div>
                                );
                            }

                            marks.push(
                                <button type="button" className="pms-pace-row" onClick={() => setOpenId(unit.id)}>
                                    <span className="pms-pace-name">
                                        <span title={unit.name}>{unit.name}</span>
                                        <Typography.Text type="secondary" className="pms-pace-sub">
                                            {[
                                                TYPE_LABELS[unit.type] ?? unit.type,
                                                unit.parent_name,
                                                unit.commitments
                                                    ? `${unit.completed} of ${unit.commitments} done`
                                                    : "Nothing recorded",
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </Typography.Text>
                                    </span>
                                    <span className="viz-bar-track pms-pace-track">
                                        <span
                                            className="viz-bar-fill"
                                            style={{
                                                width: `${unit.progress_pct ?? 0}%`,
                                                background: paceColor(unit.progress_pct),
                                            }}
                                        />
                                    </span>
                                    <span className="viz-bar-value">{unit.progress_pct != null ? `${unit.progress_pct}%` : "—"}</span>
                                    {unit.overdue > 0 ? (
                                        <Tag color="error" className="pms-pace-late">
                                            {unit.overdue} late
                                        </Tag>
                                    ) : unit.pace === "idle" ? (
                                        <span className="pms-pace-clear">—</span>
                                    ) : (
                                        <span className="pms-pace-clear">On time</span>
                                    )}
                                </button>
                            );

                            return <React.Fragment key={unit.id}>{marks}</React.Fragment>;
                        })}
                    </div>
                )}
            </Card>

            <Drawer
                title={open?.name ?? "Unit"}
                open={Boolean(open)}
                onClose={() => setOpenId(null)}
                width={480}
                destroyOnClose
            >
                {open && (
                    <>
                        <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                            {[TYPE_LABELS[open.type] ?? open.type, open.parent_name ? `under ${open.parent_name}` : null]
                                .filter(Boolean)
                                .join(" · ")}
                            {open.progress_pct != null
                                ? ` · ${open.progress_pct}% across the people on this unit`
                                : " · no commitments yet"}
                        </Typography.Paragraph>
                        {lines.length === 0 ? (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="Nothing has been recorded on this unit yet."
                            />
                        ) : (
                            <List
                                size="small"
                                dataSource={lines}
                                renderItem={(line) => (
                                    <List.Item
                                        style={{ cursor: "pointer" }}
                                        onClick={() =>
                                            navigate(`/forms/${line.form_id}`, { state: { from: "/" } })
                                        }
                                        actions={[
                                            line.is_overdue ? (
                                                <Tag key="late" color="error">
                                                    {line.days_late}d late
                                                </Tag>
                                            ) : (
                                                <Tag key="pct">{line.progress_pct}%</Tag>
                                            ),
                                        ]}
                                    >
                                        <List.Item.Meta
                                            title={
                                                <Typography.Text ellipsis={{ tooltip: line.description }}>
                                                    {line.description || "Untitled commitment"}
                                                </Typography.Text>
                                            }
                                            description={[
                                                line.owner,
                                                line.target_date
                                                    ? `due ${dayjs(line.target_date).format("MMM D, YYYY")}`
                                                    : null,
                                                `${line.progress_pct}% done`,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        />
                                    </List.Item>
                                )}
                            />
                        )}
                    </>
                )}
            </Drawer>
        </>
    );
}
