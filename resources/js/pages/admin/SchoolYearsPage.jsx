import React, { useState } from "react";
import {
    Alert,
    Button,
    Card,
    DatePicker,
    Drawer,
    Form,
    Input,
    Popconfirm,
    Segmented,
    Space,
    Table,
    Tag,
    message,
} from "antd";
import {
    CheckCircleOutlined,
    LockOutlined,
    PlusOutlined,
    UnlockOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";

const PERIOD_STATUS = [
    { value: "upcoming", label: "Upcoming" },
    { value: "open", label: "Open" },
    { value: "closed", label: "Closed" },
];

export default function SchoolYearsPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [open, setOpen] = useState(false);

    const { data: years = [], isLoading } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ["school-years"] });

    const save = useMutation({
        mutationFn: (values) => api.post("school-years", values),
        onSuccess: () => {
            message.success("School year saved.");
            invalidate();
            setOpen(false);
            form.resetFields();
        },
    });

    const activateYear = useMutation({
        mutationFn: (id) => api.post(`school-years/${id}/activate`),
        onSuccess: () => {
            message.success("Active school year set.");
            invalidate();
        },
    });

    const activatePeriod = useMutation({
        mutationFn: (id) => api.post(`rating-periods/${id}/activate`),
        onSuccess: () => {
            message.success("Active review period set.");
            invalidate();
        },
    });

    const setPeriodLock = useMutation({
        mutationFn: ({ id, locked }) => api.post(`rating-periods/${id}/lock`, { locked }),
        onSuccess: (_, { locked }) => {
            message.success(locked ? "Period locked — it is view-only now." : "Period unlocked.");
            queryClient.invalidateQueries({ queryKey: ["school-years"] });
        },
    });

    const setPeriodStatus = useMutation({
        mutationFn: ({ id, status }) => api.post(`rating-periods/${id}/status`, { status }),
        onSuccess: () => {
            message.success("Review period updated.");
            invalidate();
        },
    });

    const columns = [
        {
            title: "School year",
            dataIndex: "label",
            render: (label, record) => (
                <Space>
                    <strong>{label}</strong>
                    {record.is_active && (
                        <Tag color="green" icon={<CheckCircleOutlined />}>
                            Active
                        </Tag>
                    )}
                </Space>
            ),
        },
        {
            title: "Covers",
            key: "range",
            render: (_, record) =>
                `${dayjs(record.start_date).format("MMM D, YYYY")} – ${dayjs(record.end_date).format("MMM D, YYYY")}`,
        },
        {
            title: "",
            key: "actions",
            render: (_, record) =>
                record.is_active ? null : (
                    <Button size="small" onClick={() => activateYear.mutate(record.id)}>
                        Set as active
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                title="School Years"
                subtitle="Each school year carries one OPCR or IPCR, rated at two review points. Set which year and period is active so people know what they are filling in."
                extra={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => setOpen(true)}>
                        New school year
                    </Button>
                }
            />

            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Creating ahead of time"
                description="You can add next year's cycle now and leave it inactive. Forms can still be written against it; the active flag only tells everyone which cycle is current."
            />

            <Card>
                <Table
                    rowKey="id"
                    loading={isLoading}
                    dataSource={years}
                    columns={columns}
                    pagination={false}
                    expandable={{
                        defaultExpandAllRows: true,
                        expandedRowRender: (record) => (
                            <Table
                                rowKey="id"
                                size="small"
                                pagination={false}
                                dataSource={record.periods}
                                columns={[
                                    {
                                        title: "Review point",
                                        dataIndex: "label",
                                        render: (label, period) => (
                                            <Space>
                                                {label}
                                                {Boolean(period.is_active) && <Tag color="green">Active</Tag>}
                                                {Boolean(period.is_locked) && (
                                                    <Tag color="red" icon={<LockOutlined />}>
                                                        Locked
                                                    </Tag>
                                                )}
                                            </Space>
                                        ),
                                    },
                                    {
                                        title: "Window",
                                        key: "window",
                                        render: (_, period) =>
                                            period.opens_at
                                                ? `${dayjs(period.opens_at).format("MMM D, YYYY")} – ${dayjs(period.closes_at).format("MMM D, YYYY")}`
                                                : "Not scheduled",
                                    },
                                    {
                                        title: "Status",
                                        key: "status",
                                        render: (_, period) => (
                                            <Segmented
                                                size="small"
                                                value={period.status}
                                                options={PERIOD_STATUS}
                                                onChange={(status) =>
                                                    setPeriodStatus.mutate({ id: period.id, status })
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        title: "Lock",
                                        key: "lock",
                                        render: (_, period) => (
                                            <Popconfirm
                                                title={
                                                    period.is_locked
                                                        ? "Unlock this period?"
                                                        : "Lock this period?"
                                                }
                                                description={
                                                    period.is_locked
                                                        ? "People will be able to edit and upload again."
                                                        : "Everyone but an administrator will be view-only — no edits, accomplishments, evidence or ratings."
                                                }
                                                onConfirm={() =>
                                                    setPeriodLock.mutate({
                                                        id: period.id,
                                                        locked: !period.is_locked,
                                                    })
                                                }
                                            >
                                                <Button
                                                    size="small"
                                                    danger={!period.is_locked}
                                                    icon={period.is_locked ? <UnlockOutlined /> : <LockOutlined />}
                                                >
                                                    {period.is_locked ? "Unlock" : "Lock"}
                                                </Button>
                                            </Popconfirm>
                                        ),
                                    },
                                    {
                                        title: "",
                                        key: "activate",
                                        render: (_, period) =>
                                            period.is_active ? null : (
                                                <Button
                                                    size="small"
                                                    onClick={() => activatePeriod.mutate(period.id)}
                                                >
                                                    Set as active
                                                </Button>
                                            ),
                                    },
                                ]}
                            />
                        ),
                    }}
                />
            </Card>

            <Drawer
                title="New school year"
                open={open}
                onClose={() => setOpen(false)}
                width={440}
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
                    initialValues={{
                        periods: [{ label: "Mid-year Review" }, { label: "End-year Review" }],
                    }}
                    onFinish={(values) =>
                        save.mutate({
                            label: values.label,
                            start_date: values.range[0].format("YYYY-MM-DD"),
                            end_date: values.range[1].format("YYYY-MM-DD"),
                            is_active: false,
                            periods: values.periods.map((p, index) => ({
                                label: p.label,
                                opens_at: p.window?.[0]?.format("YYYY-MM-DD") ?? null,
                                closes_at: p.window?.[1]?.format("YYYY-MM-DD") ?? null,
                            })),
                        })
                    }
                >
                    <Form.Item name="label" label="Label" rules={[{ required: true, message: "Name the school year." }]}>
                        <Input placeholder="SY 2026-2027" />
                    </Form.Item>
                    <Form.Item name="range" label="Covers" rules={[{ required: true, message: "Pick the date range." }]}>
                        <DatePicker.RangePicker style={{ width: "100%" }} />
                    </Form.Item>

                    {[0, 1].map((index) => (
                        <Card
                            key={index}
                            size="small"
                            title={index === 0 ? "First review point" : "Second review point"}
                            style={{ marginBottom: 12 }}
                        >
                            <Form.Item
                                name={["periods", index, "label"]}
                                label="Name"
                                rules={[{ required: true, message: "Name this review point." }]}
                            >
                                <Input />
                            </Form.Item>
                            <Form.Item name={["periods", index, "window"]} label="Submission window">
                                <DatePicker.RangePicker style={{ width: "100%" }} />
                            </Form.Item>
                        </Card>
                    ))}
                </Form>
            </Drawer>
        </>
    );
}
