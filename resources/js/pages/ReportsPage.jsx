import React, { useEffect, useMemo, useState } from "react";
import {
    Button,
    Card,
    Dropdown,
    Empty,
    Input,
    Popover,
    Progress,
    Select,
    Space,
    Table,
    Tabs,
    Tag,
    Tooltip,
    Typography,
    message,
} from "antd";
import {
    DownOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    FilterOutlined,
    PrinterOutlined,
} from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import ListPager, { tablePager, usePaged } from "~/components/ListPager";
import { useNavigate, useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { downloadFile, openPdf } from "~/utils/download";
import {
    ADJECTIVAL_COLORS,
    PROGRESS_META,
    RATING_LEGEND,
    SECTION_LABELS,
    STATUS_META,
    VIZ,
} from "~/utils/constants";
import { useAuth } from "~/hooks/useAuth";
import PageHeader from "~/components/PageHeader";

const COLLEGE_TABS = [
    { value: "units", label: "By unit" },
    { value: "heads", label: "By head" },
    { value: "vps", label: "By VP" },
    { value: "people", label: "By individual" },
    { value: "forms", label: "Forms" },
    { value: "commitments", label: "Commitments" },
    { value: "ratings", label: "Ratings" },
];

/** Heads and VPs get a team desk — not the college hierarchy views. */
const TEAM_TABS = [
    { value: "commitments", label: "Commitments" },
    { value: "people", label: "By individual" },
    { value: "forms", label: "Forms" },
    { value: "ratings", label: "Ratings" },
    { value: "units", label: "By unit" },
];

const TEAM_ROLES = new Set(["program_head", "vp"]);

function tabsFor(role) {
    return TEAM_ROLES.has(role) ? TEAM_TABS : COLLEGE_TABS;
}

function defaultTab(role, params) {
    if (params.has("late") || params.get("progress") || params.get("standing")) return "commitments";
    if (params.get("pace")) return "people";

    const next = params.get("tab");
    const allowed = tabsFor(role);

    if (next && allowed.some((item) => item.value === next)) return next;

    return TEAM_ROLES.has(role) ? "commitments" : "units";
}

const PROGRESS_PARAMS = new Set(["completed", "ongoing", "not_started", "deferred"]);
const STANDING_PARAMS = new Set(["late", "due_soon", "on_track", "ontime"]);
const PACE_PARAMS = new Set(["ahead", "idle", "behind"]);

function kindsFromParams(params) {
    const next = {};
    const commitments = {};
    const progress = params.get("progress");
    const standing = params.has("late") ? "late" : params.get("standing");

    if (progress && PROGRESS_PARAMS.has(progress)) commitments.progress = progress;
    if (standing && STANDING_PARAMS.has(standing)) commitments.standing = standing;
    if (Object.keys(commitments).length) next.commitments = commitments;

    const pace = params.get("pace");

    if (pace && PACE_PARAMS.has(pace)) next.people = { pace };

    const type = params.get("type");

    if (type) next.forms = { type: type.toUpperCase() };

    return next;
}

const score = (value) => (value == null ? "—" : Number(value).toFixed(2));
const emptyKind = {};

const LEVEL_ORDER = ["college", "office", "program"];
const LEVEL_LABELS = { college: "College", office: "Office", program: "Program" };
const TYPE_ORDER = ["IPCR", "OPCR"];
const RATING_ORDER = RATING_LEGEND.map((band) => band.label);
const STATUS_ORDER = Object.keys(STATUS_META);
const SECTION_ORDER = Object.keys(SECTION_LABELS);
const LINK_ORDER = ["linked", "unlinked"];
const LINK_LABELS = { linked: "Linked", unlinked: "Unlinked" };
const PROGRESS_ORDER = Object.keys(PROGRESS_META);
const STANDING_ORDER = ["late", "due_soon", "on_track"];
const STANDING_LABELS = { late: "Late", due_soon: "Due soon", on_track: "On track" };

function standingOf(row) {
    if (row.is_overdue) return "late";
    if (row.due_soon) return "due_soon";

    return "on_track";
}

function overdueStandingOf(row) {
    return Number(row.overdue) > 0 ? "late" : "on_track";
}

function linkOf(row) {
    return row.linked ? "linked" : "unlinked";
}

function unitOptions(rows) {
    return [...new Set(rows.map((row) => row.unit).filter(Boolean))].sort(compareText).map((value) => ({
        value,
        label: value,
    }));
}

function ratingField(rows, { includeUnrated = false } = {}) {
    const order = includeUnrated ? [...RATING_ORDER, "unrated"] : RATING_ORDER;
    const pick = (row) => (includeUnrated ? row.adjectival || "unrated" : row.adjectival);

    return {
        key: "adjectival",
        label: "Rating",
        all: "All ratings",
        pick,
        options: orderedChoices(rows, order, pick, (value) =>
            value === "unrated" ? "Not yet rated" : value
        ),
    };
}

function unitField(rows) {
    return {
        key: "unit",
        label: "Unit",
        all: "All units",
        pick: (row) => row.unit,
        options: unitOptions(rows),
    };
}

function overdueStandingField() {
    return {
        key: "standing",
        label: "Standing",
        all: "All standing",
        always: true,
        pick: overdueStandingOf,
        options: [
            { value: "late", label: "Late" },
            { value: "on_track", label: "On track" },
        ],
    };
}

function compareText(a, b) {
    return String(a ?? "").localeCompare(String(b ?? ""), undefined, {
        numeric: true,
        sensitivity: "base",
    });
}

const NAME_OF = {
    units: (row) => row.name,
    heads: (row) => row.head,
    vps: (row) => row.vp,
    people: (row) => row.name,
    forms: (row) => row.owner,
    ratings: (row) => row.owner,
    commitments: (row) => row.description,
};

const VALUE_OF = {
    units: (row) => row.average,
    heads: (row) => row.average,
    vps: (row) => row.average,
    people: (row) => row.average,
    forms: (row) => row.average,
    ratings: (row) => row.average,
    commitments: (row) => row.progress_pct,
};

function sortRows(list, tab, sort) {
    const nameOf = NAME_OF[tab];
    const valueOf = VALUE_OF[tab];

    return [...list].sort((a, b) => {
        if (sort === "value") {
            const av = valueOf(a);
            const bv = valueOf(b);
            const aMissing = av == null || av === "";
            const bMissing = bv == null || bv === "";

            if (aMissing !== bMissing) return aMissing ? 1 : -1;
            if (!aMissing && Number(bv) !== Number(av)) return Number(bv) - Number(av);
        }

        return compareText(nameOf(a), nameOf(b));
    });
}

function orderedChoices(rows, order, pick, labelOf) {
    const present = new Set(rows.map((row) => pick(row)).filter((value) => value != null && value !== ""));
    const known = order.filter((value) => present.has(value));
    const extra = [...present].filter((value) => !known.includes(value));

    return [...known, ...extra].map((value) => ({ value, label: labelOf(value) }));
}

function visibleKindFields(fields) {
    return fields.filter((field) => field.always || field.options.length >= 2);
}

function activeKindCount(fields, kind) {
    return visibleKindFields(fields).filter((field) => {
        const selected = kind[field.key] ?? "all";

        return selected !== "all" && field.options.some((item) => item.value === selected);
    }).length;
}

function ReportKinds({ fields, kind, onChange, sort, onSort }) {
    const visible = visibleKindFields(fields);
    const active = activeKindCount(fields, kind) + (sort !== "az" ? 1 : 0);

    const clear = () => {
        visible.forEach((field) => onChange(field.key, "all"));
        onSort("az");
    };

    return (
        <Popover
            trigger="click"
            placement="bottomRight"
            content={
                <div className="pms-report-kind-panel">
                    <label className="pms-report-kind-row">
                        <span>Sort</span>
                        <Select
                            aria-label="Sort"
                            value={sort}
                            onChange={onSort}
                            options={[
                                { value: "az", label: "A–Z" },
                                { value: "value", label: "Value" },
                            ]}
                            popupMatchSelectWidth={false}
                        />
                    </label>
                    {visible.map((field) => {
                        const selected = kind[field.key] ?? "all";
                        const current = field.options.some((item) => item.value === selected)
                            ? selected
                            : "all";

                        return (
                            <label key={field.key} className="pms-report-kind-row">
                                <span>{field.label}</span>
                                <Select
                                    value={current}
                                    onChange={(value) => onChange(field.key, value)}
                                    options={[{ value: "all", label: field.all }, ...field.options]}
                                    popupMatchSelectWidth={false}
                                />
                            </label>
                        );
                    })}
                    {active > 0 && (
                        <Button type="link" size="small" className="pms-report-kind-clear" onClick={clear}>
                            Clear filters
                        </Button>
                    )}
                </div>
            }
        >
            <Button icon={<FilterOutlined />}>
                Filters
                {active > 0 ? <em className="pms-report-kind-count">{active}</em> : null}
                <DownOutlined />
            </Button>
        </Popover>
    );
}

function progressBar(value, done = false) {
    if (value == null) return "—";

    return (
        <Progress
            percent={value}
            size="small"
            style={{ width: 110 }}
            strokeColor={done || value >= 100 ? VIZ.good : VIZ.series[0]}
        />
    );
}

function ReportExport({ busy, onDownload }) {
    return (
        <Dropdown
            menu={{
                items: [
                    { key: "pdf", icon: <FilePdfOutlined />, label: "PDF" },
                    { key: "xlsx", icon: <FileExcelOutlined />, label: "Excel", disabled: busy },
                ],
                onClick: ({ key }) => onDownload(key),
            }}
        >
            <Button icon={<PrinterOutlined />} loading={busy} aria-label="PDF or Excel">
                <span className="pms-btn-text">Print</span>
                <DownOutlined />
            </Button>
        </Dropdown>
    );
}

function ReportFilters({ search, onSearch, children }) {
    return (
        <>
            <Input.Search
                allowClear
                placeholder="Search"
                value={search}
                onChange={(e) => onSearch(e.target.value)}
            />
            {children}
        </>
    );
}

function ReportCards({ tab, rows, onOpen }) {
    const cardPage = usePaged(rows, 20);

    if (rows.length === 0) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing matches." />;
    }

    return (
        <div className="pms-paged">
        <div className="pms-report-cards">
            {cardPage.rows.map((row) => {
                const openable = tab === "forms" || tab === "ratings";
                const title =
                    tab === "units"
                        ? row.name
                        : tab === "heads"
                          ? row.head || "No head"
                          : tab === "vps"
                            ? row.vp || "No VP"
                            : tab === "commitments"
                              ? row.description || "Untitled"
                              : row.name || row.owner || "—";
                const meta =
                    tab === "units"
                        ? [row.code, row.type].filter(Boolean).join(" · ")
                        : tab === "heads" || tab === "vps"
                          ? [row.position, row.unit].filter(Boolean).join(" · ")
                          : [row.position, row.unit, row.owner].filter(Boolean).join(" · ");
                const pct = row.progress_pct;
                const rating = row.adjectival;

                return (
                    <button
                        key={row.id}
                        type="button"
                        className="pms-report-card"
                        disabled={!openable}
                        onClick={() => openable && onOpen(row.id)}
                    >
                        <span className="pms-report-card-top">
                            {row.type && <Tag>{row.type}</Tag>}
                            {rating ? (
                                <Tag color={ADJECTIVAL_COLORS[rating]}>{rating}</Tag>
                            ) : tab !== "commitments" ? (
                                <Tag>Not yet rated</Tag>
                            ) : null}
                            {row.status && STATUS_META[row.status] && (
                                <Tag color={STATUS_META[row.status].color}>{STATUS_META[row.status].label}</Tag>
                            )}
                            {row.is_overdue && <Tag color="error">Late</Tag>}
                            {tab === "commitments" && (
                                <Tag color={row.linked ? "blue" : undefined}>
                                    {row.linked ? "Linked" : "Unlinked"}
                                </Tag>
                            )}
                        </span>
                        <strong>{title}</strong>
                        {meta && <span>{meta}</span>}
                        {pct != null && (
                            <Progress
                                percent={pct}
                                size="small"
                                strokeColor={pct >= 100 ? VIZ.good : VIZ.series[0]}
                            />
                        )}
                        <span className="pms-report-card-stats">
                            {row.average != null && <em>{score(row.average)}</em>}
                            {row.overdue > 0 && <Tag color="error">{row.overdue} overdue</Tag>}
                            {row.total_forms != null && <span>{row.submitted ?? 0}/{row.total_forms} submitted</span>}
                        </span>
                    </button>
                );
            })}
        </div>
        <ListPager page={cardPage.page} pageSize={cardPage.pageSize} total={cardPage.total} onChange={cardPage.setPage} />
        </div>
    );
}

