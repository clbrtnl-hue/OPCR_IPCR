import React, { useMemo, useState } from "react";
import {
    Button,
    Card,
    Col,
    Drawer,
    Empty,
    Form,
    Input,
    Row,
    Popconfirm,
    Segmented,
    Select,
    Spin,
    Table,
    Tag,
    Typography,
    message,
} from "antd";
import { PlusOutlined, SearchOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { ROLE_LABELS } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import ListPager, { tablePager } from "~/components/ListPager";
import UserAvatar from "~/components/UserAvatar";

const PINNED_ROLES = ["admin", "president"];

function pinRank(role) {
    const index = PINNED_ROLES.indexOf(role);

    return index === -1 ? PINNED_ROLES.length : index;
}

function byName(a, b) {
    return (
        (a.last_name ?? a.name).localeCompare(b.last_name ?? b.name) ||
        (a.first_name ?? "").localeCompare(b.first_name ?? "")
    );
}

function matchesSearch(user, term) {
    if (!term) {
        return true;
    }

    return [user.name, user.email, user.position_title, user.org_unit?.name, ROLE_LABELS[user.role]]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term));
}

function printedName(values) {
    const core = [values.prefix, values.first_name, values.middle_initial, values.last_name, values.suffix]
        .map((part) => (part ?? "").trim())
        .filter(Boolean)
        .join(" ");

    return values.credentials?.trim() ? `${core}, ${values.credentials.trim()}` : core;
}

function PersonCell({ user }) {
    return (
        <div className="pms-account-person">
            <UserAvatar user={user} size={40} showTooltip={false} />
            <div>
                <strong>{user.name}</strong>
                <span className="pms-account-place">{user.org_unit?.name || "No unit assigned"}</span>
            </div>
        </div>
    );
}

function StatusTag({ status }) {
    const active = status === "active";

    return <Tag color={active ? "green" : "default"}>{active ? "Active" : "Inactive"}</Tag>;
}

