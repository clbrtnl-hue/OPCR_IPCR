import React, { useState } from "react";
import { Badge, Button, Empty, Popover, Typography } from "antd";
import { BellOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import api from "~/utils/api";
import { notificationMeta } from "~/utils/notifications";

dayjs.extend(relativeTime);

export default function NotificationBell() {
    const [open, setOpen] = useState(false);
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const { data } = useQuery({
        queryKey: ["notifications", "bell"],
        queryFn: () => api.get("notifications?limit=6").then((r) => r.data),
        refetchInterval: 30000,
    });

    const items = data?.items ?? [];
    const unread = data?.unread ?? 0;

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ["notifications"] });

    const markRead = useMutation({
        mutationFn: (id) => api.post(`notifications/${id}/read`),
        onSuccess: invalidate,
    });

    const markAll = useMutation({
        mutationFn: () => api.post("notifications/read-all"),
        onSuccess: invalidate,
    });

    const openItem = (item) => {
        if (!item.read_at) {
            markRead.mutate(item.id);
        }

        setOpen(false);
        navigate(item.link ?? "/notifications");
    };

    const panel = (
        <div className="pms-bell-panel">
            <div className="pms-bell-head">
                <Typography.Text strong>Notifications</Typography.Text>
                {unread > 0 && (
                    <Button type="link" size="small" loading={markAll.isPending} onClick={() => markAll.mutate()}>
                        Mark all read
                    </Button>
                )}
            </div>

            {items.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing new." style={{ margin: "18px 0" }} />
            ) : (
                <div className="pms-bell-list">
                    {items.map((item) => {
                        const meta = notificationMeta(item.type);

                        return (
                            <button
                                key={item.id}
                                type="button"
                                className={item.read_at ? "pms-note" : "pms-note is-unread"}
                                onClick={() => openItem(item)}
                            >
                                <span className="pms-note-icon" style={{ color: meta.color }}>
                                    {meta.icon}
                                </span>
                                <span className="pms-note-body">
                                    <span className="pms-note-title">{item.title}</span>
                                    {item.body && <span className="pms-note-text">{item.body}</span>}
                                    <span className="pms-note-meta">
                                        {item.actor_name ? `${item.actor_name} · ` : ""}
                                        {dayjs(item.created_at).fromNow()}
                                    </span>
                                </span>
                                {!item.read_at && <span className="pms-note-dot" />}
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="pms-bell-foot">
                <Button
                    type="link"
                    size="small"
                    onClick={() => {
                        setOpen(false);
                        navigate("/notifications");
                    }}
                >
                    See all notifications
                </Button>
            </div>
        </div>
    );

    return (
        <Popover
            open={open}
            onOpenChange={setOpen}
            trigger="click"
            placement="bottomRight"
            arrow={false}
            content={panel}
            styles={{ body: { padding: 0 } }}
        >
            <Badge count={unread} size="small" offset={[-2, 2]}>
                <Button type="text" icon={<BellOutlined style={{ fontSize: 18 }} />} />
            </Badge>
        </Popover>
    );
}