export default function ReportsPage() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const query = searchParams.toString();
    const teamReport = TEAM_ROLES.has(user?.role);
    const tabs = useMemo(() => tabsFor(user?.role), [user?.role]);
    const [yearId, setYearId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [tab, setTab] = useState(() => defaultTab(user?.role, searchParams));
    const [search, setSearch] = useState(() => searchParams.get("q") ?? "");
    const [sort, setSort] = useState("az");
    const [kindByTab, setKindByTab] = useState(() => kindsFromParams(searchParams));
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const params = new URLSearchParams(query);

        setTab(defaultTab(user?.role, params));
        setKindByTab(kindsFromParams(params));
        setSearch(params.get("q") ?? "");
    }, [query, user?.role]);

    useEffect(() => {
        if (!tabs.some((item) => item.value === tab)) {
            setTab(teamReport ? "commitments" : "units");
        }
    }, [tabs, tab, teamReport]);

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

    const { data, isLoading, isPlaceholderData } = useQuery({
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

    const tabRows = useMemo(() => {
        if (!data) return [];

        if (tab === "units") return data.units ?? [];
        if (tab === "heads") return data.heads ?? [];
        if (tab === "vps") return data.vps ?? [];
        if (tab === "people") return data.people ?? [];
        if (tab === "forms") return data.forms ?? [];
        if (tab === "ratings") return (data.forms ?? []).filter((row) => row.average != null);

        return data.commitments ?? [];
    }, [data, tab]);

    const kindFields = useMemo(() => {
        if (tab === "units") {
            return [
                {
                    key: "level",
                    label: "Level",
                    all: "All levels",
                    pick: (row) => row.type,
                    options: orderedChoices(tabRows, LEVEL_ORDER, (row) => row.type, (value) => LEVEL_LABELS[value] ?? value),
                },
                ratingField(tabRows, { includeUnrated: true }),
                overdueStandingField(),
            ];
        }

        if (tab === "heads" || tab === "vps") {
            return [unitField(tabRows), ratingField(tabRows, { includeUnrated: true }), overdueStandingField()];
        }

        if (tab === "people") {
            const collegePace = data?.totals?.progress_pct;

            return [
                unitField(tabRows),
                ratingField(tabRows, { includeUnrated: true }),
                overdueStandingField(),
                {
                    key: "pace",
                    label: "Pace",
                    all: "All pace",
                    always: true,
                    pick: (row) => {
                        if (row.progress_pct == null || Number(row.progress_pct) === 0) return "idle";
                        if (collegePace == null || Number(row.progress_pct) >= Number(collegePace)) return "ahead";

                        return "behind";
                    },
                    options: [
                        { value: "ahead", label: "Ahead" },
                        { value: "idle", label: "Not started" },
                        { value: "behind", label: "Behind" },
                    ],
                },
            ];
        }

        if (tab === "forms") {
            return [
                {
                    key: "type",
                    label: "Type",
                    all: "All forms",
                    pick: (row) => row.type,
                    options: orderedChoices(tabRows, TYPE_ORDER, (row) => row.type, (value) => value),
                },
                {
                    key: "status",
                    label: "Status",
                    all: "All statuses",
                    pick: (row) => row.status,
                    options: orderedChoices(
                        tabRows,
                        STATUS_ORDER,
                        (row) => row.status,
                        (value) => STATUS_META[value]?.label ?? value
                    ),
                },
                unitField(tabRows),
            ];
        }

        if (tab === "ratings") {
            return [
                {
                    key: "type",
                    label: "Type",
                    all: "All forms",
                    pick: (row) => row.type,
                    options: orderedChoices(tabRows, TYPE_ORDER, (row) => row.type, (value) => value),
                },
                ratingField(tabRows),
                unitField(tabRows),
            ];
        }

        if (tab === "commitments") {
            return [
                {
                    key: "section",
                    label: "Section",
                    all: "All sections",
                    pick: (row) => row.section,
                    options: orderedChoices(
                        tabRows,
                        SECTION_ORDER,
                        (row) => row.section,
                        (value) => SECTION_LABELS[value] ?? value
                    ),
                },
                {
                    key: "link",
                    label: "Link",
                    all: "All links",
                    always: true,
                    pick: linkOf,
                    options: LINK_ORDER.map((value) => ({ value, label: LINK_LABELS[value] })),
                },
                {
                    key: "progress",
                    label: "Progress",
                    all: "All progress",
                    always: true,
                    pick: (row) => row.progress_status || "not_started",
                    options: PROGRESS_ORDER.map((value) => ({
                        value,
                        label: PROGRESS_META[value]?.label ?? value,
                    })),
                },
                unitField(tabRows),
                {
                    key: "standing",
                    label: "Standing",
                    all: "All standing",
                    always: true,
                    pick: standingOf,
                    options: [
                        { value: "ontime", label: "On time" },
                        ...STANDING_ORDER.map((value) => ({ value, label: STANDING_LABELS[value] })),
                    ],
                },
            ];
        }

        return [];
    }, [tab, tabRows, data?.totals?.progress_pct]);

    const kind = kindByTab[tab] ?? emptyKind;
    const setKindValue = (key, value) =>
        setKindByTab((prev) => ({
            ...prev,
            [tab]: { ...prev[tab], [key]: value },
        }));

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();
        const exactBand = RATING_LEGEND.some((band) => band.label.toLowerCase() === term);
        const exactSection = ["strategic", "core", "support"].includes(term);
        const picked = tabRows.filter((row) =>
            kindFields.every((field) => {
                const selected = kind[field.key] ?? "all";

                if (selected === "all") return true;
                if (field.key === "standing" && selected === "ontime") return !row.is_overdue;
                if (!field.options.some((item) => item.value === selected)) return true;

                return field.pick(row) === selected;
            })
        );
        const searched = (list, fields) => {
            if (!term) return list;

            return list.filter((row) => fields.some((field) => String(row[field] ?? "").toLowerCase().includes(term)));
        };

        if (tab === "units") return searched(picked, ["name", "code"]);
        if (tab === "heads") return searched(picked, ["head", "unit", "unit_code", "position"]);
        if (tab === "vps") return searched(picked, ["vp", "unit", "unit_code", "position"]);
        if (tab === "people") return searched(picked, ["name", "unit", "position", "adjectival"]);
        if (tab === "forms") return searched(picked, ["owner", "unit", "type", "status"]);

        if (tab === "ratings") {
            return exactBand
                ? picked.filter((row) => String(row.adjectival ?? "").toLowerCase() === term)
                : searched(picked, ["owner", "unit", "type", "adjectival"]);
        }

        return exactSection
            ? picked.filter((row) => String(row.section ?? "").toLowerCase() === term)
            : searched(picked, ["description", "owner", "unit", "section"]);
    }, [tabRows, tab, search, kind, kindFields]);

    const sortedRows = useMemo(() => sortRows(rows, tab, sort), [rows, tab, sort]);

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

                const name = `OCC PMS ${tabs.find((t) => t.value === tab)?.label ?? "Report"} ${stamp}`.trim();

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

    const adjectivalTag = (value) =>
        value ? <Tag color={ADJECTIVAL_COLORS[value]}>{value}</Tag> : <Tag>Not yet rated</Tag>;

    const unitColumns = [
        { title: "Unit", dataIndex: "name" },
        { title: "Code", dataIndex: "code", render: (v) => v || "—" },
        {
            title: "Level",
            dataIndex: "type",
            render: (value, row) =>
                row.parent_name ? (
                    <Tooltip title={`Under ${row.parent_name}`}>
                        <span style={{ textTransform: "capitalize" }}>{value ?? "—"}</span>
                    </Tooltip>
                ) : (
                    <span style={{ textTransform: "capitalize" }}>{value ?? "—"}</span>
                ),
        },
        { title: "Forms", dataIndex: "total_forms", align: "right" },
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
            render: (value) => progressBar(value),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            render: (value) => (value > 0 ? <Tag color="error">{value}</Tag> : "—"),
        },
        {
            title: "Average",
            dataIndex: "average",
            align: "right",
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: adjectivalTag,
        },
        ...(data?.dimensions?.keys ?? []).map((key) => ({
            title: data.dimensions.college.find((d) => d.key === key)?.label ?? key.toUpperCase(),
            key,
            align: "right",
            render: (_, row) => score(dimensionsByUnit[row.id]?.scores?.[key]),
        })),
    ];

    const peopleColumns = [
        { title: "Name", dataIndex: "name" },
        { title: "Position", dataIndex: "position", render: (v) => v || "—" },
        { title: "Unit", dataIndex: "unit" },
        { title: "Forms", dataIndex: "forms", align: "right" },
        {
            title: "Commitments",
            dataIndex: "commitments",
            align: "right",
            render: (value, row) => `${row.completed} / ${value}`,
        },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            render: (value) => progressBar(value),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
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
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: adjectivalTag,
        },
    ];

    const leaderColumns = (title, field) => [
        {
            title,
            dataIndex: field,
        },
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
            render: (value) => progressBar(value),
        },
        {
            title: "Overdue",
            dataIndex: "overdue",
            align: "right",
            render: (value) => (value > 0 ? <Tag color="error">{value}</Tag> : "—"),
        },
        {
            title: "Average",
            dataIndex: "average",
            align: "right",
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: adjectivalTag,
        },
    ];

    const headColumns = leaderColumns("Head", "head");
    const vpColumns = leaderColumns("VP", "vp");

    const formColumns = [
        {
            title: "Type",
            dataIndex: "type",
            render: (value) => <Tag>{value}</Tag>,
        },
        { title: "Owner", dataIndex: "owner" },
        { title: "Unit", dataIndex: "unit" },
        {
            title: "Status",
            dataIndex: "status",
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
        },
        { title: "Lines", dataIndex: "commitments", align: "right" },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            render: (value) => progressBar(value),
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
            render: score,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
            render: adjectivalTag,
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
            render: (value) => <strong>{score(value)}</strong>,
        },
        {
            title: "Adjectival",
            dataIndex: "adjectival",
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
            render: (value) => SECTION_LABELS[value] ?? value,
        },
        {
            title: "Link",
            dataIndex: "linked",
            width: 100,
            render: (value) => (value ? <Tag color="blue">Linked</Tag> : <Tag>Unlinked</Tag>),
        },
        {
            title: "Target date",
            dataIndex: "target_date",
            render: (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—"),
        },
        {
            title: "Progress",
            dataIndex: "progress_pct",
            render: (value, row) => progressBar(value, row.progress_status === "completed"),
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
        vps: { columns: vpColumns, rowKey: "id", scroll: 1300 },
        people: { columns: peopleColumns, rowKey: "id", scroll: 1200 },
        forms: { columns: formColumns, rowKey: "id", scroll: 1300 },
        ratings: { columns: ratingColumns, rowKey: "id", scroll: 1000 },
        commitments: { columns: commitmentColumns, rowKey: "id", scroll: 1600 },
    }[tab];

    return (
        <>
            <PageHeader
                title={teamReport ? "Team report" : "Reports"}
                subtitle={
                    teamReport
                        ? "Forms, people and commitments in the offices you lead — filter it, then take it away as Excel or PDF."
                        : "Every form, unit and commitment in the cycle — filter it, then take it away as Excel or PDF."
                }
                extra={
                    <Space wrap className="pms-page-extra">
                        <Select
                            className="pms-year-select"
                            value={yearId}
                            onChange={(value) => {
                                setYearId(value);
                                setPeriodId(null);
                            }}
                            options={years.map((y) => ({
                                value: y.id,
                                label: y.is_active ? `${y.label} ★` : y.label,
                            }))}
                        />
                        {year?.periods?.length > 0 && (
                            <Select
                                className="pms-year-select"
                                value={periodId ?? "all"}
                                onChange={(value) => setPeriodId(value === "all" ? null : value)}
                                options={[
                                    { value: "all", label: "Whole year" },
                                    ...year.periods.map((p) => ({
                                        value: p.id,
                                        label: p.is_active ? `${p.label} ★` : p.label,
                                    })),
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
                <>
                    <div className="pms-desktop-only">
                    <Card className="pms-list-panel pms-list-fill">
                        <div className="pms-report-bar">
                            <Tabs
                                className="pms-line-tabs"
                                activeKey={tab}
                                onChange={(value) => {
                                    setTab(value);
                                    setSearch("");
                                }}
                                items={tabs.map((item) => ({ key: item.value, label: item.label }))}
                            />
                            <Space wrap>
                                <Input.Search
                                    allowClear
                                    placeholder="Search this table"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    style={{ width: 220 }}
                                />
                                <ReportKinds
                                    fields={kindFields}
                                    kind={kind}
                                    onChange={setKindValue}
                                    sort={sort}
                                    onSort={setSort}
                                />
                                <ReportExport busy={busy} onDownload={download} />
                            </Space>
                        </div>
                        <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                            {year?.label} · {period?.label ?? "whole year"} — {totals.forms} forms, {totals.submitted}{" "}
                            submitted, {totals.rated} rated · {totals.commitments} commitments at{" "}
                            {totals.progress_pct ?? 0}% · {totals.overdue} overdue
                        </Typography.Paragraph>
                        <Table
                            key={tab}
                            rowKey={table.rowKey}
                            dataSource={
                                tab === "units"
                                    ? sortedRows.map((row) => ({
                                          ...row,
                                          dimension_scores: dimensionsByUnit[row.id]?.scores,
                                      }))
                                    : sortedRows
                            }
                            columns={table.columns}
                            loading={isLoading || isPlaceholderData}
                            size="small"
                            pagination={tablePager(20, { showSizeChanger: true, showTotal: (t) => `${t} rows` })}
                            scroll={{ x: table.scroll }}
                            onRow={(row) =>
                                tab === "forms" || tab === "ratings"
                                    ? { style: { cursor: "pointer" }, onClick: () => navigate(`/forms/${row.id}`, { state: { from: "/reports" } }) }
                                    : {}
                            }
                        />
                    </Card>
                    </div>

                    <div className="pms-mobile-only">
                    <Card className="pms-list-panel">
                        <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                            {year?.label} · {period?.label ?? "whole year"} — {totals.forms} forms, {totals.submitted}{" "}
                            submitted, {totals.rated} rated · {totals.commitments} commitments at{" "}
                            {totals.progress_pct ?? 0}% · {totals.overdue} overdue
                        </Typography.Paragraph>

                        <div className="pms-report-tabs">
                            <Tabs
                                className="pms-line-tabs"
                                activeKey={tab}
                                onChange={(value) => {
                                    setTab(value);
                                    setSearch("");
                                }}
                                items={tabs.map((item) => ({ key: item.value, label: item.label }))}
                            />
                        </div>

                        <div className="pms-report-tools">
                            <ReportFilters search={search} onSearch={setSearch}>
                                <ReportKinds
                                    fields={kindFields}
                                    kind={kind}
                                    onChange={setKindValue}
                                    sort={sort}
                                    onSort={setSort}
                                />
                            </ReportFilters>
                            <ReportExport busy={busy} onDownload={download} />
                        </div>

                        <ReportCards tab={tab} rows={sortedRows} onOpen={(id) => navigate(`/forms/${id}`, { state: { from: "/reports" } })} />
                    </Card>
                    </div>
                </>
            )}
        </>
    );
}
