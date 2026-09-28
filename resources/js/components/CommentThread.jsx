import React, { useMemo, useState } from "react";
import { Button, Space, Typography } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import CommentList from "~/components/CommentList";
import RichText from "~/components/RichText";
import { toPlainText } from "~/components/RichTextView";

function remarksClosed(form) {
    const periods = form?.school_year?.periods ?? [];

    if (form?.type === "ipcr") {
        const period = periods.find((row) => Number(row.id) === Number(form.rating_period_id));

        return period?.status === "closed";
    }

    return periods.length > 0 && periods.every((row) => row.status === "closed");
}

export default function CommentThread({ formId, indicatorId = null, compact = false }) {
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(formId)],
        queryFn: () => api.get(`pcr-forms/${formId}`).then((r) => r.data),
        enabled: Boolean(formId),
    });
    const closed = remarksClosed(form);
    const queryClient = useQueryClient();
    const [body, setBody] = useState("");

    const { data: comments = [], isLoading } = useQuery({
        queryKey: ["comments", formId],
        queryFn: () => api.get(`pcr-forms/${formId}/comments`).then((r) => r.data),
    });

    const { data: people = [] } = useQuery({
        queryKey: ["mentionables", formId],
        queryFn: () => api.get(`pcr-forms/${formId}/mentionables`).then((r) => r.data),
        enabled: !viewOnly && !closed,
    });

    // Whoever the remark actually links to — reading the ids back beats
    // matching names, which two people can share.
    const mentionedIds = useMemo(() => {
        const ids = [...body.matchAll(/\/people\/(\d+)/g)].map((m) => Number(m[1]));

        return [...new Set(ids)];
    }, [body]);

    const post = useMutation({
        mutationFn: () =>
            api.post("pcr-comments", {
                form_id: formId,
                indicator_id: indicatorId,
                body,
                mentions: mentionedIds,
            }),
        onSuccess: () => {
            setBody("");
            queryClient.invalidateQueries({ queryKey: ["comments", formId] });
        },
    });

    const visible = comments.filter((c) =>
        indicatorId ? c.indicator_id === indicatorId : !c.indicator_id
    );

    return (
        <div>
            {!isLoading && (
                <CommentList
                    comments={visible}
                    compact={compact}
                    emptyText={indicatorId ? "No remarks on this line yet." : "No remarks yet."}
                />
            )}

            {!viewOnly && closed && (
                <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
                    {form?.type === "opcr"
                        ? "This school year is closed. Remarks can no longer be added."
                        : "This period is closed. Remarks can no longer be added."}
                </Typography.Paragraph>
            )}

            {!viewOnly && !closed && (
            <div style={{ marginTop: 12 }}>
                <RichText
                    rows={compact ? 2 : 3}
                    value={body}
                    onChange={setBody}
                    mentions={people}
                    placeholder={
                        indicatorId
                            ? "Add a remark about this success indicator — type @ to tag someone"
                            : "Add a remark — type @ to tag someone"
                    }
                />
            </div>
            )}

            {!viewOnly && !closed && (
            <Space style={{ marginTop: 8 }} wrap>
                <Button
                    type="primary"
                    disabled={!toPlainText(body)}
                    loading={post.isPending}
                    onClick={() => post.mutate()}
                >
                    Post remark
                </Button>
                {mentionedIds.length > 0 && (
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {mentionedIds.length} person{mentionedIds.length === 1 ? "" : "s"} will be notified
                    </Typography.Text>
                )}
            </Space>
            )}
        </div>
    );
}
