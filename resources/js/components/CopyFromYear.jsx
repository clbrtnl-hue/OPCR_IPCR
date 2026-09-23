import React, { useState } from "react";
import { Empty, Modal, Radio, Space, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";

/**
 * An OPCR is largely the same year to year, so a new one can start from the
 * last rather than being retyped. Structure only — what was achieved and how it
 * was rated belongs to the year it happened in.
 */
export default function CopyFromYear({ form, open, onClose }) {
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState(null);

    const { data: forms = [] } = useQuery({
        queryKey: ["opcr-history", form.id],
        queryFn: () => api.get("pcr-forms?type=opcr").then((r) => r.data),
        enabled: open,
    });

    const others = forms.filter((f) => f.id !== form.id && f.outputs_count > 0);

    const copy = useMutation({
        mutationFn: () => api.post(`pcr-forms/${form.id}/copy-from`, { source_form_id: chosen }),
        onSuccess: ({ data }) => {
            message.success(`${data.lines} commitments copied — edit them for this year.`);
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
    });

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title="Start from another year"
            okText="Copy the commitments"
            okButtonProps={{ disabled: !chosen, loading: copy.isPending }}
            onOk={() => copy.mutate()}
        >
            {others.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="There is no earlier OPCR to start from."
                />
            ) : (
                <>
                    <Typography.Paragraph type="secondary">
                        The MFO/PPAs, success indicators and budgets are copied across.
                        Accomplishments, ratings and who was accountable are not — those belong
                        to the year they happened in.
                    </Typography.Paragraph>
                    <Radio.Group
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        style={{ width: "100%" }}
                    >
                        <Space direction="vertical" style={{ width: "100%" }}>
                            {others.map((f) => (
                                <Radio key={f.id} value={f.id}>
                                    {f.school_year?.label ?? "Earlier year"}{" "}
                                    <Typography.Text type="secondary">
                                        · {f.outputs_count} MFO/PPA
                                        {f.outputs_count === 1 ? "" : "s"}
                                    </Typography.Text>
                                </Radio>
                            ))}
                        </Space>
                    </Radio.Group>
                </>
            )}
        </Modal>
    );
}
