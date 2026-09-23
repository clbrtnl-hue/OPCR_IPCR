import React from "react";
import {
    Alert,
    Card,
    Collapse,
    Empty,
    Popconfirm,
    Progress,
    Space,
    Spin,
    Tag,
    Typography,
    message,
} from "antd";
import { DeleteOutlined, MessageOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { PROGRESS_META, STATUS_META, progressTone } from "~/utils/constants";
import { usePerson } from "~/hooks/usePerson";
import { AttachmentGallery } from "~/components/AccomplishmentView";
import CommentList from "~/components/CommentList";
import RichTextView from "~/components/RichTextView";
import UserAvatar from "~/components/UserAvatar";

export default function DeliveredBy({
    indicatorId,
    formId,
    periodId,
    canAssign = false,
    hasDelegatedHeading = false,
}) {
    const queryClient = useQueryClient();
    const { openPerson } = usePerson();

    const { data, isLoading } = useQuery({
        queryKey: ["indicator-rollup", indicatorId, periodId],
        queryFn: () =>
            api
                .get(`pcr-indicators/${indicatorId}/rollup`, {
                    params: periodId ? { rating_period_id: periodId } : {},
                })
                .then((r) => r.data),
        enabled: Boolean(indicatorId),
    });

    const withdraw = useMutation({
        mutationFn: (childId) => api.delete(`pcr-assignments/${childId}`),
        onSuccess: () => {
            message.success("Assignment withdrawn.");
            queryClient.invalidateQueries({ queryKey: ["indicator-rollup", indicatorId] });
            if (formId) queryClient.invalidateQueries({ queryKey: ["pcr-form", String(formId)] });
        },
    });

    const delivered = data?.delivered ?? [];

    if (isLoading) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 24 }}>
                <Spin />
            </div>
        );
    }

    if (delivered.length === 0) {
        return hasDelegatedHeading ? (
            <Alert
                type="info"
                showIcon
                message="Handed out with the MFO/PPA"
                description="The whole heading was given to someone who writes their own success indicators under it, so nothing was cascaded from this particular line."
            />
        ) : (
            <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description="This line has not been handed to anyone yet."
            />
        );
    }

    return (
        <Space direction="vertical" size={12} style={{ width: "100%" }}>
            {delivered.map((person) => {
                const meta = PROGRESS_META[person.progress_status] ?? PROGRESS_META.not_started;
                const status = STATUS_META[person.form_status];
                const files = person.accomplishment?.attachments ?? [];

                return (
                    <Card key={person.child_id} size="small">
                        <Space
                            align="start"
                            style={{ width: "100%", justifyContent: "space-between" }}
                            wrap
                        >
                            <Space align="center">
                                <UserAvatar user={person.owner} showTooltip={false} />
                                <div>
                                    <span
                                        className="pms-person-link"
                                        onClick={() => person.owner && openPerson(person.owner.id)}
                                    >
                                        {person.owner?.name ?? "Unassigned"}
                                    </span>
                                    {person.owner?.position_title && (
                                        <div>
                                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                {person.owner.position_title}
                                            </Typography.Text>
                                        </div>
                                    )}
                                </div>
                            </Space>
                            <Space size={4} wrap>
                                {!person.is_current_period && person.rating_period_label && (
                                    <Tag>{person.rating_period_label}</Tag>
                                )}
                                {status && <Tag color={status.color}>{status.label}</Tag>}
                                <Tag color={meta.color}>
                                    {meta.label} · {person.progress_pct ?? 0}%
                                </Tag>
                                {canAssign && person.assigned_by && (
                                    <Popconfirm
                                        title="Withdraw this assignment?"
                                        description="It disappears from their IPCR. Only possible while they have not worked on it."
                                        onConfirm={() => withdraw.mutate(person.child_id)}
                                    >
                                        <DeleteOutlined className="pms-person-remove" />
                                    </Popconfirm>
                                )}
                            </Space>
                        </Space>

                        <Progress
                            percent={person.progress_pct ?? 0}
                            size="small"
                            showInfo={false}
                            strokeColor={progressTone(person.progress_status, person.progress_pct)}
                            style={{ margin: "8px 0 4px" }}
                        />

                        {person.accomplishment?.actual_accomplishment ? (
                            <RichTextView html={person.accomplishment.actual_accomplishment} />
                        ) : (
                            <Typography.Text type="secondary" style={{ display: "block" }}>
                                {files.length > 0
                                    ? "Evidence attached, but nothing written yet."
                                    : "Nothing recorded yet."}
                            </Typography.Text>
                        )}

                        {person.accomplishment?.remarks && (
                            <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
                                <RichTextView html={person.accomplishment.remarks} />
                            </Typography.Paragraph>
                        )}

                        <AttachmentGallery files={files} />

                        {person.children_count > 0 && (
                            <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
                                Handed on to {person.children_count}{" "}
                                {person.children_count === 1 ? "more person" : "more people"}.
                            </Typography.Paragraph>
                        )}

                        {person.comments.length > 0 && (
                            <Collapse
                                ghost
                                size="small"
                                style={{ marginTop: 8 }}
                                items={[
                                    {
                                        key: "remarks",
                                        label: (
                                            <Space size={6}>
                                                <MessageOutlined />
                                                <span>
                                                    {person.comments.length}{" "}
                                                    {person.comments.length === 1 ? "remark" : "remarks"} on
                                                    their IPCR line
                                                </span>
                                            </Space>
                                        ),
                                        children: <CommentList comments={person.comments} compact />,
                                    },
                                ]}
                            />
                        )}
                    </Card>
                );
            })}
        </Space>
    );
}
