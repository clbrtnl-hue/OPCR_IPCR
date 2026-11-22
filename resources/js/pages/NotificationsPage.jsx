import React, { useEffect, useMemo, useState } from "react";
import { Button, Card, Empty, Segmented, Skeleton, Space, Tabs, Tag, message } from "antd";
import { CheckOutlined, DeleteOutlined, UndoOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";
import { dayLabel, notificationHref, notificationMeta, notificationNavState } from "~/utils/notifications";
import ListPager from "~/components/ListPager";

const PAGE = 25;

function AlertList({ items, isFetching, open, onTrash, onRestore, trashing, restoring, trashed, web }) {
    const now = dayjs();
    let lastDay = null;

    return (
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

                        <div
                            role="button"
                            tabIndex={0}
                            className={item.read_at ? "pms-note pms-note-row" : "pms-note pms-note-row is-unread"}
                            onClick={() => open(item)}
                            onKeyDown={(event) => {
                                if (event.target !== event.currentTarget) {
                                    return;
                                }
                                if (event.key === "Enter" || event.key === " ") {
                                    event.preventDefault();
                                    open(item);
                                }
                            }}
                        >
                            <span className="pms-note-avatar">
                                <UserAvatar name={item.actor_name ?? "System"} size={34} showTooltip={false} />
                                <span className="pms-note-badge" style={{ color: meta.color }}>
                                    {meta.icon}
                                </span>
                            </span>

                            <span className="pms-note-body">
                                <span className="pms-note-title">
                                    {web ? (
                                        item.title
                                    ) : (
                                        <span>{item.title}</span>
                                    )}
                                    {web ? (
                                        <Tag color="default" style={{ marginInlineStart: 8 }}>
                                            {meta.label}
                                        </Tag>
                                    ) : (
                                        <Tag>{meta.label}</Tag>
                                    )}
                                </span>
                                {item.body && <span className="pms-note-text">{item.body}</span>}
                                <span className="pms-note-meta">
                                    {item.actor_name ? `${item.actor_name} · ` : ""}
                                    {at.format("MMM D, YYYY h:mm A")} · {at.fromNow()}
                                </span>
                            </span>

                            {!item.read_at && <span className="pms-note-dot" />}
                            <Button
                                type="text"
                                className={trashed ? "pms-note-action is-restore" : "pms-note-action"}
                                icon={trashed ? <UndoOutlined /> : <DeleteOutlined />}
                                aria-label={trashed ? "Restore notification" : "Move notification to Trash"}
                                title={trashed ? "Restore" : "Move to Trash"}
                                loading={trashing === item.id || restoring === item.id}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    if (trashed) {
                                        onRestore(item);
                                    } else {
                                        onTrash(item);
                                    }
                                }}
                            />
                        </div>
                    </React.Fragment>
                );
            })}

        </div>
    );
}

