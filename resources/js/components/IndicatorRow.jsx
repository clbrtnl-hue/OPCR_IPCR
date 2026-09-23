import React, { useState } from "react";
import {
    Alert,
    Button,
    Card,
    Descriptions,
    Input,
    Modal,
    Popconfirm,
    Progress,
    Select,
    Space,
    Tag,
    Tooltip,
    Upload,
    Typography,
    message,
} from "antd";
import {
    ClockCircleOutlined,
    DeleteOutlined,
    ExclamationCircleOutlined,
    UserAddOutlined,
    UploadOutlined,
    SaveOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { DELAY_META, NARRATIVE_MAX, PROGRESS_META, UPLOAD_ACCEPT, UPLOAD_MAX_MB } from "~/utils/constants";
import CommentThread from "~/components/CommentThread";
import AccomplishmentView, { AttachmentGallery } from "~/components/AccomplishmentView";
import RichText from "~/components/RichText";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import DeliveredBy from "~/components/DeliveredBy";
import ProgressCell from "~/components/ProgressCell";

export default function IndicatorRow({
    indicator,
    formId,
    periodId,
    canEditCommitment,
    canRecordProgress,
    canAssign,
    isOpcr,
}) {
    const queryClient = useQueryClient();
    const accomplishment = indicator.accomplishments?.find(
        (a) => a.rating_period_id === periodId
    );
    const rating = indicator.ratings?.find((r) => r.rating_period_id === periodId);

    const [text, setText] = useState(accomplishment?.actual_accomplishment ?? "");
    const [showRemarks, setShowRemarks] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [picked, setPicked] = useState([]);
    const [uploading, setUploading] = useState({});

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(formId)] });

    const saveAccomplishment = useMutation({
        mutationFn: () =>
            api.post("pcr-accomplishments", {
                indicator_id: indicator.id,
                rating_period_id: periodId,
                actual_accomplishment: text,
            }),
        onSuccess: () => {
            message.success("Accomplishment saved.");
            refresh();
        },
    });

    const removeIndicator = useMutation({
        mutationFn: () => api.delete(`pcr-indicators/${indicator.id}`),
        onSuccess: () => {
            message.success("Success indicator removed.");
            refresh();
        },
    });

    const assign = useMutation({
        mutationFn: (userIds) => api.post(`pcr-indicators/${indicator.id}/assign`, { user_ids: userIds }),
        onSuccess: ({ data }) => {
            message.success(
                data.assigned === 0
                    ? "They already have this one."
                    : `Assigned to ${data.assigned} ${data.assigned === 1 ? "person" : "people"}.`
            );
            setAssignOpen(false);
            setPicked([]);
            refresh();
        },
    });

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: assignOpen,
        staleTime: 5 * 60 * 1000,
    });

    const removeAttachment = useMutation({
        mutationFn: (id) => api.delete(`pcr-attachments/${id}`),
        onSuccess: () => {
            message.success("Evidence removed.");
            refresh();
        },
    });

    const upload = async (file) => {
        if (file.size > UPLOAD_MAX_MB * 1024 * 1024) {
            message.error(`${file.name} is larger than ${UPLOAD_MAX_MB} MB. Compress it and try again.`);
            return Upload.LIST_IGNORE;
        }

        const body = new FormData();
        body.append("indicator_id", indicator.id);
        body.append("rating_period_id", periodId);
        body.append("file", file);

        const key = `${file.name}-${file.lastModified}`;
        setUploading((current) => ({ ...current, [key]: { name: file.name, percent: 0 } }));

        try {
            await api.post("pcr-attachments", body, {
                onUploadProgress: (event) => {
                    if (!event.total) return;

                    const percent = Math.round((event.loaded / event.total) * 100);
                    setUploading((current) =>
                        current[key] ? { ...current, [key]: { name: file.name, percent } } : current
                    );
                },
            });

            message.success(`${file.name} attached.`);
            refresh();
        } catch {
            setUploading((current) => {
                const next = { ...current };
                if (next[key]) next[key] = { ...next[key], failed: true };
                return next;
            });

            // Leave the failed row visible for a moment so the person sees which one.
            setTimeout(() => dropUpload(key), 4000);
            return false;
        }

        dropUpload(key);

        return false;
    };

    const dropUpload = (key) =>
        setUploading((current) => {
            const next = { ...current };
            delete next[key];
            return next;
        });

    const progressMeta = PROGRESS_META[indicator.progress_status];

    return (
        <Card
            size="small"
            style={{ marginBottom: 12 }}
            title={
                <Typography.Text style={{ whiteSpace: "pre-wrap" }}>
                    <RichTextView html={indicator.description} />
                </Typography.Text>
            }
            extra={
                <Space>
                    {indicator.delay && indicator.delay.state !== "no_date" && (
                        <Tooltip title={indicator.delay.label}>
                            <Tag
                                color={DELAY_META[indicator.delay.state]?.color}
                                icon={
                                    ["overdue", "late"].includes(indicator.delay.state) ? (
                                        <ExclamationCircleOutlined />
                                    ) : (
                                        <ClockCircleOutlined />
                                    )
                                }
                            >
                                {DELAY_META[indicator.delay.state]?.label}
                            </Tag>
                        </Tooltip>
                    )}
                    <Tag color={progressMeta.color}>{progressMeta.label}</Tag>
                    {rating?.a != null && <Tag color="purple">A = {Number(rating.a).toFixed(2)}</Tag>}
                    {canAssign && (
                        <Button size="small" icon={<UserAddOutlined />} onClick={() => setAssignOpen(true)}>
                            Assign
                        </Button>
                    )}
                    {canEditCommitment && (
                        <Popconfirm title="Remove this success indicator?" onConfirm={() => removeIndicator.mutate()}>
                            <Button size="small" danger icon={<DeleteOutlined />} />
                        </Popconfirm>
                    )}
                </Space>
            }
        >
            {isOpcr && (indicator.allotted_budget || indicator.accountable) && (
                <Descriptions size="small" column={2} style={{ marginBottom: 12 }}>
                    {indicator.allotted_budget != null && (
                        <Descriptions.Item label="Allotted budget">
                            ₱{Number(indicator.allotted_budget).toLocaleString()}
                        </Descriptions.Item>
                    )}
                    {indicator.accountable && (
                        <Descriptions.Item label="Accountable">{indicator.accountable}</Descriptions.Item>
                    )}
                </Descriptions>
            )}

            {indicator.target_date && (
                <Typography.Text type="secondary" style={{ display: "block", marginBottom: 8 }}>
                    Target date: {dayjs(indicator.target_date).format("MMM D, YYYY")}
                    {indicator.completed_on &&
                        ` · delivered ${dayjs(indicator.completed_on).format("MMM D, YYYY")}`}
                </Typography.Text>
            )}

            {!isOpcr && indicator.parent && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Contributes to office target"
                    description={toPlainText(indicator.parent.description)}
                />
            )}

            {(indicator.children?.length > 0 || indicator.assignments?.length > 0) && (
                <div style={{ marginBottom: 12 }}>
                    <Typography.Text strong style={{ display: "block", marginBottom: 8 }}>
                        Delivered by
                    </Typography.Text>
                    <DeliveredBy
                        indicatorId={indicator.id}
                        formId={formId}
                        periodId={periodId}
                        canAssign={canAssign}
                    />
                </div>
            )}

            <div style={{ marginBottom: 12, maxWidth: 280 }}>
                <ProgressCell
                    status={indicator.progress_status}
                    pct={indicator.progress_pct}
                    computed
                    computedHint={
                        indicator.children?.length
                            ? "Rolled up from the commitments written against this line."
                            : "100% once the actual accomplishment is written and a file is attached. A narrative alone stays at 0%."
                    }
                />
            </div>

            <Typography.Text strong style={{ display: "block", marginBottom: 4 }}>
                Actual accomplishment
            </Typography.Text>

            {canRecordProgress ? (
                <>
                    <RichText
                        rows={3}
                        value={text}
                        onChange={setText}
                        maxLength={NARRATIVE_MAX}
                        placeholder="What was actually delivered against this target?"
                    />
                    <Space style={{ marginTop: 8 }} wrap>
                        <Button
                            size="small"
                            type="primary"
                            icon={<SaveOutlined />}
                            loading={saveAccomplishment.isPending}
                            onClick={() => saveAccomplishment.mutate()}
                        >
                            Save
                        </Button>
                        <Upload
                            multiple
                            accept={UPLOAD_ACCEPT}
                            showUploadList={false}
                            beforeUpload={upload}
                        >
                            <Button size="small" icon={<UploadOutlined />}>
                                Attach evidence
                            </Button>
                        </Upload>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            Images and documents up to {UPLOAD_MAX_MB} MB each
                        </Typography.Text>
                    </Space>
                </>
            ) : (
                <AccomplishmentView
                    html={text}
                    attachments={accomplishment?.attachments}
                    empty="Nothing recorded yet."
                />
            )}

            {Object.entries(uploading).length > 0 && (
                <Space direction="vertical" size={6} style={{ marginTop: 12, width: "100%" }}>
                    {Object.entries(uploading).map(([key, item]) => (
                        <div key={key}>
                            <Typography.Text style={{ fontSize: 12 }}>
                                {item.failed ? `${item.name} — upload failed` : item.name}
                            </Typography.Text>
                            <Progress
                                percent={item.percent}
                                size="small"
                                status={item.failed ? "exception" : "active"}
                            />
                        </div>
                    ))}
                </Space>
            )}

            {canRecordProgress && (
                <AttachmentGallery
                    files={accomplishment?.attachments}
                    canRemove
                    onRemove={(id) => removeAttachment.mutate(id)}
                />
            )}

            <Modal
                title="Assign this commitment"
                open={assignOpen}
                onCancel={() => setAssignOpen(false)}
                onOk={() => assign.mutate(picked)}
                okText="Assign"
                okButtonProps={{ disabled: picked.length === 0, loading: assign.isPending }}
            >
                <Typography.Paragraph type="secondary">
                    Each person gets this as a draft line in their own IPCR, tied back to
                    this one. Progress here becomes the average of theirs.
                </Typography.Paragraph>
                <Select
                    mode="multiple"
                    style={{ width: "100%" }}
                    placeholder="Search for people"
                    value={picked}
                    onChange={setPicked}
                    optionFilterProp="label"
                    options={people.map((p) => ({
                        value: p.id,
                        label: p.position_title ? `${p.name} — ${p.position_title}` : p.name,
                    }))}
                />
            </Modal>

            {rating && (
                <Space wrap style={{ marginTop: 12 }}>
                    <Tag>Q {rating.q ?? "—"}</Tag>
                    <Tag>E {rating.e ?? "—"}</Tag>
                    <Tag>T {rating.t ?? "—"}</Tag>
                    {rating.remarks && (
                        <Typography.Text type="secondary">
                            <RichTextView html={rating.remarks} />
                        </Typography.Text>
                    )}
                </Space>
            )}

            <div style={{ marginTop: 12 }}>
                <Button size="small" type="link" onClick={() => setShowRemarks((v) => !v)}>
                    {showRemarks ? "Hide remarks" : "Remarks on this line"}
                </Button>
                {showRemarks && (
                    <div style={{ marginTop: 8 }}>
                        <CommentThread formId={formId} indicatorId={indicator.id} compact />
                    </div>
                )}
            </div>
        </Card>
    );
}
