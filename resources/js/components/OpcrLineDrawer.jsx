import React, { useEffect, useRef, useState } from "react";
import { Button, Divider, Space, Tag, Tooltip, Typography, Upload, message } from "antd";
import {
    ClockCircleOutlined,
    ExclamationCircleOutlined,
    SaveOutlined,
    UploadOutlined,
} from "@ant-design/icons";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { DELAY_META, NARRATIVE_MAX, UPLOAD_ACCEPT, UPLOAD_MAX_MB } from "~/utils/constants";
import CommentThread from "~/components/CommentThread";
import DeliveredBy from "~/components/DeliveredBy";
import ProgressCell from "~/components/ProgressCell";
import RichText from "~/components/RichText";
import RichTextView from "~/components/RichTextView";
import AccomplishmentView, { AttachmentGallery } from "~/components/AccomplishmentView";

/**
 * Office-level actual accomplishments live on this OPCR line. They are what QA
 * rates and are not copied onto anyone's IPCR. Delivered by stays the rollup.
 */
export default function OpcrLineDrawer({
    line,
    output,
    form,
    periodId,
    canAssign = false,
    canRecordProgress = false,
}) {
    const queryClient = useQueryClient();
    const rating = (line.ratings ?? []).find((r) => r.rating_period_id === periodId);
    const accomplishment = (line.accomplishments ?? []).find((a) => a.rating_period_id === periodId);
    const hasDelegatedHeading = Number(output?.delegated_outputs_count ?? 0) > 0;
    const serverText = accomplishment?.actual_accomplishment ?? "";
    const [text, setText] = useState(serverText);
    const seenServer = useRef(serverText);

    useEffect(() => {
        setText((current) => (current === seenServer.current ? serverText : current));
        seenServer.current = serverText;
    }, [serverText, line.id, periodId]);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });

    const saveAccomplishment = useMutation({
        mutationFn: () =>
            api.post("pcr-accomplishments", {
                indicator_id: line.id,
                rating_period_id: periodId,
                actual_accomplishment: text,
            }),
        onSuccess: () => {
            message.success("Office accomplishment saved.");
            refresh();
        },
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
        body.append("indicator_id", line.id);
        body.append("rating_period_id", periodId);
        body.append("file", file);

        try {
            await api.post("pcr-attachments", body);
            message.success(`${file.name} attached.`);
            refresh();
        } catch {
            message.error(`${file.name} could not be uploaded.`);
        }

        return Upload.LIST_IGNORE;
    };

    return (
        <>
            <RichTextView html={line.description} />

            <Space wrap size={8} style={{ marginTop: 12 }}>
                {line.delay && line.delay.state !== "no_date" && (
                    <Tooltip title={line.delay.label}>
                        <Tag
                            color={DELAY_META[line.delay.state]?.color}
                            icon={
                                ["overdue", "late"].includes(line.delay.state) ? (
                                    <ExclamationCircleOutlined />
                                ) : (
                                    <ClockCircleOutlined />
                                )
                            }
                        >
                            {DELAY_META[line.delay.state]?.label}
                        </Tag>
                    </Tooltip>
                )}
                {line.target_date && (
                    <Typography.Text type="secondary">
                        Target date: {dayjs(line.target_date).format("MMM D, YYYY")}
                    </Typography.Text>
                )}
                {line.allotted_budget != null && (
                    <Typography.Text type="secondary">
                        Budget: ₱{Number(line.allotted_budget).toLocaleString()}
                    </Typography.Text>
                )}
            </Space>

            <div style={{ marginTop: 12, maxWidth: 260 }}>
                <ProgressCell
                    status={line.progress_status}
                    pct={line.progress_pct}
                    computed
                    computedHint={
                        (line.children ?? []).length > 0 || hasDelegatedHeading
                            ? "Rolled up from the commitments written against this line."
                            : "100% once the actual accomplishment is written and a file is attached."
                    }
                />
            </div>

            {rating?.a != null && (
                <Space wrap size={6} style={{ marginTop: 12 }}>
                    <Tag>Q {rating.q ?? "—"}</Tag>
                    <Tag>E {rating.e ?? "—"}</Tag>
                    <Tag>T {rating.t ?? "—"}</Tag>
                    <Tag color="purple">A = {Number(rating.a).toFixed(2)}</Tag>
                </Space>
            )}

            <Divider orientation="left" orientationMargin={0}>
                Actual accomplishment
            </Divider>

            <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                Office-level summary for this target. QA rates this text. It is not copied onto
                anyone&apos;s IPCR.
            </Typography.Paragraph>

            {canRecordProgress ? (
                <>
                    <RichText
                        rows={4}
                        value={text}
                        onChange={setText}
                        maxLength={NARRATIVE_MAX}
                        placeholder="What did the college deliver against this target?"
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
                    <AttachmentGallery
                        files={accomplishment?.attachments}
                        canRemove
                        onRemove={(id) => removeAttachment.mutate(id)}
                    />
                </>
            ) : (
                <AccomplishmentView
                    html={accomplishment?.actual_accomplishment}
                    attachments={accomplishment?.attachments}
                    empty="Nothing recorded yet."
                />
            )}

            <Divider orientation="left" orientationMargin={0}>
                Delivered by
            </Divider>

            <DeliveredBy
                indicatorId={line.id}
                formId={form.id}
                periodId={periodId}
                canAssign={canAssign}
                hasDelegatedHeading={hasDelegatedHeading}
            />

            <Divider orientation="left" orientationMargin={0}>
                Remarks on this office target
            </Divider>

            <CommentThread formId={form.id} indicatorId={line.id} compact />
        </>
    );
}