export default function NotificationsPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [scope, setScope] = useState("all");
    const [kind, setKind] = useState("all");
    const [page, setPage] = useState(1);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["notifications", "page", scope, kind, page],
        queryFn: () =>
            api
                .get("notifications", {
                    params: {
                        limit: PAGE,
                        offset: (page - 1) * PAGE,
                        unread: scope === "unread" ? 1 : undefined,
                        trash: scope === "trash" ? 1 : undefined,
                        type: kind === "all" ? undefined : kind,
                    },
                })
                .then((r) => r.data),
        placeholderData: (previous) => previous,
    });

    const items = data?.items ?? [];
    const unread = data?.unread ?? 0;
    const inboxCount = data?.inbox ?? 0;
    const trashCount = data?.trash ?? 0;
    const total = data?.total ?? 0;
    const pageCount = Math.max(1, Math.ceil(total / PAGE) || 1);

    useEffect(() => {
        if (page > pageCount) {
            setPage(pageCount);
        }
    }, [page, pageCount]);
    const inTrash = scope === "trash";

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

    const trash = useMutation({
        mutationFn: (id) => api.post(`notifications/${id}/trash`),
        onSuccess: () => {
            message.success("Moved to Trash. You can restore it for 30 days.");
            invalidate();
        },
    });

    const trashAll = useMutation({
        mutationFn: () => api.post("notifications/trash-all"),
        onSuccess: () => {
            message.success("All notifications moved to Trash. You can restore them for 30 days.");
            invalidate();
        },
    });

    const restore = useMutation({
        mutationFn: (id) => api.post(`notifications/${id}/restore`),
        onSuccess: () => {
            message.success("Restored to your inbox.");
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

        const href = notificationHref(item);

        if (href) {
            navigate(href, { state: notificationNavState(item) });
        }
    };

    const kindTabs = () => (
        <Tabs
            className="pms-line-tabs"
            activeKey={kind}
            onChange={(value) => {
                setKind(value);
                setPage(1);
            }}
            items={kinds.map((item) => ({ key: item.value, label: item.label }))}
        />
    );

    const scopeControl = () => (
        <Segmented
            value={scope}
            onChange={(value) => {
                setScope(value);
                setKind("all");
                setPage(1);
            }}
            options={[
                { value: "all", label: "All" },
                { value: "unread", label: `Unread ${unread}` },
                { value: "trash", label: `Trash ${trashCount}` },
            ]}
        />
    );

    const markAllButton = () => (
        <Button
            icon={<CheckOutlined />}
            disabled={unread === 0 || inTrash}
            loading={markAll.isPending}
            onClick={() => markAll.mutate()}
        >
            Mark all read
        </Button>
    );

    const deleteAllButton = () => (
        <Button
            icon={<DeleteOutlined />}
            danger
            disabled={inboxCount === 0}
            loading={trashAll.isPending}
            onClick={() => trashAll.mutate()}
        >
            Delete all
        </Button>
    );

    const body = isLoading ? (
        <Skeleton active paragraph={{ rows: 6 }} />
    ) : items.length === 0 ? (
        <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description={inTrash ? "Trash is empty." : scope === "unread" ? "Nothing unread." : "Nothing has come in yet."}
        />
    ) : (
        <AlertList
            items={items}
            isFetching={isFetching}
            open={open}
            onTrash={(item) => trash.mutate(item.id)}
            onRestore={(item) => restore.mutate(item.id)}
            trashing={trash.isPending ? trash.variables : null}
            restoring={restore.isPending ? restore.variables : null}
            trashed={inTrash}
            web
        />
    );

    const mobileBody = isLoading ? (
        <Skeleton active paragraph={{ rows: 6 }} />
    ) : items.length === 0 ? (
        <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description={inTrash ? "Trash is empty." : scope === "unread" ? "Nothing unread." : "Nothing has come in yet."}
        />
    ) : (
        <AlertList
            items={items}
            isFetching={isFetching}
            open={open}
            onTrash={(item) => trash.mutate(item.id)}
            onRestore={(item) => restore.mutate(item.id)}
            trashing={trash.isPending ? trash.variables : null}
            restoring={restore.isPending ? restore.variables : null}
            trashed={inTrash}
        />
    );

    return (
        <>
            <div className="pms-desktop-only">
                <PageHeader
                    title="Notifications"
                    subtitle={
                        inTrash
                            ? "Deleted notifications stay here for 30 days, then they are removed."
                            : unread > 0
                              ? `${unread} unread — everything the system has told you, newest first.`
                              : "Everything the system has told you, newest first."
                    }
                    extra={
                        <Space wrap>
                            {scopeControl()}
                            {!inTrash && markAllButton()}
                            {!inTrash && deleteAllButton()}
                        </Space>
                    }
                />

                <Card className="pms-list-panel pms-list-fill">
                    {kinds.length > 2 && kindTabs()}
                    <div className="pms-paged">
                        {body}
                        <ListPager page={page} pageSize={PAGE} total={total} onChange={setPage} />
                    </div>
                </Card>
            </div>

            <div className="pms-alerts pms-mobile-only">
                <PageHeader
                    title="Alerts"
                    subtitle={
                        inTrash
                            ? "Stay here for 30 days, then they are removed."
                            : unread > 0
                              ? `${unread} unread, newest first.`
                              : "Everything the system has told you, newest first."
                    }
                />

                <div className="pms-alerts-bar">
                    {scopeControl()}
                    {!inTrash && (
                        <div className="pms-alerts-actions">
                            {markAllButton()}
                            {deleteAllButton()}
                        </div>
                    )}
                </div>

                <Card className="pms-alerts-card">
                    {kinds.length > 2 && (
                        <div className="pms-alerts-kinds">{kindTabs()}</div>
                    )}
                    {mobileBody}
                    <ListPager page={page} pageSize={PAGE} total={total} onChange={setPage} />
                </Card>
            </div>
        </>
    );
}
