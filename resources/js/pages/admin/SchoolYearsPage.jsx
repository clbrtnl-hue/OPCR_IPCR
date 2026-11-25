import React, { useState } from "react";
import {
    Alert,
    Button,
    Card,
    DatePicker,
    Drawer,
    Empty,
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
    EditOutlined,
    LockOutlined,
    PlusOutlined,
    UnlockOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api, { errorText } from "~/utils/api";
import PageHeader from "~/components/PageHeader";

const PERIOD_STATUS = [
    { value: "upcoming", label: "Upcoming" },
    { value: "open", label: "Open" },
    { value: "closed", label: "Closed" },
];

const phoneRange = window.matchMedia("(max-width: 991px)").matches;

const rangePickerProps = {
    style: { width: "100%" },
    inputReadOnly: phoneRange,
    classNames: { popup: { root: "pms-range-popup" } },
};

function periodWindow(period) {
    if (!period?.opens_at || !period?.closes_at) return undefined;

    return [dayjs(period.opens_at), dayjs(period.closes_at)];
}

export default function SchoolYearsPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [openIds, setOpenIds] = useState(null);

    const { data: years = [], isLoading } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ["school-years"] });

    const closeDrawer = () => {
        setOpen(false);
        setEditing(null);
        form.resetFields();
    };

    const openCreate = () => {
        setEditing(null);
        form.setFieldsValue({
            label: undefined,
            range: undefined,
            periods: [{ label: "Mid-year Review" }, { label: "End-year Review" }],
        });
        setOpen(true);
    };

    const openEdit = (year) => {
        const periods = [...(year.periods ?? [])].sort(
            (a, b) => (a.seq ?? a.id) - (b.seq ?? b.id)
        );

        setEditing(year);
        form.setFieldsValue({
            label: year.label,
            range: [dayjs(year.start_date), dayjs(year.end_date)],
            periods: [0, 1].map((index) => ({
                label: periods[index]?.label ?? (index === 0 ? "Mid-year Review" : "End-year Review"),
                window: periodWindow(periods[index]),
            })),
        });
        setOpen(true);
    };

    const save = useMutation({
        mutationFn: (values) => api.post("school-years", values),
        onSuccess: (_, values) => {
            message.success(values.id ? "School year updated." : "School year saved.");
            invalidate();
            closeDrawer();
        },
        onError: (error) => message.error(errorText(error)),
    });

    const [actionError, setActionError] = useState("");
    const clearActionError = () => setActionError("");

    const activateYear = useMutation({
        mutationFn: (id) => api.post(`school-years/${id}/activate`),
        onSuccess: () => {
            clearActionError();
            message.success("Active school year set.");
            invalidate();
        },
        onError: (error) => setActionError(errorText(error)),
    });

    const activatePeriod = useMutation({
        mutationFn: (id) => api.post(`rating-periods/${id}/activate`),
        onSuccess: () => {
            clearActionError();
            message.success("Active review period set.");
            invalidate();
        },
        onError: (error) => setActionError(errorText(error)),
    });

    const setPeriodLock = useMutation({
        mutationFn: ({ id, locked }) => api.post(`rating-periods/${id}/lock`, { locked }),
        onSuccess: (_, { locked }) => {
            clearActionError();
            message.success(locked ? "Period locked — it is view-only now." : "Period unlocked.");
            queryClient.invalidateQueries({ queryKey: ["school-years"] });
        },
        onError: (error) => setActionError(errorText(error)),
    });

    const setPeriodStatus = useMutation({
        mutationFn: ({ id, status }) => api.post(`rating-periods/${id}/status`, { status }),
        onSuccess: () => {
            clearActionError();
            message.success("Review period updated.");
            invalidate();
        },
        onError: (error) => setActionError(errorText(error)),
    });

    const periodColumns = [
        {
            title: "Review point",
            dataIndex: "label",
            width: "25%",
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
            width: "25%",
            render: (_, period) =>
                period.opens_at
                    ? `${dayjs(period.opens_at).format("MMM D, YYYY")} – ${dayjs(period.closes_at).format("MMM D, YYYY")}`
                    : "Not scheduled",
        },
        {
            title: "Status",
            key: "status",
            width: "25%",
            render: (_, period) => (
                <Segmented
                    size="small"
                    value={period.status}
                    options={PERIOD_STATUS}
                    onChange={(status) => setPeriodStatus.mutate({ id: period.id, status })}
                />
            ),
        },
        {
            title: "",
            key: "actions",
            align: "right",
            width: "25%",
            render: (_, period) => (
                <Space>
                    <Popconfirm
                        title={period.is_locked ? "Unlock this period?" : "Lock this period?"}
                        description={
                            period.is_locked
                                ? "People will be able to edit and upload again."
                                : "Everyone will be view-only — no edits, accomplishments, evidence or ratings — until you unlock it."
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
                    {!period.is_active && (
                        <Button size="small" onClick={() => activatePeriod.mutate(period.id)}>
                            Set as active
                        </Button>
                    )}
                </Space>
            ),
        },
    ];

    const yearIsOpen = (year) => {
        if (openIds) {
            return openIds.has(year.id);
        }

        if (years.some((row) => row.is_active)) {
            return Boolean(year.is_active);
        }

        return year.id === years[0]?.id;
    };

    const toggleYear = (year) => {
        setOpenIds((current) => {
            const next = new Set(
                current ??
                    years
                        .filter((row) => (years.some((item) => item.is_active) ? row.is_active : row.id === years[0]?.id))
                        .map((row) => row.id)
            );

            if (next.has(year.id)) {
                next.delete(year.id);
            } else {
                next.add(year.id);
            }

            return next;
        });
    };

    return (
        <>
            <PageHeader
                title="School Years"
                subtitle="Each school year carries one OPCR or IPCR, rated at two review points. Set which year and period is active so people know what they are filling in."
                extra={
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                        New school year
                    </Button>
                }
            />

            {actionError && (
                <Alert type="error" showIcon style={{ marginBottom: 16 }} message={actionError} />
            )}
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Creating ahead of time"
                description="You can add next year's cycle now and leave it inactive. People can open a form for it, but they cannot write commitments or move it until you open a review period and set the year active."
            />

            <Card className="pms-list-panel">
            {!isLoading && years.length === 0 && <Empty description="No school years yet." />}

            {years.map((record) => {
                const expanded = yearIsOpen(record);

                return (
                <section key={record.id} className="pms-year-row">
                    <div className="pms-year-row-head">
                        <div className="pms-year-row-lead">
                            <button
                                type="button"
                                className={`pms-year-expand${expanded ? " is-open" : ""}`}
                                aria-expanded={expanded}
                                aria-label={expanded ? "Minimize" : "Expand"}
                                onClick={() => toggleYear(record)}
                            />
                            <strong>{record.label}</strong>
                            {record.is_active && (
                                <Tag color="green" icon={<CheckCircleOutlined />}>
                                    Active
                                </Tag>
                            )}
                        </div>
                        <span className="pms-year-row-dates">
                            {dayjs(record.start_date).format("MMM D, YYYY")} –{" "}
                            {dayjs(record.end_date).format("MMM D, YYYY")}
                        </span>
                        <span className="pms-year-row-action">
                            <Space size={8}>
                                <Button size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                                    Edit
                                </Button>
                                {!record.is_active && (
                                    <Button size="small" onClick={() => activateYear.mutate(record.id)}>
                                        Set as active
                                    </Button>
                                )}
                            </Space>
                        </span>
                    </div>
                    {expanded && (
                    <>
                <div className="pms-mobile-only pms-pds-cards">
                            {(record.periods ?? []).map((period) => (
                                <div key={period.id} className="pms-admin-period">
                                    <div className="pms-pds-line">
                                        <span>Review</span>
                                        <strong>
                                            {period.label}
                                            {period.is_active ? " · Active" : ""}
                                            {period.is_locked ? " · Locked" : ""}
                                        </strong>
                                    </div>
                                    <div className="pms-pds-line">
                                        <span>Window</span>
                                        <strong>
                                            {period.opens_at
                                                ? `${dayjs(period.opens_at).format("MMM D")} – ${dayjs(period.closes_at).format("MMM D, YYYY")}`
                                                : "Not scheduled"}
                                        </strong>
                                    </div>
                                    <Segmented
                                        block
                                        size="small"
                                        value={period.status}
                                        options={PERIOD_STATUS}
                                        onChange={(status) => setPeriodStatus.mutate({ id: period.id, status })}
                                    />
                                    <div className="pms-pds-actions">
                                        <Popconfirm
                                            title={period.is_locked ? "Unlock this period?" : "Lock this period?"}
                                            description={
                                                period.is_locked
                                                    ? "People will be able to edit and upload again."
                                                    : "Everyone will be view-only until you unlock it."
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
                                        {!period.is_active && (
                                            <Button size="small" onClick={() => activatePeriod.mutate(period.id)}>
                                                Set as active
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                </div>
                <div className="pms-desktop-only">
                    <Table
                        className="pms-year-periods"
                        rowKey="id"
                        loading={isLoading}
                        dataSource={record.periods}
                        columns={periodColumns}
                        pagination={false}
                        size="small"
                        tableLayout="fixed"
                    />
                </div>
                    </>
                    )}
                </section>
                );
            })}
            </Card>

            <Drawer
                title={editing ? `Edit ${editing.label}` : "New school year"}
                open={open}
                onClose={closeDrawer}
                width={440}
                destroyOnClose
                extra={
                    <Button type="primary" loading={save.isPending} onClick={() => form.submit()}>
                        {editing ? "Save changes" : "Save"}
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
                            id: editing?.id,
                            label: values.label,
                            start_date: values.range[0].format("YYYY-MM-DD"),
                            end_date: values.range[1].format("YYYY-MM-DD"),
                            is_active: editing ? undefined : false,
                            periods: values.periods.map((p) => ({
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
                        <DatePicker.RangePicker {...rangePickerProps} />
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
                                <DatePicker.RangePicker {...rangePickerProps} />
                            </Form.Item>
                        </Card>
                    ))}
                </Form>
            </Drawer>
        </>
    );
}
