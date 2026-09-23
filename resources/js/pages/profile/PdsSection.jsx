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

    return (
        <Card
            title={title}
            extra={
                <Button size="small" icon={<PlusOutlined />} onClick={() => open(null)}>
                    Add
                </Button>
            }
            style={{ marginBottom: 16 }}
        >
            {rows.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={hint} />
            ) : (
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
                            render: (_, row) => (
                                <>
                                    <Button
                                        size="small"
                                        type="text"
                                        icon={<EditOutlined />}
                                        onClick={() => open(row)}
                                    />
                                    <Popconfirm
                                        title="Remove this entry?"
                                        onConfirm={() => remove.mutate(row.id)}
                                    >
                                        <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                                    </Popconfirm>
                                </>
                            ),
                        },
                    ]}
                />
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
