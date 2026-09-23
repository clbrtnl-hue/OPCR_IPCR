import React, { useEffect, useMemo, useState } from "react";
import {
    Button,
    Card,
    Dropdown,
    Empty,
    Input,
    Progress,
    Segmented,
    Select,
    Space,
    Switch,
    Table,
    Tag,
    Tooltip,
    Typography,
    message,
} from "antd";
import { DownOutlined, FileExcelOutlined, FilePdfOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { downloadFile, openPdf } from "~/utils/download";
import { ADJECTIVAL_COLORS, SECTION_LABELS, STATUS_META, VIZ } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";

const TABS = [
    { value: "units", label: "By unit" },
    { value: "heads", label: "By head" },
    { value: "people", label: "By individual" },
    { value: "forms", label: "Forms" },
    { value: "commitments", label: "Commitments" },
    { value: "ratings", label: "Ratings" },
];

const score = (value) => (value == null ? "—" : Number(value).toFixed(2));

export default function ReportsPage() {
    const navigate = useNavigate();
    const [yearId, setYearId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [tab, setTab] = useState("units");
    const [search, setSearch] = useState("");
    const [lateOnly, setLateOnly] = useState(false);
    const [busy, setBusy] = useState(false);

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    useEffect(() => {
        if (!yearId && years.length > 0) {
            setYearId((years.find((y) => y.is_active) ?? years[0]).id);
        }
    }, [years, yearId]);

    const year = years.find((y) => y.id === yearId);
    const period = year?.periods?.find((p) => p.id === periodId);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["report-summary", yearId, periodId],
        queryFn: () =>
            api
                .get("reports/summary", {
                    params: { school_year_id: yearId, rating_period_id: periodId ?? undefined },
                })
                .then((r) => r.data),
        enabled: Boolean(yearId),
        placeholderData: (previous) => previous,
    });

    const totals = data?.totals;

    const matches = (row, fields) => {
        const term = search.trim().toLowerCase();

        if (!term) return true;

        return fields.some((field) => String(row[field] ?? "").toLowerCase().includes(term));
    };

    const rows = useMemo(() => {
        if (!data) return [];

        if (tab === "units") return data.units.filter((row) => matches(row, ["name", "code"]));

        if (tab === "heads") return data.heads.filter((row) => matches(row, ["head", "unit", "unit_code", "position"]));

        if (tab === "people")
            return data.people.filter((row) => matches(row, ["name", "unit", "position", "adjectival"]));

        if (tab === "forms") return data.forms.filter((row) => matches(row, ["owner", "unit", "type", "status"]));

        if (tab === "ratings")
            return data.forms
                .filter((row) => row.average != null)
                .filter((row) => matches(row, ["owner", "unit", "type", "adjectival"]));

        return data.commitments
            .filter((row) => (lateOnly ? row.is_overdue : true))
            .filter((row) => matches(row, ["description", "owner", "unit", "section"]));
    }, [data, tab, search, lateOnly]);

    const download = async (kind) => {
        setBusy(true);

        try {
            const stamp = [year?.label, period?.label].filter(Boolean).join(" ");
            const params = new URLSearchParams({ school_year_id: yearId });

            if (periodId) params.set("rating_period_id", periodId);

            if (kind === "pdf") {
                await openPdf(`reports/summary/pdf?${params}`, `OCC PMS Report ${stamp}.pdf`.trim());
            } else {
                params.set("table", tab);
                params.set("format", kind);

                const name = `OCC PMS ${TABS.find((t) => t.value === tab).label} ${stamp}`.trim();

                await downloadFile(
                    `reports/summary/export?${params}`,
                    `${name}.${kind}`,
                    kind === "xlsx"
                        ? "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        : "text/csv"
                );
            }
        } catch {
            message.error("The export could not be prepared.");
        } finally {
            setBusy(false);
        }
    };

    const dimensionsByUnit = React.useMemo(
        () => Object.fromEntries((data?.dimensions?.units ?? []).map((unit) => [unit.id, unit])),
        [data]
    );

    const unitColumns = [
        { title: "Unit", dataIndex: "name", sorter: (a, b) => a.name.localeCompare(b.name) },
        { title: "Code", dataIndex: "code", render: (v) => v || "—" },
        {
            title: "Level",
            dataIndex: "type",
            filters: [
                { text: "College", value: "college" },
                { text: "Office", value: "office" },
                { text: "Program", value: "program" },
            ],
            onFilter: (value, record) => record.type === value,
            render: (value, row) =>
                row.parent_name ? (
                    <Tooltip title={`Under ${row.parent_name}`}>
                        <span style={{ textTransform: "capitalize" }}>{value ?? "—"}</span>
                    </Tooltip>
                ) : (
                    <span style={{ textTransform: "capitalize" }}>{value ?? "—"}</span>
                ),
        },
        { title: "Forms", dataIndex: "total_forms", align: "right", sorter: (a, b) => a.total_forms - b.total_forms },
        {
            title: "Submitted",
            key: "submitted",
            render: (_, row) => `${row.submitted} / ${row.total_forms}`,
        },
        { title: "Rated", dataIndex: "rated", align: "right" },
        { title: "Commitments", dataIndex: "commitments", align: "right" },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            sorter: (a, b) => (a.progress_pct ?? 0) - (b.progress_pct ?? 0),
            render: (value) =>
                value == null ? (
                    "—"
                ) : (
                    <Progress
                        percent={value}
                        size="small"
                        style={{ width: 120 }}
                        strokeColor={value >= 100 ? VIZ.good : VIZ.series[0]}
                    />
                ),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            sorter: (a, b) => a.overdue - b.overdue,
            render: (value) => (value > 0 ? <Tag color="error">{value}</Tag> : "—"),
        },
        {
            title: "Average",
            dataIndex: "average",
            align: "right",
            sorter: (a, b) => (a.average ?? 0) - (b.average ?? 0),
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: (value) =>
                value ? <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag> : <Tag>Not yet rated</Tag>,
        },
        ...(data?.dimensions?.keys ?? []).map((key) => ({
            title: data.dimensions.college.find((d) => d.key === key)?.label ?? key.toUpperCase(),
            key,
            align: "right",
            sorter: (a, b) =>
                (dimensionsByUnit[a.id]?.scores?.[key] ?? 0) - (dimensionsByUnit[b.id]?.scores?.[key] ?? 0),
            render: (_, row) => score(dimensionsByUnit[row.id]?.scores?.[key]),
        })),
    ];

    const peopleColumns = [
        { title: "Name", dataIndex: "name", sorter: (a, b) => a.name.localeCompare(b.name) },
        { title: "Position", dataIndex: "position", render: (v) => v || "—" },
        { title: "Unit", dataIndex: "unit", sorter: (a, b) => (a.unit ?? "").localeCompare(b.unit ?? "") },
        { title: "Forms", dataIndex: "forms", align: "right" },
        {
            title: "Commitments",
            dataIndex: "commitments",
            align: "right",
            sorter: (a, b) => a.commitments - b.commitments,
            render: (value, row) => `${row.completed} / ${value}`,
        },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            sorter: (a, b) => (a.progress_pct ?? 0) - (b.progress_pct ?? 0),
            render: (value) =>
                value == null ? (
                    "—"
                ) : (
                    <Progress
                        percent={value}
                        size="small"
                        style={{ width: 110 }}
                        strokeColor={value >= 100 ? VIZ.good : VIZ.series[0]}
                    />
                ),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            defaultSortOrder: "descend",
            sorter: (a, b) => a.overdue - b.overdue || a.days_late - b.days_late,
            render: (value, row) =>
                value > 0 ? (
                    <Tooltip title={`${row.days_late} day(s) late in total`}>
                        <Tag color="error">{value}</Tag>
                    </Tooltip>
                ) : (
                    "—"
                ),
        },
        {
            title: "Average",
            dataIndex: "average",
            align: "right",
            sorter: (a, b) => (a.average ?? 0) - (b.average ?? 0),
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: (value) =>
                value ? <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag> : <Tag>Not yet rated</Tag>,
        },
    ];

    const headColumns = [
        { title: "Head", dataIndex: "head", sorter: (a, b) => (a.head ?? "").localeCompare(b.head ?? "") },
        { title: "Position", dataIndex: "position", render: (v) => v || "—" },
        { title: "Unit", dataIndex: "unit" },
        { title: "Forms", dataIndex: "forms", align: "right" },
        {
            title: "Submitted",
            key: "submitted",
            render: (_, row) => `${row.submitted} / ${row.forms}`,
        },
        { title: "Rated", dataIndex: "rated", align: "right" },
        { title: "Commitments", dataIndex: "commitments", align: "right" },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            sorter: (a, b) => (a.progress_pct ?? 0) - (b.progress_pct ?? 0),
            render: (value) =>
                value == null ? (
                    "—"
                ) : (
                    <Progress
                        percent={value}
                        size="small"
                        style={{ width: 110 }}
                        strokeColor={value >= 100 ? VIZ.good : VIZ.series[0]}
                    />
                ),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            sorter: (a, b) => a.overdue - b.overdue,
            render: (value) => (value > 0 ? <Tag color="error">{value}</Tag> : "—"),
        },
        {
            title: "Average",
            dataIndex: "average",
            align: "right",
            sorter: (a, b) => (a.average ?? 0) - (b.average ?? 0),
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: (value) =>
                value ? <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag> : <Tag>Not yet rated</Tag>,
        },
    ];

    const formColumns = [
        {
            title: "Type",
            dataIndex: "type",
            filters: [
                { text: "IPCR", value: "IPCR" },
                { text: "OPCR", value: "OPCR" },
            ],
            onFilter: (value, row) => row.type === value,
            render: (value) => <Tag>{value}</Tag>,
        },
        { title: "Owner", dataIndex: "owner", sorter: (a, b) => (a.owner ?? "").localeCompare(b.owner ?? "") },
        { title: "Unit", dataIndex: "unit", sorter: (a, b) => (a.unit ?? "").localeCompare(b.unit ?? "") },
        {
            title: "Status",
            dataIndex: "status",
            filters: Object.entries(STATUS_META).map(([value, meta]) => ({ text: meta.label, value })),
            onFilter: (value, row) => row.status === value,
            render: (value) => (
                <Tooltip title={STATUS_META[value]?.who}>
                    <Tag color={STATUS_META[value]?.color}>{STATUS_META[value]?.label ?? value}</Tag>
                </Tooltip>
            ),
        },
        {
            title: "Submitted",
            dataIndex: "submitted_at",
            render: (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—"),
            sorter: (a, b) => (a.submitted_at ?? "").localeCompare(b.submitted_at ?? ""),
        },
        { title: "Lines", dataIndex: "commitments", align: "right" },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            sorter: (a, b) => (a.progress_pct ?? 0) - (b.progress_pct ?? 0),
            render: (value) =>
                value == null ? (
                    "—"
                ) : (
                    <Progress
                        percent={value}
                        size="small"
                        style={{ width: 110 }}
                        strokeColor={value >= 100 ? VIZ.good : VIZ.series[0]}
                    />
                ),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            render: (value) => (value > 0 ? <Tag color="error">{value}</Tag> : "—"),
        },
        {
            title: "Final",
            dataIndex: "average",
            align: "right",
            sorter: (a, b) => (a.average ?? 0) - (b.average ?? 0),
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: (value) =>
                value ? <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag> : <Tag>Not yet rated</Tag>,
        },
    ];

    const ratingColumns = [
        { title: "Type", dataIndex: "type", render: (value) => <Tag>{value}</Tag> },
        { title: "Ratee", dataIndex: "owner" },
        { title: "Unit", dataIndex: "unit" },
        { title: "Strategic", dataIndex: "strategic", align: "right", render: score },
        { title: "Core", dataIndex: "core", align: "right", render: score },
        { title: "Support", dataIndex: "support", align: "right", render: score },
        {
            title: "Final",
            dataIndex: "average",
            align: "right",
            defaultSortOrder: "descend",
            sorter: (a, b) => (a.average ?? 0) - (b.average ?? 0),
            render: (value) => <strong>{score(value)}</strong>,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            filters: Object.keys(ADJECTIVAL_COLORS).map((value) => ({ text: value, value })),
            onFilter: (value, row) => row.adjectival === value,
            render: (value) => <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag>,
        },
        {
            title: "Rated on",
            dataIndex: "rated_at",
            render: (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—"),
        },
    ];

    const commitmentColumns = [
        {
            title: "Success indicator",
            dataIndex: "description",
            width: 320,
            render: (value) => (
                <Typography.Text ellipsis={{ tooltip: value }} style={{ maxWidth: 300 }}>
                    {value || "Untitled"}
                </Typography.Text>
            ),
        },
        { title: "Owner", dataIndex: "owner" },
        { title: "Unit", dataIndex: "unit" },
        {
            title: "Section",
            dataIndex: "section",
            filters: Object.entries(SECTION_LABELS).map(([value, text]) => ({ text, value })),
            onFilter: (value, row) => row.section === value,
            render: (value) => SECTION_LABELS[value] ?? value,
        },
        {
            title: "Target date",
            dataIndex: "target_date",
            sorter: (a, b) => (a.target_date ?? "").localeCompare(b.target_date ?? ""),
            render: (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—"),
        },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            sorter: (a, b) => a.progress_pct - b.progress_pct,
            render: (value, row) => (
                <Progress
                    percent={value}
                    size="small"
                    style={{ width: 110 }}
                    strokeColor={row.progress_status === "completed" ? VIZ.good : VIZ.series[0]}
                />
            ),
        },
        {
            title: "Standing",
            key: "standing",
            render: (_, row) =>
                row.is_overdue ? (
                    <Tag color="error">{row.days_late} day{row.days_late === 1 ? "" : "s"} late</Tag>
                ) : row.due_soon ? (
                    <Tag color="warning">Due soon</Tag>
                ) : row.progress_status === "completed" ? (
                    <Tag color="success">Completed</Tag>
                ) : (
                    <Tag>On track</Tag>
                ),
        },
    ];

    const table = {
        units: { columns: unitColumns, rowKey: "id", scroll: 1500 },
        heads: { columns: headColumns, rowKey: "id", scroll: 1300 },
        people: { columns: peopleColumns, rowKey: "id", scroll: 1200 },
        forms: { columns: formColumns, rowKey: "id", scroll: 1300 },
        ratings: { columns: ratingColumns, rowKey: "id", scroll: 1000 },
        commitments: { columns: commitmentColumns, rowKey: "id", scroll: 1500 },
    }[tab];

    return (
        <>
            <PageHeader
                title="Reports"
                subtitle="Every form, unit and commitment in the cycle — filter it, then take it away as Excel or PDF."
                extra={
                    <Space wrap>
                        <Select
                            style={{ minWidth: 180 }}
                            value={yearId}
                            onChange={(value) => {
                                setYearId(value);
                                setPeriodId(null);
                            }}
                            options={years.map((y) => ({
                                value: y.id,
                                label: y.is_active ? `${y.label} (active)` : y.label,
                            }))}
                        />
                        {year?.periods?.length > 0 && (
                            <Segmented
                                value={periodId ?? "all"}
                                onChange={(v) => setPeriodId(v === "all" ? null : v)}
                                options={[
                                    { value: "all", label: "Whole year" },
                                    ...year.periods.map((p) => ({ value: p.id, label: p.label })),
                                ]}
                            />
                        )}
                    </Space>
                }
            />

            {!totals ? (
                <Card loading={isLoading}>
                    <Empty description="Pick a school year to see its report." />
                </Card>
            ) : (
                <Card
                    title={
                        <Segmented
                            value={tab}
                            onChange={(value) => {
                                setTab(value);
                                setSearch("");
                            }}
                            options={TABS}
                        />
                    }
                    extra={
                        <Space wrap>
                            <Input.Search
                                allowClear
                                placeholder="Search this table"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                style={{ width: 220 }}
                            />
                            {tab === "commitments" && (
                                <Space size={6}>
                                    <Switch size="small" checked={lateOnly} onChange={setLateOnly} />
                                    <Typography.Text type="secondary">Late only</Typography.Text>
                                </Space>
                            )}
                            <Dropdown.Button
                                icon={<DownOutlined />}
                                loading={busy}
                                onClick={() => download("xlsx")}
                                menu={{
                                    items: [{ key: "csv", label: "Download as CSV" }],
                                    onClick: () => download("csv"),
                                }}
                            >
                                <FileExcelOutlined /> Excel
                            </Dropdown.Button>
                            <Button icon={<FilePdfOutlined />} loading={busy} onClick={() => download("pdf")}>
                                PDF
                            </Button>
                        </Space>
                    }
                >
                    <Typography.Paragraph type="secondary" style={{ marginTop: -4 }}>
                        {year?.label} · {period?.label ?? "whole year"} — {totals.forms} forms, {totals.submitted}{" "}
                        submitted, {totals.rated} rated · {totals.commitments} commitments at{" "}
                        {totals.progress_pct ?? 0}% · {totals.overdue} overdue
                    </Typography.Paragraph>

                    <Table
                        rowKey={table.rowKey}
                        dataSource={rows}
                        columns={table.columns}
                        loading={isFetching}
                        size="small"
                        pagination={{ pageSize: 20, showSizeChanger: true, showTotal: (t) => `${t} rows` }}
                        scroll={{ x: table.scroll }}
                        onRow={(row) =>
                            tab === "forms" || tab === "ratings"
                                ? { style: { cursor: "pointer" }, onClick: () => navigate(`/forms/${row.id}`) }
                                : {}
                        }
                    />
                </Card>
            )}
        </>
    );
}
