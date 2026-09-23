import React, { useMemo, useState } from "react";
import {
    Button,
    Card,
    Col,
    DatePicker,
    Empty,
    Input,
    Row,
    Select,
    Space,
    Table,
    Tag,
    Tooltip,
    Typography,
} from "antd";
import {
    CheckCircleOutlined,
    DeleteOutlined,
    EditOutlined,
    LoginOutlined,
    LockOutlined,
    PlusOutlined,
    ReloadOutlined,
    SearchOutlined,
    StarOutlined,
    SwapOutlined,
    UnlockOutlined,
    UploadOutlined,
} from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";
import { STATUS_META } from "~/utils/constants";

dayjs.extend(relativeTime);

/** What each kind of entry is, so the eye can skip to what it wants. */
const ACTIONS = {
    login:      { label: "Signed in",   colour: "default",  icon: <LoginOutlined /> },
    create:     { label: "Created",     colour: "green",    icon: <PlusOutlined /> },
    update:     { label: "Updated",     colour: "blue",     icon: <EditOutlined /> },
    delete:     { label: "Deleted",     colour: "red",      icon: <DeleteOutlined /> },
    status:     { label: "Moved",       colour: "geekblue", icon: <SwapOutlined /> },
    rate:       { label: "Rated",       colour: "purple",   icon: <StarOutlined /> },
    upload:     { label: "Uploaded",    colour: "cyan",     icon: <UploadOutlined /> },
    avatar:     { label: "Photo",       colour: "cyan",     icon: <UploadOutlined /> },
    activate:   { label: "Activated",   colour: "gold",     icon: <CheckCircleOutlined /> },
    deactivate: { label: "Deactivated", colour: "orange",   icon: <DeleteOutlined /> },
    assign:     { label: "Assigned",    colour: "lime",     icon: <PlusOutlined /> },
    lock:       { label: "Locked",      colour: "red",      icon: <LockOutlined /> },
    unlock:     { label: "Unlocked",    colour: "green",    icon: <UnlockOutlined /> },
    reset:      { label: "Reset",       colour: "orange",   icon: <ReloadOutlined /> },
};

/** Class names are how the code refers to things; this is how people do. */
const SUBJECTS = {
    User: "Account",
    PcrForm: "Form",
    PcrIndicator: "Commitment",
    PcrOutput: "MFO/PPA",
    OrgUnit: "Office",
    SchoolYear: "School year",
    RatingPeriod: "Review period",
    WorkflowSetting: "Workflow rules",
};

/** Turn "Form moved from draft to head_review" into words people use. */
function humanise(description) {
    if (!description) return "";

    return description.replace(/\b([a-z]+_[a-z_]+|draft|rated|final|approved|published|returned)\b/g, (word) =>
        STATUS_META[word] ? STATUS_META[word].label : word.replace(/_/g, " ")
    );
}

