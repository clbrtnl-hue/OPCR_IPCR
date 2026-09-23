import React, { useMemo, useState } from "react";
import { Button, Card, Empty, Segmented, Skeleton, Space, Tag, Typography, message } from "antd";
import { CheckOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";
import { dayLabel, notificationMeta } from "~/utils/notifications";

const PAGE = 25;

export default function NotificationsPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [scope, setScope] = useState("all");
    const [kind, setKind] = useState("all");
    const [limit, setLimit] = useState(PAGE);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["notifications", "page", scope, kind, limit],
        queryFn: () =>
            api
                .get("notifications", {
                    params: {
                        limit,
                        unread: scope === "unread" ? 1 : undefined,
                        type: kind === "all" ? undefined : kind,
                    },
                })
                .then((r) => r.data),
        placeholderData: (previous) => previous,
    });

    const items = data?.items ?? [];
    const unread = data?.unread ?? 0;
    const total = data?.total ?? 0;

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ["notifications"] });

    const markRead = useMutation({
        mutationFn: (id) => api.post(`notifications/${id}/read`),
        onSuccess: invalidate,
    });

    const markAll = useMutation({
        mutationFn: () => api.post("notifications/read-all"),
        onSuccess: () => {
            message.success("Everything is marked read.");
            invalidate();
        },
    });

    const kinds = useMemo(() => {
        const counts = data?.kinds ?? {};

        return [
            { value: "all", label: `All ${Object.values(counts).reduce((a, b) => a + b, 0)}` },
            ...Object.entries(counts).map(([type, count]) => ({
                value: type,
                label: `${notificationMeta(type).label} ${count}`,
            })),
        ];
    }, [data?.kinds]);

    const open = (item) => {
        if (!item.read_at) {
            markRead.mutate(item.id);
        }

        if (item.link) {
            navigate(item.link);
        }
    };

    const now = dayjs();
    let lastDay = null;

    return (
        <>
            <PageHeader
                title="Notifications"
                subtitle={
                    unread > 0
                        ? `${unread} unread — everything the system has told you, newest first.`
                        : "Everything the system has told you, newest first."
                }
                extra={
                    <Space wrap>
                        <Segmented
                            value={scope}
                            onChange={(value) => {
                                setScope(value);
                                setLimit(PAGE);
                            }}
                            options={[
                                { value: "all", label: "All" },
                                { value: "unread", label: `Unread ${unread}` },
                            ]}
                        />
                        <Button
                            icon={<CheckOutlined />}
                            disabled={unread === 0}
                            loading={markAll.isPending}
                            onClick={() => markAll.mutate()}
                        >
                            Mark all read
                        </Button>
                    </Space>
                }
            />

            <Card>
                {kinds.length > 2 && (
                    <Segmented
                        size="small"
                        value={kind}
                        onChange={(value) => {
                            setKind(value);
                            setLimit(PAGE);
                        }}
                        options={kinds}
                        style={{ marginBottom: 14 }}
                    />
                )}

                {isLoading ? (
                    <Skeleton active paragraph={{ rows: 6 }} />
                ) : items.length === 0 ? (
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={scope === "unread" ? "Nothing unread." : "Nothing has come in yet."}
                    />
                ) : (
                    <div style={{ opacity: isFetching ? 0.7 : 1, transition: "opacity 0.2s" }}>
                        {items.map((item) => {
                            const meta = notificationMeta(item.type);
                            const at = dayjs(item.created_at);
                            const label = dayLabel(at, now);
                            const heading = label !== lastDay ? label : null;

                            lastDay = label;

                            return (
                                <React.Fragment key={item.id}>
                                    {heading && <div className="pms-note-day">{heading}</div>}

                                    <button
                                        type="button"
                                        className={item.read_at ? "pms-note pms-note-row" : "pms-note pms-note-row is-unread"}
                                        onClick={() => open(item)}
                                    >
                                        <span className="pms-note-avatar">
                                            <UserAvatar name={item.actor_name ?? "System"} size={34} showTooltip={false} />
                                            <span className="pms-note-badge" style={{ color: meta.color }}>
                                                {meta.icon}
                                            </span>
                                        </span>

                                        <span className="pms-note-body">
                                            <span className="pms-note-title">
                                                {item.title}
                                                <Tag color="default" style={{ marginInlineStart: 8 }}>
                                                    {meta.label}
                                                </Tag>
                                            </span>
                                            {item.body && <span className="pms-note-text">{item.body}</span>}
                                            <span className="pms-note-meta">
                                                {item.actor_name ? `${item.actor_name} · ` : ""}
                                                {at.format("MMM D, YYYY h:mm A")} · {at.fromNow()}
                                            </span>
                                        </span>

                                        {!item.read_at && <span className="pms-note-dot" />}
                                    </button>
                                </React.Fragment>
                            );
                        })}

                        {items.length < total && (
                            <Button block style={{ marginTop: 12 }} onClick={() => setLimit(limit + PAGE)}>
                                Show older ({total - items.length} more)
                            </Button>
                        )}

                        <Typography.Paragraph type="secondary" style={{ marginTop: 12, fontSize: 12 }}>
                            Showing {items.length} of {total}.
                        </Typography.Paragraph>
                    </div>
                )}
            </Card>
        </>
    );
}
