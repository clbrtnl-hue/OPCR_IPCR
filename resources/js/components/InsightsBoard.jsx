import React from "react";
import { Card, Col, Empty, List, Row, Space, Tag, Typography } from "antd";
import { useNavigate } from "react-router-dom";
import { RiseOutlined, TrophyOutlined, WarningOutlined } from "@ant-design/icons";
import BarRows from "~/components/charts/BarRows";
import StackedColumns from "~/components/charts/StackedColumns";
import StatusRing from "~/components/charts/StatusRing";
import TrendLine from "~/components/charts/TrendLine";
import StatTile from "~/components/charts/StatTile";
import { ADJECTIVAL_COLORS, RATING_LEGEND, STATUS_META } from "~/utils/constants";
import { useTheme } from "~/hooks/useTheme";

function reportPath(tab, q) {
    const params = new URLSearchParams({ tab });

    if (q) params.set("q", q);

    return `/reports?${params.toString()}`;
}

function SpreadRing({ slices, caption, onOpen }) {
    const total = slices.reduce((sum, slice) => sum + Number(slice.value ?? 0), 0);

    return (
        <div className="pms-status-top">
            <StatusRing slices={slices} total={total} caption={caption} onSelect={onOpen} />
            <div className="pms-status-legend">
                {slices.map((slice) => (
                    <button
                        key={slice.key}
                        type="button"
                        disabled={Number(slice.value) === 0}
                        onClick={(event) => {
                            event.stopPropagation();
                            if (Number(slice.value) > 0) onOpen(slice);
                        }}
                    >
                        <i style={{ background: slice.color }} />
                        <span>{slice.label}</span>
                        <strong>{slice.value}</strong>
                    </button>
                ))}
            </div>
        </div>
    );
}

function latePath(q) {
    const params = new URLSearchParams({ tab: "commitments", late: "1" });

    if (q) params.set("q", q);

    return `/reports?${params.toString()}`;
}

function stagesFor(viz) {
    return [
        { key: "drafting", label: "Drafting", color: viz.ordinal[0] },
        { key: "submitted", label: "Submitted", color: viz.ordinal[2] },
        { key: "rated", label: "Rated", color: viz.ordinal[4] },
    ];
}

function bandFillFor(viz) {
    return {
        Outstanding: viz.good,
        "Very Satisfactory": viz.series[2],
        Satisfactory: viz.series[0],
        Unsatisfactory: viz.warning,
        Poor: viz.critical,
    };
}

function statusFillFor(viz) {
    return {
        draft: viz.ordinal[1],
        returned: viz.critical,
        head_review: viz.ordinal[0],
        dean_review: viz.ordinal[2],
        vp_review: viz.series[0],
        qa_approval: viz.warning,
        approved: viz.series[2],
        published: viz.good,
        qa_rating: viz.series[1],
        rated: viz.ordinal[4],
        final: viz.ink,
    };
}

function useCompactCharts() {
    const query = "(max-width: 991px)";
    const [compact, setCompact] = React.useState(() =>
        typeof window !== "undefined" ? window.matchMedia(query).matches : false
    );

    React.useEffect(() => {
        const media = window.matchMedia(query);
        const onChange = () => setCompact(media.matches);
        media.addEventListener("change", onChange);
        return () => media.removeEventListener("change", onChange);
    }, []);

    return compact;
}

