import React from "react";
import { Card, Col, Empty, List, Row, Space, Tag, Typography } from "antd";
import {
    CalendarOutlined,
    ClockCircleOutlined,
    FileExclamationOutlined,
    HourglassOutlined,
    RiseOutlined,
    TrophyOutlined,
    WarningOutlined,
} from "@ant-design/icons";
import dayjs from "dayjs";
import BarRows from "~/components/charts/BarRows";
import StackedBars from "~/components/charts/StackedBars";
import TrendLine from "~/components/charts/TrendLine";
import StatTile, { Meter } from "~/components/charts/StatTile";
import { ADJECTIVAL_COLORS, RATING_LEGEND, SECTION_LABELS, STATUS_META, VIZ } from "~/utils/constants";

const STAGES = [
    { key: "drafting", label: "Drafting", color: VIZ.ordinal[0] },
    { key: "submitted", label: "Submitted", color: VIZ.ordinal[2] },
    { key: "rated", label: "Rated", color: VIZ.ordinal[4] },
];

export default function InsightsBoard({ data }) {
    const totals = data?.totals;

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
            hint: `${count} form${count === 1 ? "" : "s"} — ${STATUS_META[status]?.who ?? ""}`,
        }));

    const bands = RATING_LEGEND.map((band) => ({
        key: band.label,
        label: `${band.value} — ${band.label}`,
        value: data.adjectival?.[band.label] ?? 0,
        color: VIZ.ordinal[band.value - 1],
        hint: `${data.adjectival?.[band.label] ?? 0} form(s) rated ${band.label} (${band.range})`,
    }));

    const trend = (data.trend ?? []).map((point) => ({
        label: point.short ?? point.label,
        hint: point.label,
        value: point.average,
    }));

    const submissions = (data.submissions ?? []).map((month) => ({
        label: month.label,
        hint: month.full_label,
        value: month.cumulative,
    }));

    const monthly = (data.submissions ?? []).map((month) => ({
        key: month.month,
        label: month.label,
        value: month.submitted,
        color: month.month === data.deadlines?.active?.month ? VIZ.warning : VIZ.series[0],
        hint:
            month.month === data.deadlines?.active?.month
                ? `${month.full_label} — ${month.submitted} filed · the period closes this month`
                : `${month.full_label} — ${month.submitted} filed`,
    }));

    const turnaround = (data.turnaround?.stages ?? [])
        .filter((stage) => stage.key !== "end_to_end" && stage.median_days !== null)
        .map((stage, index) => ({
            key: stage.key,
            label: stage.label,
            value: stage.median_days,
            color: VIZ.ordinal[index * 2],
            hint: `${stage.label} — median ${stage.median_days}d, mean ${stage.mean_days}d across ${stage.forms} form${
                stage.forms === 1 ? "" : "s"
            }${stage.skipped ? ` · ${stage.skipped} skipped this stage` : ""}`,
        }));

    const endToEnd = (data.turnaround?.stages ?? []).find((stage) => stage.key === "end_to_end");

    const waiting = (data.turnaround?.waiting ?? [])
        .filter((row) => row.forms > 0)
        .map((row) => ({
            key: row.status,
            label: row.label,
            value: row.median_age_days ?? 0,
            color: VIZ.warning,
            hint: `${row.forms} form${row.forms === 1 ? "" : "s"} waiting — median ${
                row.median_age_days ?? 0
            }d, oldest ${row.oldest_days ?? 0}d`,
        }));

    const dimensions = (data.dimensions?.college ?? []).map((dimension, index) => ({
        key: dimension.key,
        label: dimension.label,
        value: dimension.mean ?? 0,
        color: VIZ.series[index % VIZ.series.length],
        hint: `${dimension.label} — ${dimension.mean ?? "not yet rated"} across ${dimension.rated} rated line${
            dimension.rated === 1 ? "" : "s"
        }`,
    }));

    const weakest = dimensions.length
        ? dimensions.reduce((low, row) => (row.value < low.value ? row : low))
        : null;

    const evidenceGaps = (data.evidence?.units ?? [])
        .filter((unit) => unit.missing_evidence > 0 || unit.blank_narrative > 0)
        .map((unit) => ({
            key: unit.id,
            label: unit.name,
            value: unit.completed - unit.compliant,
            color: VIZ.serious,
            hint: `${unit.name} — ${unit.missing_evidence} without a file, ${unit.blank_narrative} without a narrative, of ${unit.completed} completed`,
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
        }));

    const overdueTone = totals.overdue > 0 ? VIZ.critical : VIZ.good;

    const people = data.people ?? [];

    const rank = (rows, value, hint, limit = 6, keepZero = false) =>
        rows
            .filter((row) => value(row) != null && (keepZero || value(row) > 0))
            .sort((a, b) => value(b) - value(a))
            .slice(0, limit)
            .map((row) => ({
                key: row.id,
                label: row.name ?? row.head,
                value: value(row),
                hint: hint(row),
            }));

    const laggards = rank(
        people,
        (person) => person.overdue,
        (person) =>
            `${person.name} — ${person.overdue} line${person.overdue === 1 ? "" : "s"} past target, ${person.days_late} day${
                person.days_late === 1 ? "" : "s"
            } late in total`
    );

    const movers = rank(
        people,
        (person) => person.progress_pct,
        (person) =>
            `${person.name} — ${person.completed} of ${person.commitments} lines done (${person.progress_pct}%)`
    );

    const topRated = rank(
        people,
        (person) => person.average,
        (person) => `${person.name} — ${Number(person.average).toFixed(2)} · ${person.adjectival ?? ""}`
    );

    const headProgress = rank(
        data.heads ?? [],
        (head) => head.progress_pct,
        (head) =>
            `${head.head} · ${head.unit} — ${head.progress_pct}% across ${head.commitments} line${
                head.commitments === 1 ? "" : "s"
            }, ${head.overdue} overdue`,
        10,
        true
    );

    const tiles = (
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
            <Col xs={12} lg={6}>
                <StatTile
                    label="College average"
                    value={totals.college_average != null ? Number(totals.college_average).toFixed(2) : "—"}
                    suffix={
                        totals.college_rating ? (
                            <Tag color={ADJECTIVAL_COLORS[totals.college_rating]} style={{ marginLeft: 8 }}>
                                {totals.college_rating}
                            </Tag>
                        ) : null
                    }
                    hint={`${totals.rated} of ${totals.forms} forms rated so far`}
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label="Commitments completed"
                    value={totals.progress_pct != null ? `${totals.progress_pct}%` : "—"}
                    meter={{ pct: totals.progress_pct ?? 0 }}
                    hint={`${totals.completed} of ${totals.commitments} committed lines are done`}
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label="Forms submitted"
                    value={`${totals.submitted}/${totals.forms}`}
                    meter={{ pct: totals.forms ? (totals.submitted / totals.forms) * 100 : 0 }}
                    hint={
                        coverage.expected
                            ? `${coverage.filed} of ${coverage.expected} staff have opened an IPCR (${coverage.pct}%)`
                            : undefined
                    }
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label={
                        <Space size={4}>
                            <WarningOutlined /> Overdue commitments
                        </Space>
                    }
                    value={totals.overdue}
                    tone={overdueTone}
                    hint={`${totals.due_soon} more fall due within seven days`}
                />
            </Col>

            <Col xs={12} lg={6}>
                <StatTile
                    label="Waiting for QA"
                    value={totals.awaiting_rating}
                    hint={`${totals.in_review} still with a head or VP`}
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label="Returned for correction"
                    value={totals.returned}
                    tone={totals.returned > 0 ? VIZ.serious : undefined}
                    hint="Sent back by a reviewer and not yet resubmitted"
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label="Due within seven days"
                    value={totals.due_soon}
                    tone={totals.due_soon > 0 ? VIZ.warning : undefined}
                    hint={`${totals.not_started} lines have not been started at all`}
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label="Staff without an IPCR"
                    value={coverage.missing ?? 0}
                    tone={(coverage.missing ?? 0) > 0 ? VIZ.serious : VIZ.good}
                    hint={`${totals.units_reporting} of ${totals.units_total} units have opened a form`}
                />
            </Col>

            <Col xs={12} lg={6}>
                <StatTile
                    label="Submitted → rated"
                    value={endToEnd?.median_days != null ? `${endToEnd.median_days}d` : "—"}
                    hint={
                        endToEnd?.forms
                            ? `Median across ${endToEnd.forms} rated form${endToEnd.forms === 1 ? "" : "s"}`
                            : "Nothing has gone all the way through yet"
                    }
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label={weakest ? `Weakest: ${weakest.label}` : "Rating profile"}
                    value={weakest ? Number(weakest.value).toFixed(2) : "—"}
                    hint={
                        data.dimensions?.rated_lines
                            ? `Lowest of the ${data.dimensions.keys.length} rated dimensions, across ${data.dimensions.rated_lines} lines`
                            : "Nothing has been rated yet"
                    }
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label={
                        <Space size={4}>
                            <FileExclamationOutlined /> Completed without evidence
                        </Space>
                    }
                    value={data.evidence?.missing_evidence ?? 0}
                    tone={(data.evidence?.missing_evidence ?? 0) > 0 ? VIZ.serious : VIZ.good}
                    hint={`${data.evidence?.blank_narrative ?? 0} more have no written accomplishment · ${
                        data.evidence?.pct ?? 0
                    }% of completed work is fully documented`}
                />
            </Col>
            <Col xs={12} lg={6}>
                <StatTile
                    label={
                        <Space size={4}>
                            <CalendarOutlined /> Filed after the deadline
                        </Space>
                    }
                    value={data.deadlines?.late?.forms ?? 0}
                    tone={(data.deadlines?.late?.forms ?? 0) > 0 ? VIZ.warning : VIZ.good}
                    hint={
                        data.deadlines?.active?.closes_at
                            ? `${data.deadlines.active.label} closes ${dayjs(data.deadlines.active.closes_at).format(
                                  "MMM D, YYYY"
                              )} · ${data.deadlines.late?.judged ?? 0} forms judged against it`
                            : "No period has a closing date set"
                    }
                />
            </Col>
        </Row>
    );

    const turnaroundCard = (
        <Card
            className="viz-card"
            title={
                <Space size={6}>
                    <HourglassOutlined /> Where forms wait
                </Space>
            }
            extra={<Typography.Text type="secondary">Median days at each stage</Typography.Text>}
        >
            <BarRows
                data={turnaround}
                format={(value) => `${value}d`}
                emptyText="No form has been through a review stage yet."
            />
        </Card>
    );

    const waitingCard = (
        <Card
            className="viz-card"
            title="Sitting in a queue right now"
            extra={<Typography.Text type="secondary">Median age</Typography.Text>}
        >
            <BarRows
                data={waiting}
                format={(value) => `${value}d`}
                emptyText="Nothing is waiting on a reviewer."
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

    const dimensionsCard = (
        <Card
            className="viz-card"
            title="Quality, efficiency, timeliness"
            extra={
                <Typography.Text type="secondary">
                    {data.dimensions?.rated_lines
                        ? `${data.dimensions.rated_lines} rated lines`
                        : "the rating instrument"}
                </Typography.Text>
            }
        >
            <BarRows
                data={dimensions}
                max={data.dimensions?.bounds?.max ?? 5}
                format={(value) => Number(value).toFixed(2)}
                emptyText="Nothing has been rated yet."
            />
        </Card>
    );

    const evidenceCard = (
        <Card
            className="viz-card"
            title={
                <Space size={6}>
                    <FileExclamationOutlined /> Completed work missing its paperwork
                </Space>
            }
            extra={<Typography.Text type="secondary">by unit</Typography.Text>}
        >
            <BarRows
                data={evidenceGaps}
                emptyText="Every completed commitment has a file and a written accomplishment."
            />
        </Card>
    );

    const velocityCard = (
        <Card
            className="viz-card"
            title="Filed each month"
            extra={
                <Typography.Text type="secondary">
                    {data.deadlines?.active?.closes_at
                        ? `${data.deadlines.active.label} closes ${dayjs(
                              data.deadlines.active.closes_at
                          ).format("MMM D")}`
                        : "no deadline set"}
                </Typography.Text>
            }
        >
            <BarRows data={monthly} emptyText="Nothing has been submitted yet." />
        </Card>
    );

    const atRisk = (
        <Card
            className="viz-card"
            title={
                <Space size={6}>
                    <ClockCircleOutlined /> Commitments at risk
                </Space>
            }
        >
            {(data.at_risk ?? []).length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing is past its target date." />
            ) : (
                <List
                    size="small"
                    dataSource={data.at_risk}
                    renderItem={(line) => (
                        <List.Item
                            actions={[
                                <Tag key="late" color="error">
                                    {line.days_late} day{line.days_late === 1 ? "" : "s"} late
                                </Tag>,
                            ]}
                        >
                            <List.Item.Meta
                                title={
                                    <Typography.Text ellipsis={{ tooltip: line.description }}>
                                        {line.description || "Untitled commitment"}
                                    </Typography.Text>
                                }
                                description={`${line.owner ?? line.unit ?? "Unassigned"} · due ${
                                    line.target_date ? dayjs(line.target_date).format("MMM D, YYYY") : "—"
                                } · ${line.progress_pct}% done`}
                            />
                        </List.Item>
                    )}
                />
            )}
        </Card>
    );

    const sectionMeters = (
        <Space direction="vertical" size={14} style={{ width: "100%" }}>
            {(data.sections ?? []).map((section) => (
                <Meter
                    key={section.section}
                    pct={section.progress_pct ?? 0}
                    label={`${SECTION_LABELS[section.section] ?? section.section} — ${section.commitments} line${
                        section.commitments === 1 ? "" : "s"
                    }`}
                    value={
                        section.progress_pct != null
                            ? `${section.progress_pct}%${
                                  section.average != null
                                      ? ` · rated ${Number(section.average).toFixed(2)}`
                                      : ""
                              }`
                            : "—"
                    }
                />
            ))}
        </Space>
    );

    return (
        <>
            {tiles}

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={8}>
                    {turnaroundCard}
                </Col>
                <Col xs={24} lg={8}>
                    {waitingCard}
                </Col>
                <Col xs={24} lg={8}>
                    {dimensionsCard}
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={12}>
                    {velocityCard}
                </Col>
                <Col xs={24} lg={12}>
                    {evidenceCard}
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={12}>
                    <Card className="viz-card" title="Rating trend" extra={<Typography.Text type="secondary">Average of every rated form</Typography.Text>}>
                        <TrendLine
                            points={trend}
                            min={0}
                            max={5}
                            format={(value) => Number(value).toFixed(2)}
                            emptyText="No period has been rated yet."
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={12}>
                    <Card title="Rating spread" className="viz-card">
                        <BarRows data={bands} emptyText="Nothing has been rated yet." />
                    </Card>
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={12}>
                    <Card title="Where the forms are" className="viz-card">
                        <BarRows data={pipeline} emptyText="No forms in this cycle yet." />
                    </Card>
                </Col>
                <Col xs={24} lg={12}>
                    <Card className="viz-card" title="Forms submitted through the year" extra={<Typography.Text type="secondary">Running total</Typography.Text>}>
                        <TrendLine
                            points={submissions}
                            format={(value) => Math.round(value)}
                            emptyText="Nothing has been submitted yet."
                        />
                    </Card>
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={14}>
                    <Card title="How each unit is tracking" className="viz-card">
                        <StackedBars rows={unitRows} series={STAGES} emptyText="No unit has opened a form yet." />
                    </Card>
                </Col>
                <Col xs={24} lg={10}>
                    <Card title="Commitments by section" className="viz-card">
                        {sectionMeters}
                    </Card>
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card"
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
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card"
                        title={
                            <Space size={6}>
                                <WarningOutlined /> Running late
                            </Space>
                        }
                        extra={<Typography.Text type="secondary">overdue lines</Typography.Text>}
                    >
                        <BarRows
                            data={laggards}
                            color={VIZ.critical}
                            emptyText="Nobody is past a target date."
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={8}>
                    <Card
                        className="viz-card"
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
                        />
                    </Card>
                </Col>
            </Row>

            <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
                <Col xs={24} lg={12}>
                    <Card
                        className="viz-card"
                        title="How each head's unit is tracking"
                        extra={<Typography.Text type="secondary">progress across the unit</Typography.Text>}
                    >
                        <BarRows
                            data={headProgress}
                            max={100}
                            format={(value) => `${value}%`}
                            emptyText="No head has any commitment recorded yet."
                        />
                    </Card>
                </Col>
                <Col xs={24} lg={12}>{atRisk}</Col>
            </Row>
        </>
    );
}
