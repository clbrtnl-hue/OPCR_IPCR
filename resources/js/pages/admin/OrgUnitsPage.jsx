import React, { useEffect, useMemo, useState } from "react";
import {
    Alert,
    Button,
    Card,
    Drawer,
    Form,
    Input,
    Popconfirm,
    Select,
    Space,
    Table,
    Tag,
    message,
} from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";

const TYPE_LABELS = { college: "College", office: "Office", program: "Program" };

function byName(a, b) {
    return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
}

function nestUnits(units) {
    const nodes = new Map(units.map((unit) => [unit.id, { ...unit, children: [] }]));
    const placed = new Set();

    [...units].sort(byName).forEach((unit) => {
        const node = nodes.get(unit.id);
        const parent = unit.parent_id ? nodes.get(unit.parent_id) : null;

        if (parent && parent.id !== node.id) {
            parent.children.push(node);
            placed.add(node.id);
        }
    });

    const roots = [...units]
        .filter((unit) => !placed.has(unit.id))
        .map((unit) => nodes.get(unit.id))
        .sort((a, b) => {
            if ((a.type === "college") !== (b.type === "college")) {
                return a.type === "college" ? -1 : 1;
            }
            return byName(a, b);
        });

    return dropEmptyChildren(roots);
}

function dropEmptyChildren(nodes) {
    return nodes.map((node) => {
        const children = dropEmptyChildren(node.children);
        if (children.length === 0) {
            const rest = { ...node };
            delete rest.children;
            return rest;
        }
        return { ...node, children };
    });
}

function collectIds(nodes) {
    return nodes.flatMap((node) => [node.id, ...(node.children ? collectIds(node.children) : [])]);
}

function flattenUnits(nodes, depth = 0) {
    return nodes.flatMap((node) => [
        { id: node.id, name: node.name, depth },
        ...(node.children ? flattenUnits(node.children, depth + 1) : []),
    ]);
}

function findUnit(nodes, id) {
    for (const node of nodes) {
        if (node.id === id) return node;
        const nested = node.children ? findUnit(node.children, id) : null;
        if (nested) return nested;
    }
    return null;
}

function descendantIds(node) {
    return (node.children ?? []).flatMap((child) => [child.id, ...descendantIds(child)]);
}

export default function OrgUnitsPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [expandedRowKeys, setExpandedRowKeys] = useState([]);

    const { data: units = [], isLoading } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const { data: people = [] } = useQuery({
        queryKey: ["user-options"],
        queryFn: () => api.get("users/options").then((r) => r.data),
    });

    const heads = people.filter((p) => ["program_head", "admin", "vp"].includes(p.role));
    const vps = people.filter((p) => ["vp", "admin"].includes(p.role));
    const tree = useMemo(() => nestUnits(units), [units]);

    useEffect(() => {
        setExpandedRowKeys(collectIds(tree));
    }, [tree]);

    const parentOptions = flattenUnits(tree).filter((unit) => {
        if (unit.id === editing?.id) return false;
        const current = editing ? findUnit(tree, editing.id) : null;
        return !current || !descendantIds(current).includes(unit.id);
    });

    const save = useMutation({
        mutationFn: (values) => api.post("org-units", values),
        onSuccess: ({ data }) => {
            message.success(data.data === "created" ? "Unit created." : "Unit updated.");
            queryClient.invalidateQueries({ queryKey: ["org-units"] });
            close();
        },
    });

    const remove = useMutation({
        mutationFn: (id) => api.delete(`org-units/${id}`),
        onSuccess: () => {
            message.success("Unit deleted.");
            queryClient.invalidateQueries({ queryKey: ["org-units"] });
        },
    });

    const openDrawer = (record) => {
        setEditing(record ?? null);
        setOpen(true);
        form.setFieldsValue(record ?? { type: "program" });
    };

    const close = () => {
        setOpen(false);
        setEditing(null);
        form.resetFields();
    };

    const columns = [
        { title: "Unit", dataIndex: "name" },
        { title: "Code", dataIndex: "code", render: (v) => v || "—" },
        {
            title: "Type",
            dataIndex: "type",
            render: (type) => <Tag>{TYPE_LABELS[type]}</Tag>,
        },
        {
            title: "Head",
            dataIndex: ["head", "name"],
            render: (v) => v || <Tag color="orange">Not set</Tag>,
        },
        {
            title: "Reports to VP",
            dataIndex: ["vp", "name"],
            render: (v) => v || <Tag color="orange">Not set</Tag>,
        },
        { title: "People", dataIndex: "members_count" },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Space>
                    <Button size="small" onClick={() => openDrawer(record)}>
                        Edit
                    </Button>
                    <Popconfirm title="Delete this unit?" onConfirm={() => remove.mutate(record.id)}>
                        <Button size="small" danger>
                            Delete
                        </Button>
                    </Popconfirm>
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Hierarchy"
                subtitle="Set who reviews whom. A form goes to the unit's head first, then to the VP that unit reports to."
                extra={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => openDrawer(null)}>
                        New unit
                    </Button>
                }
            />

            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Review order"
                description="Employee submits → Head reviews → VP reviews → QA rates → President reads the reports. QA and the President are college-wide, so they see every unit."
            />

            <Card>
                <Table
                    rowKey="id"
                    loading={isLoading}
                    dataSource={tree}
                    columns={columns}
                    pagination={false}
                    scroll={{ x: 900 }}
                    indentSize={24}
                    expandable={{
                        expandedRowKeys,
                        onExpandedRowsChange: setExpandedRowKeys,
                    }}
                />
            </Card>

            <Drawer
                title={editing ? `Edit ${editing.name}` : "New unit"}
                open={open}
                onClose={close}
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
                    <Form.Item name="name" label="Unit name" rules={[{ required: true, message: "Name the unit." }]}>
                        <Input placeholder="BS Information Technology" />
                    </Form.Item>
                    <Form.Item name="code" label="Short code">
                        <Input placeholder="BSIT" />
                    </Form.Item>
                    <Form.Item name="type" label="Type" rules={[{ required: true }]}>
                        <Select
                            options={Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label }))}
                        />
                    </Form.Item>
                    <Form.Item name="parent_id" label="Part of">
                        <Select
                            allowClear
                            placeholder="Select a parent unit"
                            options={parentOptions.map((unit) => ({
                                value: unit.id,
                                label: `${"— ".repeat(unit.depth)}${unit.name}`,
                            }))}
                        />
                    </Form.Item>
                    <Form.Item
                        name="head_user_id"
                        label="Head"
                        extra="First reviewer of every form written in this unit."
                    >
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            placeholder="Select the head"
                            options={heads.map((p) => ({ value: p.id, label: p.name }))}
                        />
                    </Form.Item>
                    <Form.Item
                        name="vp_user_id"
                        label="Reports to VP"
                        extra="Second reviewer, after the head endorses."
                    >
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            placeholder="Select the VP"
                            options={vps.map((p) => ({ value: p.id, label: p.name }))}
                        />
                    </Form.Item>
                </Form>
            </Drawer>
        </>
    );
}
