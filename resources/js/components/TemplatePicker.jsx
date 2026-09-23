import React from "react";
import { Empty, List, Modal, Radio, Space, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { SECTION_LABELS } from "~/utils/constants";

export default function TemplatePicker({ form, open, onClose }) {
    const queryClient = useQueryClient();
    const [chosen, setChosen] = React.useState(null);

    const { data: templates = [] } = useQuery({
        queryKey: ["form-templates"],
        queryFn: () => api.get("form-templates").then((r) => r.data),
        enabled: open,
        staleTime: 60 * 60 * 1000,
    });

    const apply = useMutation({
        mutationFn: () => api.post(`pcr-forms/${form.id}/apply-template`, { template: chosen }),
        onSuccess: ({ data }) => {
            message.success(
                data.lines === 0
                    ? "Those lines are already on this form."
                    : `${data.lines} success indicator${data.lines === 1 ? "" : "s"} inserted — edit them to match your work.`
            );
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
    });

    const picked = templates.find((t) => t.key === chosen);

    return (
        <Modal
            open={open}
            onCancel={onClose}
            width={680}
            title="Insert a template"
            okText="Insert it"
            okButtonProps={{ disabled: !chosen, loading: apply.isPending }}
            onOk={() => apply.mutate()}
        >
            <Typography.Paragraph type="secondary">
                The support functions almost everybody commits to, ready-worded. They are inserted
                as ordinary lines — reword, retarget or delete any of them afterwards. Anything
                already on the form is not duplicated.
            </Typography.Paragraph>

            {templates.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No templates are configured." />
            ) : (
                <Radio.Group
                    value={chosen}
                    onChange={(e) => setChosen(e.target.value)}
                    style={{ width: "100%" }}
                >
                    <Space direction="vertical" size={8} style={{ width: "100%" }}>
                        {templates.map((template) => (
                            <Radio key={template.key} value={template.key} style={{ alignItems: "flex-start" }}>
                                <Space direction="vertical" size={0}>
                                    <Space size={6}>
                                        <Typography.Text strong>{template.name}</Typography.Text>
                                        <Tag color="blue">
                                            {SECTION_LABELS[template.section] ?? template.section}
                                        </Tag>
                                        <Typography.Text type="secondary">
                                            {template.outputs.reduce((sum, o) => sum + o.indicators.length, 0)} lines
                                        </Typography.Text>
                                    </Space>
                                    <Typography.Text type="secondary">{template.description}</Typography.Text>
                                </Space>
                            </Radio>
                        ))}
                    </Space>
                </Radio.Group>
            )}

            {picked && (
                <List
                    size="small"
                    style={{ marginTop: 16 }}
                    header={<Typography.Text type="secondary">What gets inserted</Typography.Text>}
                    dataSource={picked.outputs.flatMap((output) =>
                        output.indicators.map((line) => ({ title: output.title, line }))
                    )}
                    renderItem={(item) => (
                        <List.Item>
                            <Space direction="vertical" size={0}>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {item.title}
                                </Typography.Text>
                                <Typography.Text>{item.line}</Typography.Text>
                            </Space>
                        </List.Item>
                    )}
                />
            )}
        </Modal>
    );
}
