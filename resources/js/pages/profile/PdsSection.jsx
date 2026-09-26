import React, { useState } from "react";
import { Button, Card, Empty, Form, Modal, Popconfirm, Table, message } from "antd";
import { DeleteOutlined, EditOutlined, PlusOutlined } from "@ant-design/icons";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";

/**
 * One repeating block of the Personal Data Sheet. They all behave the same — a
 * list you add to, edit and remove from — so the columns and the form fields are
 * the only thing that differs between them.
 */
export default function PdsSection({
    title,
    hint,
    section,
    rows = [],
    columns,
    fields,
    toForm,
    fromForm,
}) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["my-pds"] });

    const save = useMutation({
        mutationFn: (values) =>
            api.post(`my-profile/${section}`, {
                ...(fromForm ? fromForm(values) : values),
                id: editing?.id,
            }),
        onSuccess: () => {
            message.success(`${title} saved.`);
            setEditing(null);
            form.resetFields();
            refresh();
        },
    });

    const remove = useMutation({
        mutationFn: (id) => api.delete(`my-profile/${section}/${id}`),
        onSuccess: () => {
            message.success("Entry removed.");
            refresh();
        },
    });

    const open = (row) => {
        setEditing(row ?? {});
        form.setFieldsValue(row ? (toForm ? toForm(row) : row) : {});
    };

    const textOf = (column, row) => {
        const value = column.dataIndex ? row[column.dataIndex] : undefined;

        if (column.render) {
            return column.render(value, row);
        }

        return value == null || value === "" ? "—" : value;
    };

    const actions = (row) => (
        <>
            <Button size="small" type="text" icon={<EditOutlined />} onClick={() => open(row)} />
            <Popconfirm title="Remove this entry?" onConfirm={() => remove.mutate(row.id)}>
                <Button size="small" type="text" danger icon={<DeleteOutlined />} />
            </Popconfirm>
        </>
    );

    return (
        <Card title={title} style={{ marginBottom: 16 }}>
            <div className="pms-pds-bar">
                <Button size="small" icon={<PlusOutlined />} onClick={() => open(null)}>
                    Add
                </Button>
            </div>
            {rows.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={hint} />
            ) : (
                <>
                    <div className="pms-mobile-only pms-pds-cards">
                        {rows.map((row) => (
                            <article key={row.id} className="pms-pds-card">
                                {columns.map((column) => (
                                    <div key={column.title} className="pms-pds-line">
                                        <span>{column.title}</span>
                                        <strong>{textOf(column, row)}</strong>
                                    </div>
                                ))}
                                <div className="pms-pds-actions">{actions(row)}</div>
                            </article>
                        ))}
                    </div>
                    <div className="pms-desktop-only">
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={rows}
                            scroll={{ x: true }}
                            columns={[
                                ...columns,
                                {
                                    title: "",
                                    key: "actions",
                                    width: 90,
                                    render: (_, row) => actions(row),
                                },
                            ]}
                        />
                    </div>
                </>
            )}

            <Modal
                title={editing?.id ? `Edit — ${title}` : `Add to ${title}`}
                open={Boolean(editing)}
                onCancel={() => setEditing(null)}
                onOk={() => form.submit()}
                confirmLoading={save.isPending}
                destroyOnClose
            >
                <Form
                    form={form}
                    layout="vertical"
                    requiredMark={false}
                    onFinish={(values) => save.mutate(values)}
                >
                    {fields}
                </Form>
            </Modal>
        </Card>
    );
}