export default function InsightsBoard({ data }) {
    const navigate = useNavigate();
    const { viz } = useTheme();
    const compact = useCompactCharts();
    const chartHeight = {
        trend: compact ? 156 : 220,
        columns: compact ? 176 : 260,
    };
    const stages = stagesFor(viz);
    const bandFill = bandFillFor(viz);
    const statusFill = statusFillFor(viz);
    const [scene, setScene] = React.useState("look");
    const totals = data?.totals;
    const open = (target) => {
        const href = typeof target === "string" ? target : target?.href;

        if (href) navigate(href);
    };

    if (!totals) {
        return <Empty description="Pick a school year to see its insights." />;
    }

    const coverage = data.coverage ?? {};
    const pipeline = Object.entries(data.by_status ?? {})
        .filter(([, count]) => count > 0)
        .map(([status, count]) => ({
            key: status,
            label: STATUS_META[status]?.label ?? status,
            value: count,
            color: statusFill[status] ?? viz.series[0],
            hint: `${count} form${count === 1 ? "" : "s"} — ${STATUS_META[status]?.who ?? ""}`,
            href: reportPath("forms", status),
        }));

    const bands = RATING_LEGEND.map((band) => ({
        key: band.label,
        label: band.label,
        value: data.adjectival?.[band.label] ?? 0,
        color: bandFill[band.label] ?? viz.ordinal[band.value - 1],
        hint: `${data.adjectival?.[band.label] ?? 0} form(s) rated ${band.label} (${band.range})`,
        href: reportPath("ratings", band.label),
    }));

    const trend = (data.trend ?? []).map((point) => ({
        label: point.short ?? point.label,
        hint: point.label,
        value: point.average,
        href: reportPath("ratings"),
    }));

    const waiting = (data.turnaround?.waiting ?? [])
        .filter((row) => row.forms > 0)
        .map((row) => ({
            key: row.status,
            label: row.label,
            value: row.median_age_days ?? 0,
            color: viz.warning,
            hint: `${row.forms} form${row.forms === 1 ? "" : "s"} waiting — median ${
                row.median_age_days ?? 0
            }d, oldest ${row.oldest_days ?? 0}d`,
            href: reportPath("forms", row.status),
        }));

    const unitRows = (data.units ?? [])
        .filter((unit) => unit.total_forms > 0 && unit.type !== "college")
        .map((unit) => ({
            key: unit.id,
            label: unit.code || unit.name,
            values: {
                drafting: unit.total_forms - unit.submitted,
                submitted: unit.submitted - unit.rated,
                rated: unit.rated,
            },
            href: reportPath("units", unit.name),
        }));

    const overdueTone = totals.overdue > 0 ? viz.critical : viz.good;

    const people = data.people ?? [];

    const rank = (rows, value, hint, to, limit = 6, keepZero = false) =>
        rows
            .filter((row) => value(row) != null && (keepZero || value(row) > 0))
            .sort((a, b) => value(b) - value(a))
            .slice(0, limit)
            .map((row, index) => ({
                key: `${row.id}-${index}`,
                label: row.name ?? row.head,
                value: value(row),
                hint: hint(row),
                href: to(row),
                rank: index + 1,
            }));

    const laggards = rank(
        people,
        (person) => person.overdue,
        (person) =>
            `${person.name} — ${person.overdue} line${person.overdue === 1 ? "" : "s"} past target, ${person.days_late} day${
                person.days_late === 1 ? "" : "s"
            } late in total`,
        (person) => latePath(person.name)
    );

    const movers = rank(
        people,
        (person) => person.progress_pct,
        (person) =>
            `${person.name} — ${person.completed} of ${person.commitments} lines done (${person.progress_pct}%)`,
        (person) => reportPath("people", person.name)
    );

    const topRated = rank(
        people,
        (person) => person.average,
        (person) => `${person.name} — ${Number(person.average).toFixed(2)} · ${person.adjectival ?? ""}`,
        (person) => reportPath("ratings", person.name)
    );

    const scenes = [
        { key: "look", label: "Overview" },
        { key: "flow", label: "Pipeline" },
        { key: "people", label: "People" },
    ];
    const sceneIndex = Math.max(0, scenes.findIndex((item) => item.key === scene));

    const lookTiles = (
        <div className="pms-cycle-grid pms-glance-grid">
            <StatTile
                label="College average"
                value={totals.college_average != null ? Number(totals.college_average).toFixed(2) : "—"}
                suffix={
                    totals.college_rating ? (
                        <Tag color={ADJECTIVAL_COLORS[totals.college_rating]}>{totals.college_rating}</Tag>
                    ) : null
                }
                hint={`${totals.rated} of ${totals.forms} forms rated so far`}
                onClick={() => open(reportPath("ratings"))}
            />
            <StatTile
                label="Commitments completed"
                value={totals.progress_pct != null ? `${totals.progress_pct}%` : "—"}
                meter={{ pct: totals.progress_pct ?? 0 }}
                hint={`${totals.completed} of ${totals.commitments} committed lines are done`}
                onClick={() => open(reportPath("commitments"))}
            />
            <StatTile
                label={
                    <span className="pms-tile-label-icon">
                        <WarningOutlined /> Overdue commitments
                    </span>
                }
                value={totals.overdue}
                tone={overdueTone}
                hint={`${totals.due_soon} more fall due within seven days`}
                onClick={() => open(latePath())}
            />
            <StatTile
                label="Forms submitted"
                value={`${totals.submitted}/${totals.forms}`}
                meter={{ pct: totals.forms ? (totals.submitted / totals.forms) * 100 : 0 }}
                hint={
                    coverage.expected
                        ? `${coverage.filed} of ${coverage.expected} staff have opened an IPCR (${coverage.pct}%)`
                        : undefined
                }
                onClick={() => open(reportPath("forms"))}
            />
        </div>
    );

    const flowTiles = (
        <div className="pms-cycle-grid pms-glance-grid">
            <StatTile
                label="Waiting for QA"
                value={totals.awaiting_rating}
                hint={`${totals.in_review} still with a head or VP`}
                onClick={() => open(reportPath("forms", "qa_rating"))}
            />
            <StatTile
                label="Returned for correction"
                value={totals.returned}
                tone={totals.returned > 0 ? viz.serious : undefined}
                hint="Sent back by a reviewer and not yet resubmitted"
                onClick={() => open(reportPath("forms", "returned"))}
            />
            <StatTile
                label="Due within seven days"
                value={totals.due_soon}
                tone={totals.due_soon > 0 ? viz.warning : undefined}
                hint={`${totals.not_started} lines have not been started at all`}
                onClick={() => open(reportPath("commitments"))}
            />
            <StatTile
                label="Staff without an IPCR"
                value={coverage.missing ?? 0}
                tone={(coverage.missing ?? 0) > 0 ? viz.serious : viz.good}
                hint={`${totals.units_reporting} of ${totals.units_total} units have opened a form`}
                onClick={() => open(reportPath("units"))}
            />
        </div>
    );

    const waitingCard = (
        <Card
            title="Sitting in a queue right now"
            extra={<Typography.Text type="secondary">Median age</Typography.Text>}
            className="viz-card viz-card-link"
            onClick={() => open(reportPath("forms"))}
        >
            <BarRows
                data={waiting}
                format={(value) => `${value}d`}
                emptyText="Nothing is waiting on a reviewer."
                onSelect={open}
            />

            {(data.turnaround?.reviewers ?? []).filter((row) => row.pending > 0).length > 0 && (
                <List
                    size="small"
                    style={{ marginTop: 12 }}
                    dataSource={(data.turnaround?.reviewers ?? [])
                        .filter((row) => row.pending > 0)
                        .sort((a, b) => (b.oldest_pending_days ?? 0) - (a.oldest_pending_days ?? 0))
                        .slice(0, 5)}
                    renderItem={(row) => (
                        <List.Item
                            className={row.name ? "pms-click-row" : undefined}
                            onClick={
                                row.name
                                    ? (event) => {
                                          event.stopPropagation();
                                          open(reportPath("people", row.name));
                                      }
                                    : undefined
                            }
                            actions={[
                                <Typography.Text key="oldest" type="secondary">
                                    oldest {Math.round(row.oldest_pending_days ?? 0)}d
                                </Typography.Text>,
                            ]}
                        >
                            <List.Item.Meta
                                title={row.name ?? "Unassigned"}
                                description={`${row.pending} waiting · ${
                                    row.forms >= 3
                                        ? `usually ${row.median_days}d`
                                        : `too few finished forms to judge speed`
                                }`}
                            />
                        </List.Item>
                    )}
                />
            )}
        </Card>
    );

    return (
        <>
            <div className="pms-scenes">
                <div className="pms-scene-switch" role="tablist" aria-label="Dashboard view">
                    <i style={{ transform: `translateX(${sceneIndex * 100}%)` }} />
                    {scenes.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            role="tab"
                            aria-selected={scene === item.key}
                            className={scene === item.key ? "is-on" : ""}
                            onClick={() => setScene(item.key)}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                <div key={scene} className="pms-scene">
                    {scene === "look" && (
                        <>
                            {lookTiles}
                            <Row gutter={[16, 16]}>
                                <Col xs={24} lg={12}>
                                    <Card
                                        title="Rating spread"
                                        className="viz-card viz-card-link"
                                        onClick={() => open(reportPath("ratings"))}
                                    >
                                        <SpreadRing
                                            slices={bands}
                                            caption="rated"
                                            onOpen={open}
                                        />
                                    </Card>
                                </Col>
                                <Col xs={24} lg={12}>
                                    <Card
                                        className="viz-card viz-card-link"
                                        title="Rating trend"
                                        extra={<Typography.Text type="secondary">Average of every rated form</Typography.Text>}
                                        onClick={() => open(reportPath("ratings"))}
                                    >
                                        <TrendLine
                                            points={trend}
                                            height={chartHeight.trend}
                                            min={0}
                                            max={5}
                                            format={(value) => Number(value).toFixed(2)}
                                            emptyText="No period has been rated yet."
                                            onSelect={(datum) => open(datum?.href || reportPath("ratings"))}
                                        />
                                    </Card>
                                </Col>
                            </Row>
                        </>
                    )}

                    {scene === "flow" && (
                        <>
                            {flowTiles}
                            <Row gutter={[16, 16]}>
                                <Col xs={24} lg={12}>
                                    <Card
                                        title="Where the forms are"
                                        className="viz-card viz-card-link pms-forms-where"
                                        onClick={() => open(reportPath("forms"))}
                                    >
                                        <SpreadRing slices={pipeline} caption="forms" onOpen={open} />
                                    </Card>
                                </Col>
                                <Col xs={24} lg={12}>{waitingCard}</Col>
                            </Row>
                        </>
                    )}

                    {scene === "people" && (
                        <>
                            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                                <Col span={24}>
                                    <Card
                                        title="How each unit is tracking"
                                        className="viz-card viz-card-link"
                                        onClick={() => open(reportPath("units"))}
                                    >
                                        <StackedColumns
                                            rows={unitRows}
                                            series={stages}
                                            height={chartHeight.columns}
                                            emptyText="No unit has opened a form yet."
                                            onSelect={(datum) => {
                                                if (!datum?.unit && !datum?.label) {
                                                    open(reportPath("units"));
                                                    return;
                                                }

                                                const unit = datum.unit ?? datum.label;
                                                const row = unitRows.find((item) => item.label === unit);

                                                open(row?.href ? row : reportPath("units"));
                                            }}
                                        />
                                    </Card>
                                </Col>
                            </Row>
                            <Row gutter={[16, 16]}>
                                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card viz-card-link"
                        onClick={() => open(reportPath("people"))}
                        title={
                            <Space size={6}>
                                <RiseOutlined /> Furthest along
                            </Space>
                        }
                        extra={<Typography.Text type="secondary">by individual</Typography.Text>}
                    >
                        <BarRows
                            data={movers}
                            max={100}
                            format={(value) => `${value}%`}
                            emptyText="Nobody has recorded progress yet."
                            onSelect={open}
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card viz-card-link"
                        onClick={() => open(latePath())}
                        title={
                            <Space size={6}>
                                <WarningOutlined /> Running late
                            </Space>
                        }
                        extra={<Typography.Text type="secondary">overdue lines</Typography.Text>}
                    >
                        <BarRows
                            data={laggards}
                            color={viz.critical}
                            emptyText="Nobody is past a target date."
                            onSelect={open}
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card viz-card-link"
                        onClick={() => open(reportPath("ratings"))}
                        title={
                            <Space size={6}>
                                <TrophyOutlined /> Best rated
                            </Space>
                        }
                        extra={<Typography.Text type="secondary">final average</Typography.Text>}
                    >
                        <BarRows
                            data={topRated}
                            max={5}
                            format={(value) => Number(value).toFixed(2)}
                            emptyText="Nothing has been rated yet."
                            onSelect={open}
                        />
                    </Card>
                                </Col>
                            </Row>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
