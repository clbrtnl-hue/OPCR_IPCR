import React from "react";
import { Alert, Button, Card, Empty, Popconfirm, Select, Space, Table, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { ADJECTIVAL_COLORS, statusMeta } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import { tablePager } from "~/components/ListPager";
import UserAvatar from "~/components/UserAvatar";
import { useAuth } from "~/hooks/useAuth";

const OWNER_NUDGE_STATUSES = new Set(["draft", "returned", "commitments_approved"]);
// Fixed slots so "Open" and "Remind" land at the same x-position in every row,
// whether or not a row has an Open link.
const ACTION_ROW_STYLE = { display: "inline-flex", alignItems: "center", gap: 8 };
const ACTION_OPEN_SLOT_STYLE = { display: "inline-flex", alignItems: "center", minWidth: 44 };
const ACTION_REMIND_SLOT_STYLE = { display: "inline-flex", alignItems: "center", minWidth: 68 };
const PROGRESS_FILTERS = new Set(["done", "open", "late"]);
const PROGRESS_FILTER_OPTIONS = [
    { value: "all", label: "All progress" },
    { value: "done", label: "Done" },
    { value: "open", label: "Still open" },
    { value: "late", label: "Past target" },
];

function shortUnitCode(code) {
    const raw = String(code || "").trim();

    if (!raw) {
        return null;
    }

    return raw.split("-").filter(Boolean).pop() ?? raw;
}

function unitSubtreeIds(units, rootId) {
    if (!rootId) return null;

    const childrenByParent = new Map();

    for (const unit of units) {
        if (unit.parent_id == null) continue;
        const list = childrenByParent.get(unit.parent_id) ?? [];
        list.push(unit.id);
        childrenByParent.set(unit.parent_id, list);
    }

    const ids = new Set([rootId]);
    const queue = [rootId];

    while (queue.length > 0) {
        const current = queue.shift();

        for (const childId of childrenByParent.get(current) ?? []) {
            if (!ids.has(childId)) {
                ids.add(childId);
                queue.push(childId);
            }
        }
    }

    return ids;
}

export default function MyTeamPage() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const { user } = useAuth();
    const canNudge = Boolean(user?.capabilities?.follow_up);
    const [yearId, setYearId] = React.useState(() => Number(searchParams.get("year")) || null);
    const [periodId, setPeriodId] = React.useState(() => Number(searchParams.get("period")) || null);

    const rawFilter = searchParams.get("filter");
    const progressFilter = PROGRESS_FILTERS.has(rawFilter) ? rawFilter : "all";
    const unitFilter = Number(searchParams.get("unit")) || null;

    const updateParam = (key, value) => {
        const next = new URLSearchParams(searchParams);

        if (value == null || value === "" || value === "all") {
            next.delete(key);
        } else {
            next.set(key, String(value));
        }

        setSearchParams(next, { replace: true });
    };

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const activeYear = years.find((y) => y.is_active) ?? years[0];
    const year = years.find((y) => y.id === (yearId ?? activeYear?.id));
    const periods = [...(year?.periods ?? [])].sort((a, b) => a.seq - b.seq);
    const chosenPeriodId =
        (periods.some((p) => p.id === periodId) ? periodId : null) ??
        periods.find((p) => p.is_active)?.id ??
        periods[0]?.id;
    const periodLocked = Boolean(periods.find((p) => p.id === chosenPeriodId)?.is_locked);

    const { data, isLoading } = useQuery({
        queryKey: ["my-team", year?.id, chosenPeriodId],
        queryFn: () =>
            api
                .get("my-team", {
                    params: {
                        school_year_id: year.id,
                        ...(chosenPeriodId ? { rating_period_id: chosenPeriodId } : {}),
                    },
                })
                .then((r) => r.data),
        enabled: Boolean(year?.id),
    });

    const onNudgeDone = {
        onSuccess: ({ data: payload }) => {
            message.success(payload?.message ?? "Follow-up sent.");
            queryClient.invalidateQueries({ queryKey: ["my-team"] });
        },
        onError: (error) => {
            message.error(error?.response?.data?.message ?? "Could not send the follow-up.");
        },
    };

    const nudge = useMutation({
        mutationFn: (formId) => api.post(`pcr-forms/${formId}/nudge`),
        ...onNudgeDone,
    });

    const remindStart = useMutation({
        mutationFn: (memberId) =>
            api.post(`my-team/${memberId}/remind-start`, {
                school_year_id: year?.id,
                ...(chosenPeriodId ? { rating_period_id: chosenPeriodId } : {}),
            }),
        ...onNudgeDone,
    });

    const followUpBusy = nudge.isPending || remindStart.isPending;

    const members = data?.members ?? [];
    const units = data?.units ?? [];

    const activeUnitId = unitFilter && units.some((u) => u.id === unitFilter) ? unitFilter : null;
    const allowedUnitIds = React.useMemo(
        () => unitSubtreeIds(units, activeUnitId),
        [units, activeUnitId]
    );

    const visibleMembers = React.useMemo(() => {
        return members.filter((member) => {
            if (allowedUnitIds && !allowedUnitIds.has(member.org_unit?.id)) {
                return false;
            }

            if (progressFilter === "done") {
                return (member.progress?.completed ?? 0) > 0;
            }

            if (progressFilter === "open") {
                return (member.progress?.open ?? 0) > 0;
            }

            if (progressFilter === "late") {
                return (member.progress?.overdue ?? 0) > 0;
            }

            return true;
        });
    }, [members, allowedUnitIds, progressFilter]);

    const unitOptions = React.useMemo(
        () => [
            { value: "all", label: "All offices" },
            ...units.map((u) => {
                const short = shortUnitCode(u.code);
                return {
                    value: u.id,
                    label: short ? `${short} — ${u.name}` : u.name,
                };
            }),
        ],
        [units]
    );

    const hasActiveFilters = Boolean(activeUnitId) || progressFilter !== "all";

    const columns = [
        {
            title: "Name",
            dataIndex: "name",
            render: (name, record) => (
                <Space>
                    <UserAvatar user={record} />
                    <div>
                        <div>{name}</div>
                        {record.position_title && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {record.position_title}
                            </Typography.Text>
                        )}
                    </div>
                </Space>
            ),
        },
        {
            title: "Unit",
            dataIndex: ["org_unit", "code"],
            render: (code, record) => (
                <Tag title={record.org_unit?.name}>
                    {shortUnitCode(code) ?? record.org_unit?.name ?? "—"}
                </Tag>
            ),
            filters: units.map((u) => ({ text: u.name, value: u.id })),
            onFilter: (value, record) => record.org_unit?.id === value,
        },
        {
            title: "Outputs",
            dataIndex: ["form", "outputs_count"],
            align: "right",
            sorter: (a, b) => (a.form?.outputs_count ?? -1) - (b.form?.outputs_count ?? -1),
            render: (count, record) => (record.form ? count : "—"),
        },
        {
            title: "Status",
            dataIndex: ["form", "status"],
            render: (status, record) => {
                if (!record.form) {
                    return <Tag color="red">No IPCR yet</Tag>;
                }

                const meta = statusMeta(status, record.form);

                return (
                    <Space size={4}>
                        <Tag color={meta.color}>{meta.label}</Tag>
                        {status === "draft" && record.form.outputs_count === 0 && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                not started
                            </Typography.Text>
                        )}
                    </Space>
                );
            },
        },
        {
            title: "Submitted",
            dataIndex: ["form", "submitted_at"],
            render: (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—"),
        },
        {
            title: "Rating",
            dataIndex: ["form", "average"],
            align: "right",
            render: (average, record) =>
                average == null ? (
                    "—"
                ) : (
                    <Space size={4}>
                        <strong>{average.toFixed(2)}</strong>
                        <Tag color={ADJECTIVAL_COLORS[record.form.adjectival]}>{record.form.adjectival}</Tag>
                    </Space>
                ),
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => {
                if (!record.form) {
                    if (!canNudge || periodLocked) {
                        return <Typography.Text type="secondary">—</Typography.Text>;
                    }

                    return (
                        <span style={ACTION_ROW_STYLE}>
                            <span style={ACTION_OPEN_SLOT_STYLE} />
                            <span style={ACTION_REMIND_SLOT_STYLE}>
                                <Popconfirm
                                    title={`Ask ${record.name} to start their IPCR?`}
                                    description="They get a notification linking to My IPCR. One follow-up per person per day."
                                    okText="Remind"
                                    onConfirm={() => remindStart.mutate(record.id)}
                                    disabled={followUpBusy}
                                >
                                    <Button type="link" size="small" disabled={followUpBusy}>
                                        Remind
                                    </Button>
                                </Popconfirm>
                            </span>
                        </span>
                    );
                }

                const showNudge =
                    canNudge && OWNER_NUDGE_STATUSES.has(record.form.status) && !periodLocked;

                return (
                    <span style={ACTION_ROW_STYLE}>
                        <span style={ACTION_OPEN_SLOT_STYLE}>
                            <a onClick={() => navigate(`/forms/${record.form.id}`, { state: { from: "/my-team" } })}>
                                Open
                            </a>
                        </span>
                        <span style={ACTION_REMIND_SLOT_STYLE}>
                            {showNudge && (
                                <Popconfirm
                                    title={`Follow up with ${record.name}?`}
                                    description="They get a notification asking them to finish their IPCR. One follow-up per form per day."
                                    okText="Remind"
                                    onConfirm={() => nudge.mutate(record.form.id)}
                                    disabled={followUpBusy}
                                >
                                    <Button type="link" size="small" disabled={followUpBusy}>
                                        Remind
                                    </Button>
                                </Popconfirm>
                            )}
                        </span>
                    </span>
                );
            },
        },
    ];

    const periodOptions = periods.map((period) => ({
        value: period.id,
        label: period.is_active ? `${period.label} ★` : period.label,
    }));
    const yearOptions = years.map((item) => ({
        value: item.id,
        label: item.is_active ? `${item.label} ★` : item.label,
    }));
    const onYear = (value) => {
        setYearId(value);
        setPeriodId(null);
        const next = new URLSearchParams(searchParams);
        next.set("year", String(value));
        next.delete("period");
        setSearchParams(next, { replace: true });
    };

    const onPeriod = (value) => {
        setPeriodId(value);
        updateParam("period", value);
    };

    const filterControls = (
        <Space wrap>
            {units.length > 1 && (
                <Select
                    className="pms-filter-select"
                    style={{ minWidth: 160 }}
                    value={activeUnitId ?? "all"}
                    onChange={(value) => updateParam("unit", value === "all" ? null : value)}
                    options={unitOptions}
                    popupMatchSelectWidth={false}
                />
            )}
            <Select
                className="pms-filter-select"
                style={{ minWidth: 130 }}
                value={progressFilter}
                onChange={(value) => updateParam("filter", value)}
                options={PROGRESS_FILTER_OPTIONS}
            />
            {years.length > 1 && (
                <Select
                    className="pms-filter-select"
                    value={year?.id}
                    onChange={onYear}
                    options={yearOptions}
                />
            )}
            {periods.length > 0 && (
                <Select
                    className="pms-filter-select"
                    value={chosenPeriodId}
                    onChange={onPeriod}
                    options={periodOptions}
                />
            )}
        </Space>
    );

    return (
        <>
            <PageHeader
                title="My Team"
                subtitle={
                    units.length
                        ? `${units.map((u) => u.name).join(" · ")} — everyone you review, and where their IPCR stands.`
                        : "Everyone you review, and where their IPCR stands."
                }
                extra={<div className="pms-team-filters">{filterControls}</div>}
            />

            <div className="pms-team-switch">{filterControls}</div>

            {!isLoading && units.length === 0 && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="You are not named as the head, dean, director, or VP of any office, so there is no one to show here."
                    description="An administrator sets this in Setup → Hierarchy."
                />
            )}

            <Card className="pms-list-panel pms-list-fill">
                {visibleMembers.length === 0 && !isLoading ? (
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={
                            hasActiveFilters
                                ? "No team members match the current filter."
                                : "No one is placed under the offices you lead yet."
                        }
                    >
                        {hasActiveFilters && (
                            <Button
                                size="small"
                                onClick={() => {
                                    const next = new URLSearchParams(searchParams);
                                    next.delete("unit");
                                    next.delete("filter");
                                    setSearchParams(next, { replace: true });
                                }}
                            >
                                Clear filters
                            </Button>
                        )}
                    </Empty>
                ) : (
                    <Table
                        rowKey="id"
                        loading={isLoading}
                        dataSource={visibleMembers}
                        columns={columns}
                        pagination={tablePager(15)}
                        scroll={{ x: 900 }}
                    />
                )}
            </Card>
        </>
    );
}
