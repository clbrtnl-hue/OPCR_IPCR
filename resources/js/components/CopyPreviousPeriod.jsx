import React, { useState } from "react";
import { Empty, Modal, Radio, Space, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";

/**
 * An IPCR is filed once per review period. The next period can start from the
 * owner's previous one — the commitments and the office target they answer —
 * without bringing across what was accomplished or how it was rated.
 */
export default function CopyPreviousPeriod({ form, open, onClose }) {
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState(null);

    const { data: forms = [] } = useQuery({
        queryKey: ["my-ipcrs", form.school_year_id],
        queryFn: () =>
            api
                .get(`pcr-forms?type=ipcr&school_year_id=${form.school_year_id}&mine=1`)
                .then((r) => r.data),
        enabled: open && Boolean(form.school_year_id),
    });

    const others = forms
        .filter((row) => row.id !== form.id && row.outputs_count > 0)
        .sort((a, b) => (a.rating_period?.seq ?? 0) - (b.rating_period?.seq ?? 0));

    const copy = useMutation({
        mutationFn: () => api.post(`pcr-forms/${form.id}/copy-from`, { source_form_id: chosen }),
        onSuccess: ({ data }) => {
            message.success(`${data.lines} commitments copied — edit them for this period.`);
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
    });

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title="Copy a previous period"
            okText="Copy the commitments"
            okButtonProps={{ disabled: !chosen, loading: copy.isPending }}
            onOk={() => copy.mutate()}
        >
            {others.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="There is no earlier IPCR this year to copy from."
                />
            ) : (
                <>
                    <Typography.Paragraph type="secondary">
                        The MFO/PPAs and success indicators are copied, including the office
                        target each line already answers. Accomplishments, ratings and who
                        was assigned stay with the period they happened in.
                    </Typography.Paragraph>
                    <Radio.Group
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        style={{ width: "100%" }}
                    >
                        <Space direction="vertical" style={{ width: "100%" }}>
                            {others.map((row) => (
                                <Radio key={row.id} value={row.id}>
                                    {row.rating_period?.label ?? "Earlier period"}{" "}
                                    <Typography.Text type="secondary">
                                        · {row.outputs_count} MFO/PPA
                                        {row.outputs_count === 1 ? "" : "s"}
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
