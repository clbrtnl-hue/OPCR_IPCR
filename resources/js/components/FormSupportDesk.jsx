import React, { useMemo, useState } from "react";
import { Alert, Button, Form, Input, Modal, Select, Space, Typography, message } from "antd";
import { ToolOutlined } from "@ant-design/icons";
import { useMutation } from "@tanstack/react-query";
import api, { errorText } from "~/utils/api";
import { STATUS_META, statusMeta } from "~/utils/constants";

const IPCR_DESTINATIONS = [
    "draft",
    "returned",
    "commitment_review",
    "commitments_approved",
    "head_review",
    "dean_review",
    "director_review",
    "vp_review",
    "qa_rating",
    "rated",
];

const OPCR_DESTINATIONS = [
    "draft",
    "returned",
    "qa_approval",
    "approved",
    "published",
    "qa_rating",
    "rated",
];

/**
 * Admin-only repair tools for a stuck OPCR/IPCR. Reopen to the owner, force a
 * status, or refresh named reviewers after the hierarchy changed.
 */
export default function FormSupportDesk({ form, onDone }) {
    const [open, setOpen] = useState(false);
    const [action, setAction] = useState("reopen");
    const [fields] = Form.useForm();

    const destinations = useMemo(() => {
        const list = form?.type === "opcr" ? OPCR_DESTINATIONS : IPCR_DESTINATIONS;

        return list
            .filter((status) => status !== form?.status)
            .map((status) => ({
                value: status,
                label: STATUS_META[status]?.label ?? statusMeta(status, form).label,
            }));
    }, [form]);

    const repair = useMutation({
        mutationFn: (values) => api.post(`pcr-forms/${form.id}/repair`, values).then((r) => r.data),
        onSuccess: (payload) => {
            message.success(payload?.message ?? "Form repaired.");
            setOpen(false);
            fields.resetFields();
            setAction("reopen");
            onDone?.();
        },
        onError: (error) => message.error(errorText(error)),
    });

    if (!form) return null;

    const current = statusMeta(form.status, form).label;

    const submit = (values) => {
        repair.mutate({
            action,
            status: action === "move" ? values.status : undefined,
            note: values.note,
        });
    };

    return (
        <>
            <Alert
                type="info"
                showIcon
                icon={<ToolOutlined />}
                message="Support desk"
                description={
                    <Space direction="vertical" size={8} style={{ width: "100%" }}>
                        <Typography.Text>
                            This form is at <strong>{current}</strong>. You can reopen it for the
                            owner, move it to another step, or refresh its reviewers from the
                            hierarchy — without rating or closing it.
                        </Typography.Text>
                        <Button size="small" icon={<ToolOutlined />} onClick={() => setOpen(true)}>
                            Repair form
                        </Button>
                    </Space>
                }
                style={{ marginBottom: 16 }}
            />

            <Modal
                title="Repair form"
                open={open}
                onCancel={() => !repair.isPending && setOpen(false)}
                footer={null}
                destroyOnClose
            >
                <Form
                    form={fields}
                    layout="vertical"
                    onFinish={submit}
                    initialValues={{ action: "reopen" }}
                >
                    <Form.Item label="Action">
                        <Select
                            value={action}
                            onChange={(value) => {
                                setAction(value);
                                fields.setFieldsValue({ status: undefined });
                            }}
                            options={[
                                {
                                    value: "reopen",
                                    label: "Reopen for the owner (returned)",
                                    disabled: form.status === "returned",
                                },
                                { value: "move", label: "Move to another step" },
                                {
                                    value: "refresh_reviewers",
                                    label: "Refresh reviewers from hierarchy",
                                    disabled: form.type !== "ipcr",
                                },
                            ]}
                        />
                    </Form.Item>

                    {action === "move" && (
                        <Form.Item
                            name="status"
                            label="Move to"
                            rules={[{ required: true, message: "Choose a step." }]}
                        >
                            <Select
                                placeholder="Choose a step"
                                options={destinations}
                                showSearch
                                optionFilterProp="label"
                            />
                        </Form.Item>
                    )}

                    <Form.Item
                        name="note"
                        label="Reason"
                        rules={[{ required: true, message: "Say why this repair is needed." }]}
                        extra="Saved on the form history and the audit trail."
                    >
                        <Input.TextArea
                            rows={3}
                            maxLength={500}
                            showCount
                            placeholder="e.g. Wrong reviewer after office transfer — sending back to the owner."
                        />
                    </Form.Item>

                    <Space style={{ width: "100%", justifyContent: "flex-end" }}>
                        <Button onClick={() => setOpen(false)} disabled={repair.isPending}>
                            Cancel
                        </Button>
                        <Button type="primary" htmlType="submit" loading={repair.isPending}>
                            Apply repair
                        </Button>
                    </Space>
                </Form>
            </Modal>
        </>
    );
}
