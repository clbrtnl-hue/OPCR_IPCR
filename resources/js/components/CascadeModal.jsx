import React from "react";
import { Alert, Checkbox, Divider, Empty, Modal, Select, Space, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { toPlainText } from "~/components/RichTextView";
import { SECTION_LABELS } from "~/utils/constants";

const SECTIONS = ["strategic", "core", "support"];

export default function CascadeModal({ form, periodId, open, onClose }) {
    const queryClient = useQueryClient();
    const [picked, setPicked] = React.useState([]);
    const [lines, setLines] = React.useState([]);

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: open,
        staleTime: 5 * 60 * 1000,
    });

    const groups = React.useMemo(
        () =>
            SECTIONS.map((section) => ({
                section,
                outputs: (form.outputs ?? [])
                    .filter((output) => output.section === section)
                    .map((output) => ({
                        ...output,
                        rows: (output.indicators ?? []).filter(
                            (line) => !line.rating_period_id || line.rating_period_id === periodId
                        ),
                    }))
                    .filter((output) => output.rows.length > 0),
            })).filter((group) => group.outputs.length > 0),
        [form.outputs, periodId]
    );

    const everyLine = React.useMemo(
        () => groups.flatMap((g) => g.outputs.flatMap((o) => o.rows.map((r) => r.id))),
        [groups]
    );

    React.useEffect(() => {
        if (open) {
            setLines(everyLine);
            setPicked([]);
        }
    }, [open, everyLine.length]);

    const cascade = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/cascade`, {
                user_ids: picked,
                indicator_ids: lines,
                rating_period_id: periodId ?? undefined,
            }),
        onSuccess: ({ data }) => {
            message.success(
                data.assigned === 0
                    ? "Everyone picked already holds those lines."
                    : `${data.assigned} commitment${data.assigned === 1 ? "" : "s"} handed out across ${
                          data.people
                      } ${data.people === 1 ? "person" : "people"}${
                          data.skipped ? `, ${data.skipped} already out` : ""
                      }.`
            );
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
    });

    const toggleOutput = (rows, checked) => {
        const ids = rows.map((r) => r.id);

        setLines((current) =>
            checked
                ? Array.from(new Set([...current, ...ids]))
                : current.filter((id) => !ids.includes(id))
        );
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            width={720}
            title="Cascade these targets to the people accountable"
            okText={`Hand out ${lines.length} × ${picked.length || 0}`}
            okButtonProps={{
                disabled: picked.length === 0 || lines.length === 0,
                loading: cascade.isPending,
            }}
            onOk={() => cascade.mutate()}
        >
            <Typography.Paragraph type="secondary">
                Each person named below is made accountable for every ticked office target.
                Their IPCR opens so they can write their own commitments against those targets.
                The college wording is not copied. Anything they already hold is left alone.
            </Typography.Paragraph>

            <Select
                mode="multiple"
                style={{ width: "100%" }}
                placeholder="Search for the people accountable"
                value={picked}
                onChange={setPicked}
                optionFilterProp="label"
                options={people.map((p) => ({
                    value: p.id,
                    label: p.position_title ? `${p.name} — ${p.position_title}` : p.name,
                }))}
            />

            <Divider orientation="left" style={{ marginBottom: 8 }}>
                <Typography.Text type="secondary">
                    Lines to hand out — {lines.length} of {everyLine.length}
                </Typography.Text>
            </Divider>

            {groups.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="This form has no success indicators to hand out yet."
                />
            ) : (
                <div style={{ maxHeight: 320, overflowY: "auto" }}>
                    {groups.map((group) => (
                        <div key={group.section} style={{ marginBottom: 12 }}>
                            <Tag color="blue">{SECTION_LABELS[group.section] ?? group.section}</Tag>
                            {group.outputs.map((output) => {
                                const ids = output.rows.map((r) => r.id);
                                const on = ids.filter((id) => lines.includes(id));

                                return (
                                    <div key={output.id} style={{ margin: "8px 0 8px 4px" }}>
                                        <Checkbox
                                            checked={on.length === ids.length}
                                            indeterminate={on.length > 0 && on.length < ids.length}
                                            onChange={(e) => toggleOutput(output.rows, e.target.checked)}
                                        >
                                            <Typography.Text strong>
                                                {output.outline_number
                                                    ? `${output.outline_number}. ${output.title}`
                                                    : output.title}
                                            </Typography.Text>
                                        </Checkbox>
                                        <Space direction="vertical" size={2} style={{ marginLeft: 24, display: "flex" }}>
                                            {output.rows.map((row) => (
                                                <Checkbox
                                                    key={row.id}
                                                    checked={lines.includes(row.id)}
                                                    onChange={(e) =>
                                                        setLines((current) =>
                                                            e.target.checked
                                                                ? [...current, row.id]
                                                                : current.filter((id) => id !== row.id)
                                                        )
                                                    }
                                                >
                                                    <Typography.Text ellipsis style={{ maxWidth: 520 }}>
                                                        {toPlainText(row.description)}
                                                    </Typography.Text>
                                                </Checkbox>
                                            ))}
                                        </Space>
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}

            {picked.length > 1 && lines.length > 1 && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginTop: 12 }}
                    message={`That is ${lines.length * picked.length} commitments in one go — each person gets their own copy to reword.`}
                />
            )}
        </Modal>
    );
}