export default function AuditLogPage() {
    const [filters, setFilters] = useState({ actions: [], subject: null, actor: null, range: null });
    const [term, setTerm] = useState("");

    const params = useMemo(() => {
        const query = new URLSearchParams();

        filters.actions.forEach((a) => query.append("action[]", a));
        if (filters.subject) query.set("subject_type", filters.subject);
        if (filters.actor) query.set("user_id", filters.actor);
        if (filters.range?.[0]) query.set("from", filters.range[0].format("YYYY-MM-DD"));
        if (filters.range?.[1]) query.set("to", filters.range[1].format("YYYY-MM-DD"));
        if (term.trim()) query.set("q", term.trim());

        return query.toString();
    }, [filters, term]);

    const { data, isFetching, refetch } = useQuery({
        queryKey: ["audit-logs", params],
        queryFn: () => api.get(`audit-logs?${params}`).then((r) => r.data),
    });

    const logs = data?.logs ?? [];
    const busiest = useMemo(() => {
        const counts = {};
        logs.forEach((l) => {
            counts[l.action] = (counts[l.action] ?? 0) + 1;
        });

        return counts;
    }, [logs]);

    const clear = () => {
        setFilters({ actions: [], subject: null, actor: null, range: null });
        setTerm("");
    };

    const filtered = filters.actions.length || filters.subject || filters.actor || filters.range || term;

    const columns = [
        {
            title: "When",
            dataIndex: "created_at",
            width: 170,
            render: (value) => (
                <Tooltip title={dayjs(value).format("dddd, MMMM D, YYYY h:mm:ss A")}>
                    <div>
                        <div>{dayjs(value).format("MMM D, h:mm A")}</div>
                        <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                            {dayjs(value).fromNow()}
                        </Typography.Text>
                    </div>
                </Tooltip>
            ),
        },
        {
            title: "Who",
            key: "who",
            width: 190,
            render: (_, row) => (
                <Space size={8}>
                    <UserAvatar name={row.user_name} size="small" showTooltip={false} />
                    <span>{row.user_name ?? "The system"}</span>
                </Space>
            ),
        },
        {
            title: "Action",
            dataIndex: "action",
            width: 140,
            render: (action) => {
                const meta = ACTIONS[action] ?? { label: action, colour: "default" };

                return (
                    <Tag color={meta.colour} icon={meta.icon}>
                        {meta.label}
                    </Tag>
                );
            },
        },
        {
            title: "Subject",
            dataIndex: "subject_type",
            width: 130,
            render: (subject) => SUBJECTS[subject] ?? subject,
        },
        {
            title: "What happened",
            dataIndex: "description",
            render: (description, row) => (
                <div>
                    <div>{humanise(description)}</div>
                    {row.changes && (
                        <Space wrap size={4} style={{ marginTop: 4 }}>
                            {Object.entries(row.changes).map(([field, value]) => (
                                <Tag key={field} style={{ fontSize: 11, margin: 0 }}>
                                    {field.replace(/_/g, " ")}: {String(value.from ?? "—")} →{" "}
                                    <strong>{String(value.to ?? "—")}</strong>
                                </Tag>
                            ))}
                        </Space>
                    )}
                </div>
            ),
        },
        {
            title: "From",
            dataIndex: "ip_address",
            width: 120,
            render: (ip) => (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {ip ?? "—"}
                </Typography.Text>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Audit Trail"
                subtitle="Every account change, form movement and rating — who did it, and when."
                extra={
                    <Button icon={<ReloadOutlined />} loading={isFetching} onClick={() => refetch()}>
                        Refresh
                    </Button>
                }
            />

            <Card style={{ marginBottom: 16 }}>
                <Row gutter={[12, 12]}>
                    <Col xs={24} md={6}>
                        <Input
                            allowClear
                            prefix={<SearchOutlined />}
                            placeholder="Search what happened, or who did it"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <Select
                            mode="multiple"
                            allowClear
                            style={{ width: "100%" }}
                            placeholder="Any action"
                            value={filters.actions}
                            onChange={(actions) => setFilters((f) => ({ ...f, actions }))}
                            options={(data?.actions ?? []).map((a) => ({
                                value: a,
                                label: `${ACTIONS[a]?.label ?? a}${busiest[a] ? ` (${busiest[a]})` : ""}`,
                            }))}
                        />
                    </Col>
                    <Col xs={12} sm={6} md={4}>
                        <Select
                            allowClear
                            style={{ width: "100%" }}
                            placeholder="Anything"
                            value={filters.subject}
                            onChange={(subject) => setFilters((f) => ({ ...f, subject }))}
                            options={(data?.subjects ?? []).map((s) => ({
                                value: s,
                                label: SUBJECTS[s] ?? s,
                            }))}
                        />
                    </Col>
                    <Col xs={12} sm={6} md={4}>
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            style={{ width: "100%" }}
                            placeholder="Anyone"
                            value={filters.actor}
                            onChange={(actor) => setFilters((f) => ({ ...f, actor }))}
                            options={(data?.actors ?? []).map((a) => ({
                                value: a.user_id,
                                label: a.user_name,
                            }))}
                        />
                    </Col>
                    <Col xs={24} md={5}>
                        <DatePicker.RangePicker
                            style={{ width: "100%" }}
                            value={filters.range}
                            onChange={(range) => setFilters((f) => ({ ...f, range }))}
                        />
                    </Col>
                </Row>

                {filtered && (
                    <Space style={{ marginTop: 12 }}>
                        <Typography.Text type="secondary">
                            {logs.length} {logs.length === 1 ? "entry" : "entries"} match
                        </Typography.Text>
                        <Button size="small" type="link" onClick={clear}>
                            Clear filters
                        </Button>
                    </Space>
                )}
            </Card>

            <Card>
                <Table
                    rowKey="id"
                    size="small"
                    loading={isFetching}
                    dataSource={logs}
                    columns={columns}
                    scroll={{ x: 1000 }}
                    pagination={{ pageSize: 25, showSizeChanger: false }}
                    locale={{
                        emptyText: (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description={
                                    filtered
                                        ? "Nothing matches those filters."
                                        : "Nothing has happened yet."
                                }
                            />
                        ),
                    }}
                />
            </Card>
        </>
    );
}
