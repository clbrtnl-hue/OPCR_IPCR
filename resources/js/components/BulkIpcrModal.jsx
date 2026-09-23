import React from "react";
import { Alert, Form, Modal, Select, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";

export default function BulkIpcrModal({ open, onClose }) {
    const { user, can } = useAuth();
    const queryClient = useQueryClient();
    const [form] = Form.useForm();

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
        enabled: open,
    });

    const { data: units = [] } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
        enabled: open,
    });

    const { data: people = [] } = useQuery({
        queryKey: ["user-options"],
        queryFn: () => api.get("users/options").then((r) => r.data),
        enabled: open,
    });

    const yearId = Form.useWatch("school_year_id", form);
    const unitId = Form.useWatch("org_unit_id", form);
    const chosenYear = years.find((y) => y.id === yearId);
    const periods = [...(chosenYear?.periods ?? [])].sort((a, b) => a.seq - b.seq);

    const mine = can("admin")
        ? units
        : units.filter((u) => u.head_user_id === user?.id || u.vp_user_id === user?.id);

    const members = people.filter((p) => p.org_unit_id === unitId && p.role !== "admin");

    React.useEffect(() => {
        if (!open) return;

        const activeYear = years.find((y) => y.is_active) ?? years[0];

        form.setFieldsValue({
            school_year_id: activeYear?.id,
            rating_period_id: activeYear?.periods?.find((p) => p.is_active)?.id,
            org_unit_id: mine.length === 1 ? mine[0].id : user?.org_unit_id,
            user_ids: undefined,
        });
    }, [open, years.length]);

    const create = useMutation({
        mutationFn: (values) => api.post("pcr-forms/bulk", values),
        onSuccess: ({ data }) => {
            message.success(
                data.created === 0
                    ? "Everyone in that office already has an IPCR for this period."
                    : `${data.created} IPCR${data.created === 1 ? "" : "s"} opened${
                          data.skipped ? `, ${data.skipped} already had one` : ""
                      }.`
            );
            queryClient.invalidateQueries({ queryKey: ["pcr-forms"] });
            onClose();
        },
    });

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title="Open IPCRs for an office"
            okText="Open them"
            confirmLoading={create.isPending}
            onOk={() => form.submit()}
        >
            <Typography.Paragraph type="secondary">
                Every active member of the office gets a draft IPCR for the period, ready for them
                to fill in. Anyone who already has one is left alone.
            </Typography.Paragraph>

            {mine.length === 0 && !can("admin") && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="You are not named as the head or VP of any office, so there is nothing to open here."
                />
            )}

            <Form form={form} layout="vertical" requiredMark={false} onFinish={(v) => create.mutate(v)}>
                <Form.Item
                    name="school_year_id"
                    label="School year"
                    rules={[{ required: true, message: "Pick the school year." }]}
                >
                    <Select
                        onChange={(value) => {
                            const picked = years.find((y) => y.id === value);
                            form.setFieldsValue({
                                rating_period_id:
                                    picked?.periods?.find((p) => p.is_active)?.id ?? picked?.periods?.[0]?.id,
                            });
                        }}
                        options={years.map((y) => ({
                            value: y.id,
                            label: y.is_active ? `${y.label} (active)` : y.label,
                        }))}
                    />
                </Form.Item>

                <Form.Item
                    name="rating_period_id"
                    label="Review period"
                    rules={[{ required: true, message: "Pick the review period." }]}
                    extra="An IPCR is filed for one review period; the other half of the year is opened separately."
                >
                    <Select
                        options={periods.map((p) => ({
                            value: p.id,
                            label: p.is_active ? `${p.label} (active)` : p.label,
                        }))}
                    />
                </Form.Item>

                <Form.Item
                    name="org_unit_id"
                    label="Office"
                    rules={[{ required: true, message: "Pick the office." }]}
                >
                    <Select
                        showSearch
                        optionFilterProp="label"
                        placeholder="Select an office"
                        onChange={() => form.setFieldsValue({ user_ids: undefined })}
                        options={mine.map((u) => ({ value: u.id, label: u.name }))}
                    />
                </Form.Item>

                <Form.Item
                    name="user_ids"
                    label="People"
                    extra={
                        members.length
                            ? `Leave this empty to open one for all ${members.length} of them.`
                            : "Pick an office to see who is in it."
                    }
                >
                    <Select
                        mode="multiple"
                        allowClear
                        showSearch
                        optionFilterProp="label"
                        placeholder="Everyone in the office"
                        options={members.map((p) => ({
                            value: p.id,
                            label: p.position_title ? `${p.name} — ${p.position_title}` : p.name,
                        }))}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}