export default function UsersPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState("");
    const [role, setRole] = useState(null);
    const [status, setStatus] = useState("all");
    const [page, setPage] = useState(1);

    const { data: users = [], isLoading } = useQuery({
        queryKey: ["users"],
        queryFn: () => api.get("users").then((r) => r.data),
    });

    const { data: units = [] } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const save = useMutation({
        mutationFn: (values) => api.post("users", values),
        onSuccess: ({ data }) => {
            message.success(data.data === "created" ? "Account created." : "Account updated.");
            queryClient.invalidateQueries({ queryKey: ["users"] });
            closeDrawer();
        },
    });

    const deactivate = useMutation({
        mutationFn: (id) => api.delete(`users/${id}`),
        onSuccess: () => {
            message.success("Account deactivated.");
            queryClient.invalidateQueries({ queryKey: ["users"] });
        },
    });

    const openDrawer = (record) => {
        setEditing(record ?? null);
        setOpen(true);
        form.setFieldsValue(
            record
                ? { ...record, password: undefined }
                : { role: role || "employee", status: "active" }
        );
    };

    const closeDrawer = () => {
        setOpen(false);
        setEditing(null);
        form.resetFields();
    };

    const term = search.trim().toLowerCase();
    const roleCounts = useMemo(() => {
        const counts = {};

        users.forEach((user) => {
            counts[user.role] = (counts[user.role] ?? 0) + 1;
        });

        return counts;
    }, [users]);

    const rows = useMemo(
        () =>
            [...users]
                .filter((user) => matchesSearch(user, term))
                .filter((user) => !role || user.role === role)
                .filter((user) => status === "all" || user.status === status)
                .sort((a, b) => pinRank(a.role) - pinRank(b.role) || byName(a, b)),
        [users, term, role, status]
    );

    const activeCount = users.filter((user) => user.status === "active").length;

    const chooseRole = (next) => {
        setRole(next);
        setPage(1);
    };

    const accountActions = (record) => (
        <div
            className="pms-account-actions"
            style={{
                display: "inline-flex",
                gap: 4,
                alignItems: "center",
                justifyContent: "flex-end",
            }}
        >
            <Button size="small" onClick={() => openDrawer(record)}>
                Edit
            </Button>
            <span
                style={{
                    display: "inline-flex",
                    justifyContent: "flex-start",
                    minWidth: 88,
                }}
            >
                {record.status === "active" ? (
                    <Popconfirm
                        title="Deactivate this account?"
                        description="They will no longer be able to sign in."
                        onConfirm={() => deactivate.mutate(record.id)}
                    >
                        <Button size="small" danger>
                            Deactivate
                        </Button>
                    </Popconfirm>
                ) : null}
            </span>
        </div>
    );

    const columns = [
        {
            title: "Account",
            key: "account",
            render: (_, record) => <PersonCell user={record} />,
        },
        {
            title: "Email",
            dataIndex: "email",
        },
        {
            title: "Position",
            dataIndex: "position_title",
            render: (value) => value || "—",
        },
        {
            title: "Status",
            dataIndex: "status",
            width: 110,
            render: (value) => <StatusTag status={value} />,
        },
        {
            title: "",
            key: "actions",
            width: 180,
            align: "right",
            render: (_, record) => accountActions(record),
        },
    ];

    const emptyText = term || role || status !== "all" ? "No accounts match." : "No accounts yet.";

    return (
        <>
            <PageHeader
                title="Accounts"
                subtitle={
                    users.length
                        ? `${activeCount} of ${users.length} can sign in. Create people and set the role each one holds.`
                        : "Create the people who use the system and set the role each one holds."
                }
                extra={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => openDrawer(null)}>
                        New account
                    </Button>
                }
            />

            <div className="pms-account-filters">
                <button type="button" className={!role ? "is-on" : undefined} onClick={() => chooseRole(null)}>
                    All
                    <em>{users.length}</em>
                </button>
                {Object.entries(ROLE_LABELS).map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        className={role === value ? "is-on" : undefined}
                        onClick={() => chooseRole(value)}
                    >
                        {label}
                        <em>{roleCounts[value] ?? 0}</em>
                    </button>
                ))}
            </div>

            <Card className="pms-list-panel pms-list-fill pms-accounts">
                <div className="pms-account-tools">
                    <Input
                        allowClear
                        prefix={<SearchOutlined />}
                        placeholder="Search name, email, role, or unit"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                    <Segmented
                        value={status}
                        onChange={(value) => {
                            setStatus(value);
                            setPage(1);
                        }}
                        options={[
                            { label: "All", value: "all" },
                            { label: "Active", value: "active" },
                            { label: "Inactive", value: "inactive" },
                        ]}
                    />
                </div>
                <div className="pms-mobile-only">
                    {isLoading ? (
                        <div className="pms-account-loading">
                            <Spin />
                        </div>
                    ) : rows.length === 0 ? (
                        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />
                    ) : (
                        <>
                        <div className="pms-account-list">
                            {rows.slice((page - 1) * 12, page * 12).map((record) => (
                                <article
                                    key={record.id}
                                    className={`pms-account-card${record.status === "active" ? "" : " is-inactive"}`}
                                >
                                    <PersonCell user={record} />
                                    <div className="pms-account-meta">
                                        <span>{record.email}</span>
                                        <span>{record.position_title || "No position"}</span>
                                        <StatusTag status={record.status} />
                                    </div>
                                    {accountActions(record)}
                                </article>
                            ))}
                        </div>
                        <ListPager page={page} pageSize={12} total={rows.length} onChange={setPage} />
                        </>
                    )}
                </div>
                <div className="pms-desktop-only">
                    <Table
                        rowKey="id"
                        loading={isLoading}
                        dataSource={rows}
                        columns={columns}
                        rowClassName={(record) => (record.status === "active" ? "" : "is-inactive")}
                        pagination={tablePager(12, { current: page, onChange: setPage })}
                        locale={{ emptyText }}
                    />
                </div>
            </Card>

            <Drawer
                title={editing ? `Edit ${editing.name}` : "New account"}
                open={open}
                onClose={closeDrawer}
                width={460}
                extra={
                    <Button type="primary" loading={save.isPending} onClick={() => form.submit()}>
                        Save
                    </Button>
                }
            >
                <Form
                    form={form}
                    layout="vertical"
                    requiredMark={false}
                    onFinish={(values) => save.mutate({ ...values, id: editing?.id })}
                >
                    <Typography.Text className="pms-account-section">Name</Typography.Text>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="prefix" label="Title">
                                <Input placeholder="Dr." />
                            </Form.Item>
                        </Col>
                        <Col span={16}>
                            <Form.Item
                                name="first_name"
                                label="First name"
                                rules={[{ required: true, message: "Enter the first name." }]}
                            >
                                <Input placeholder="Amabelle" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item
                                name="middle_initial"
                                label="M.I."
                                normalize={(value) => (value ? value.toUpperCase() : value)}
                            >
                                <Input maxLength={10} placeholder="D." />
                            </Form.Item>
                        </Col>
                        <Col span={16}>
                            <Form.Item
                                name="last_name"
                                label="Last name"
                                rules={[{ required: true, message: "Enter the last name." }]}
                            >
                                <Input placeholder="Pacana" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="suffix" label="Extension" extra="Jr., Sr., III">
                                <Input placeholder="Jr." />
                            </Form.Item>
                        </Col>
                        <Col span={16}>
                            <Form.Item name="credentials" label="Credentials" extra="Printed after the name">
                                <Input placeholder="CPA, RN, LPT" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Form.Item shouldUpdate noStyle>
                        {({ getFieldsValue }) => {
                            const shown = printedName(getFieldsValue());

                            return shown ? (
                                <div className="pms-account-preview">
                                    <span>Signs and prints as</span>
                                    <strong>{shown}</strong>
                                </div>
                            ) : null;
                        }}
                    </Form.Item>

                    <Typography.Text className="pms-account-section">Sign-in</Typography.Text>
                    <Form.Item
                        name="email"
                        label="Email"
                        rules={[
                            { required: true, message: "Enter an email address." },
                            { type: "email", message: "That does not look like an email address." },
                        ]}
                    >
                        <Input placeholder="name@occ.edu.ph" />
                    </Form.Item>
                    <Form.Item
                        name="password"
                        label={editing ? "New password" : "Password"}
                        extra={editing ? "Leave blank to keep the current password." : "At least 8 characters."}
                        rules={editing ? [] : [{ required: true, min: 8, message: "Use at least 8 characters." }]}
                    >
                        <Input.Password placeholder="••••••••" />
                    </Form.Item>

                    <Typography.Text className="pms-account-section">Role and place</Typography.Text>
                    <Form.Item name="role" label="Role" rules={[{ required: true }]}>
                        <Select
                            options={Object.entries(ROLE_LABELS).map(([value, label]) => ({ value, label }))}
                        />
                    </Form.Item>
                    <Form.Item
                        name="position_title"
                        label="Position title"
                        extra="Printed under the signature on the OPCR and IPCR."
                    >
                        <Input placeholder="Associate Professor I" />
                    </Form.Item>
                    <Form.Item name="org_unit_id" label="Unit">
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            placeholder="Select a unit"
                            options={units.map((unit) => ({ value: unit.id, label: unit.name }))}
                        />
                    </Form.Item>
                    <Form.Item name="status" label="Status">
                        <Select
                            options={[
                                { value: "active", label: "Active" },
                                { value: "inactive", label: "Inactive" },
                            ]}
                        />
                    </Form.Item>
                </Form>
            </Drawer>
        </>
    );
}
