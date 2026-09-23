import React, { useState } from "react";
import {
    Button,
    Card,
    Col,
    Drawer,
    Form,
    Input,
    Row,
    Popconfirm,
    Select,
    Space,
    Table,
    Tag,
    Typography,
    message,
} from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { ROLE_LABELS } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";

export default function UsersPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);

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
                : { role: "employee", status: "active" }
        );
    };

    const closeDrawer = () => {
        setOpen(false);
        setEditing(null);
        form.resetFields();
    };

    const columns = [
        {
            title: "Name",
            dataIndex: "name",
            defaultSortOrder: "ascend",
            sorter: (a, b) =>
                (a.last_name ?? a.name).localeCompare(b.last_name ?? b.name) ||
                (a.first_name ?? "").localeCompare(b.first_name ?? ""),
        },
        { title: "Email", dataIndex: "email" },
        {
            title: "Role",
            dataIndex: "role",
            filters: Object.entries(ROLE_LABELS).map(([value, text]) => ({ text, value })),
            onFilter: (value, record) => record.role === value,
            render: (role) => <Tag color={role === "admin" ? "red" : "default"}>{ROLE_LABELS[role]}</Tag>,
        },
        { title: "Position", dataIndex: "position_title", render: (v) => v || "—" },
        { title: "Unit", dataIndex: ["org_unit", "name"], render: (v) => v || "—" },
        {
            title: "Status",
            dataIndex: "status",
            render: (status) => (
                <Tag color={status === "active" ? "green" : "default"}>
                    {status === "active" ? "Active" : "Inactive"}
                </Tag>
            ),
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Space>
                    <Button size="small" onClick={() => openDrawer(record)}>
                        Edit
                    </Button>
                    {record.status === "active" && (
                        <Popconfirm
                            title="Deactivate this account?"
                            description="They will no longer be able to sign in."
                            onConfirm={() => deactivate.mutate(record.id)}
                        >
                            <Button size="small" danger>
                                Deactivate
                            </Button>
                        </Popconfirm>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Accounts"
                subtitle="Create the people who use the system and set the role each one holds."
                extra={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => openDrawer(null)}>
                        New account
                    </Button>
                }
            />

            <Card>
                <Table
                    rowKey="id"
                    loading={isLoading}
                    dataSource={users}
                    columns={columns}
                    pagination={{ pageSize: 15 }}
                    scroll={{ x: 900 }}
                />
            </Card>

            <Drawer
                title={editing ? `Edit ${editing.name}` : "New account"}
                open={open}
                onClose={closeDrawer}
                width={420}
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
                            const v = getFieldsValue();
                            const core = [v.prefix, v.first_name, v.middle_initial, v.last_name, v.suffix]
                                .map((part) => (part ?? "").trim())
                                .filter(Boolean)
                                .join(" ");
                            const shown = v.credentials?.trim()
                                ? `${core}, ${v.credentials.trim()}`
                                : core;

                            return shown ? (
                                <Typography.Paragraph type="secondary" style={{ marginTop: -8 }}>
                                    Signs and prints as <strong>{shown}</strong>
                                </Typography.Paragraph>
                            ) : null;
                        }}
                    </Form.Item>
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
                            placeholder="Select a unit"
                            options={units.map((u) => ({ value: u.id, label: u.name }))}
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
