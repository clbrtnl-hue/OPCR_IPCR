import React, { useState } from "react";
import { Button, Card, Empty, Modal, Form, Select, Space, Table, Tag, message } from "antd";
import { PlusOutlined /* , TeamOutlined */ } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { STATUS_META } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import { useAuth } from "~/hooks/useAuth";
// import BulkIpcrModal from "~/components/BulkIpcrModal";

export default function MyFormsPage() {
    const { user, can } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [open, setOpen] = useState(false);
    // const [bulkOpen, setBulkOpen] = useState(false);

    const { data: forms = [], isLoading } = useQuery({
        queryKey: ["pcr-forms", "mine"],
        queryFn: () => api.get("pcr-forms?mine=1").then((r) => r.data),
    });

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const { data: units = [] } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const create = useMutation({
        mutationFn: (values) => api.post("pcr-forms", values),
        onSuccess: ({ data }) => {
            message.success("Form created. Add your outputs and success indicators.");
            queryClient.invalidateQueries({ queryKey: ["pcr-forms"] });
            setOpen(false);
            form.resetFields();
            navigate(`/forms/${data.form.id}`);
        },
    });

    const activeYear = years.find((y) => y.is_active);
    const chosenType = Form.useWatch("type", form);
    const chosenYearId = Form.useWatch("school_year_id", form);
    const chosenYear = years.find((y) => y.id === chosenYearId);
    const periodOptions = [...(chosenYear?.periods ?? [])].sort((a, b) => a.seq - b.seq);

    const columns = [
        {
            title: "Form",
            dataIndex: "type",
            render: (type) => <Tag color={type === "opcr" ? "geekblue" : "blue"}>{type.toUpperCase()}</Tag>,
        },
        { title: "School year", dataIndex: ["school_year", "label"] },
        {
            title: "Period",
            dataIndex: ["rating_period", "label"],
            render: (label) => label ?? "Whole year",
        },
        { title: "Unit", dataIndex: ["org_unit", "name"] },
        {
            title: "Ratee",
            dataIndex: ["owner", "name"],
            render: (v, record) => v || `${record.org_unit?.name} (office)`,
        },
        { title: "Outputs", dataIndex: "outputs_count" },
        {
            title: "Status",
            dataIndex: "status",
            render: (status) => {
                const meta = STATUS_META[status];
                return <Tag color={meta.color}>{meta.label}</Tag>;
            },
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Space>
                    <Button size="small" type="primary" onClick={() => navigate(`/forms/${record.id}`)}>
                        Open
                    </Button>
                    <Button size="small" onClick={() => window.open(`/forms/${record.id}/print`, "_blank")}>
                        Print
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="My Forms"
                subtitle="Your performance commitment for each school year, and where it currently sits."
                extra={
                    <Space wrap>
                    {/* {can("admin", "program_head", "vp") && (
                        <Button icon={<TeamOutlined />} onClick={() => setBulkOpen(true)}>
                            Open IPCRs for an office
                        </Button>
                    )} */}
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        onClick={() => {
                            const opensOpcr = can("president");

                            form.setFieldsValue({
                                type: opensOpcr ? "opcr" : "ipcr",
                                school_year_id: activeYear?.id,
                                rating_period_id: activeYear?.periods?.find((p) => p.is_active)?.id,
                                // An administrator writes the OPCR for an office,
                                // not for the unit they happen to sit in.
                                org_unit_id: opensOpcr ? undefined : user?.org_unit_id,
                            });
                            setOpen(true);
                        }}
                    >
                        New form
                    </Button>
                    </Space>
                }
            />

            <Card>
                {forms.length === 0 && !isLoading ? (
                    <Empty description="You have no forms yet. Create one for the active school year to get started." />
                ) : (
                    <Table
                        rowKey="id"
                        loading={isLoading}
                        dataSource={forms}
                        columns={columns}
                        pagination={{ pageSize: 10 }}
                        scroll={{ x: 900 }}
                    />
                )}
            </Card>

            {/* <BulkIpcrModal open={bulkOpen} onClose={() => setBulkOpen(false)} /> */}

            <Modal
                title="New performance commitment"
                open={open}
                onCancel={() => setOpen(false)}
                onOk={() => form.submit()}
                confirmLoading={create.isPending}
                okText="Create"
            >
                <Form form={form} layout="vertical" requiredMark={false} onFinish={(v) => create.mutate(v)}>
                    <Form.Item name="type" label="Form type" rules={[{ required: true }]}>
                        <Select
                            options={[
                                { value: "ipcr", label: "IPCR — my individual commitment" },
                                // Only an administrator opens an office OPCR; it then goes
                                // to QA for approval and is published from there.
                                // The president writes one OPCR for the college each year.
                                ...(can("president")
                                    ? [{ value: "opcr", label: "OPCR — the college's commitment" }]
                                    : []),
                            ]}
                        />
                    </Form.Item>
                    <Form.Item
                        name="school_year_id"
                        label="School year"
                        rules={[{ required: true, message: "Pick the school year." }]}
                    >
                        <Select
                            onChange={(value) => {
                                const picked = years.find((y) => y.id === value);
                                form.setFieldsValue({
                                    rating_period_id: picked?.periods?.find((p) => p.is_active)?.id
                                        ?? picked?.periods?.[0]?.id,
                                });
                            }}
                            options={years.map((y) => ({
                                value: y.id,
                                label: y.is_active ? `${y.label} (active)` : y.label,
                            }))}
                        />
                    </Form.Item>
                    {chosenType === "ipcr" && (
                        <Form.Item
                            name="rating_period_id"
                            label="Review period"
                            rules={[{ required: true, message: "Pick the review period." }]}
                            extra="An IPCR is filed for one review period; the other half of the year gets its own."
                        >
                            <Select
                                options={periodOptions.map((p) => ({
                                    value: p.id,
                                    label: p.is_active ? `${p.label} (active)` : p.label,
                                }))}
                            />
                        </Form.Item>
                    )}
                    <Form.Item
                        name="org_unit_id"
                        label="Office"
                        rules={[{ required: true, message: "Pick the office this form belongs to." }]}
                        extra="An OPCR belongs to the office that commits to the targets."
                    >
                        <Select
                            showSearch
                            optionFilterProp="label"
                            placeholder="Select an office"
                            options={units.map((u) => ({ value: u.id, label: u.name }))}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
