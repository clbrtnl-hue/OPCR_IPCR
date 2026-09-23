import React from "react";
import { Alert, Card, Empty, Segmented, Select, Space, Statistic, Table, Tag, Typography } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { ADJECTIVAL_COLORS, STATUS_META } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";

export default function MyTeamPage() {
    const navigate = useNavigate();
    const [yearId, setYearId] = React.useState(null);
    const [periodId, setPeriodId] = React.useState(null);

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

    const members = data?.members ?? [];
    const units = data?.units ?? [];
    const notStarted = members.filter((m) => !m.form || m.form.outputs_count === 0).length;
    const withMe = members.filter((m) => m.form?.status === "head_review" || m.form?.status === "vp_review").length;
    const done = members.filter((m) => ["rated", "final"].includes(m.form?.status)).length;

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
            render: (code, record) => <Tag>{code ?? record.org_unit?.name ?? "—"}</Tag>,
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

                const meta = STATUS_META[status];

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
            render: (_, record) =>
                record.form ? (
                    <a onClick={() => navigate(`/forms/${record.form.id}`)}>Open</a>
                ) : (
                    <Typography.Text type="secondary">—</Typography.Text>
                ),
        },
    ];

    const switcher = (
        <Space wrap>
            {periods.length > 0 && (
                <Segmented
                    value={chosenPeriodId}
                    onChange={setPeriodId}
                    options={periods.map((p) => ({
                        value: p.id,
                        label: p.is_active ? `${p.label} ★` : p.label,
                    }))}
                />
            )}
            {years.length > 1 && (
                <Select
                    style={{ width: 160 }}
                    value={year?.id}
                    onChange={(value) => {
                        setYearId(value);
                        setPeriodId(null);
                    }}
                    options={years.map((y) => ({
                        value: y.id,
                        label: y.is_active ? `${y.label} (active)` : y.label,
                    }))}
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
                extra={switcher}
            />

            {!isLoading && units.length === 0 && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="You are not named as the head or VP of any office, so there is no one to show here."
                    description="An administrator sets this in Setup → Hierarchy."
                />
            )}

            {members.length > 0 && (
                <Space wrap size={16} style={{ marginBottom: 16 }}>
                    <Card size="small" style={{ minWidth: 150 }}>
                        <Statistic title="People" value={members.length} />
                    </Card>
                    <Card size="small" style={{ minWidth: 150 }}>
                        <Statistic
                            title="Not started"
                            value={notStarted}
                            valueStyle={notStarted ? { color: "#cf1322" } : undefined}
                        />
                    </Card>
                    <Card size="small" style={{ minWidth: 150 }}>
                        <Statistic title="Waiting for review" value={withMe} />
                    </Card>
                    <Card size="small" style={{ minWidth: 150 }}>
                        <Statistic title="Rated" value={done} />
                    </Card>
                </Space>
            )}

            <Card>
                {members.length === 0 && !isLoading ? (
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description="No one is placed under the offices you lead yet."
                    />
                ) : (
                    <Table
                        rowKey="id"
                        loading={isLoading}
                        dataSource={members}
                        columns={columns}
                        pagination={{ pageSize: 15 }}
                        scroll={{ x: 900 }}
                    />
                )}
            </Card>
        </>
    );
}
