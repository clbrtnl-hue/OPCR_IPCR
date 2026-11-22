import React, { useEffect, useRef, useState } from "react";
import { CloseOutlined } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { notificationHref, notificationMeta, notificationNavState } from "~/utils/notifications";

const VISIBLE_MS = 5000;
const LEAVE_MS = 360;
const LIMIT = 3;

export default function NotificationToasts({ userId, items }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const known = useRef(null);
    const timers = useRef(new Map());
    const [toasts, setToasts] = useState([]);

    useEffect(() => {
        return () => {
            for (const timer of timers.current.values()) {
                clearTimeout(timer.leave);
                clearTimeout(timer.remove);
            }
            timers.current.clear();
        };
    }, []);

    useEffect(() => {
        if (!userId || !items) {
            known.current = null;
            setToasts([]);
            return;
        }

        if (known.current === null) {
            known.current = new Set(items.map((item) => item.id));
            return;
        }

        const arrived = items.filter((item) => !item.read_at && !known.current.has(item.id));

        for (const item of items) {
            known.current.add(item.id);
        }

        if (arrived.length === 0) {
            return;
        }

        setToasts((current) => {
            const next = [];
            const seen = new Set();

            for (const item of [...arrived, ...current]) {
                if (seen.has(item.id)) {
                    continue;
                }

                seen.add(item.id);
                next.push({ ...item, leaving: false });

                if (next.length === LIMIT) {
                    break;
                }
            }

            return next;
        });
    }, [items, userId]);

    useEffect(() => {
        const visible = new Set(toasts.map((item) => item.id));

        for (const [id, timer] of timers.current) {
            if (!visible.has(id)) {
                clearTimeout(timer.leave);
                clearTimeout(timer.remove);
                timers.current.delete(id);
            }
        }

        for (const toast of toasts) {
            if (timers.current.has(toast.id) || toast.leaving) {
                continue;
            }

            const leave = window.setTimeout(() => {
                setToasts((current) =>
                    current.map((item) => (item.id === toast.id ? { ...item, leaving: true } : item))
                );
            }, VISIBLE_MS);

            const remove = window.setTimeout(() => {
                setToasts((current) => current.filter((item) => item.id !== toast.id));
                timers.current.delete(toast.id);
            }, VISIBLE_MS + LEAVE_MS);

            timers.current.set(toast.id, { leave, remove });
        }
    }, [toasts]);

    const dismiss = (id) => {
        const timer = timers.current.get(id);

        if (timer) {
            clearTimeout(timer.leave);
            clearTimeout(timer.remove);
        }

        setToasts((current) => current.map((item) => (item.id === id ? { ...item, leaving: true } : item)));

        const remove = window.setTimeout(() => {
            setToasts((current) => current.filter((item) => item.id !== id));
            timers.current.delete(id);
        }, LEAVE_MS);

        timers.current.set(id, { leave: remove, remove });
    };

    const open = (item) => {
        dismiss(item.id);

        if (!item.read_at) {
            api.post(`notifications/${item.id}/read`).finally(() => {
                queryClient.invalidateQueries({ queryKey: ["notifications"] });
            });
        }

        const href = notificationHref(item);

        if (href) {
            navigate(href, { state: notificationNavState(item) });
        }
    };

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="pms-toasts" aria-live="polite">
            {toasts.map((item) => {
                const meta = notificationMeta(item.type);

                return (
                    <div
                        key={item.id}
                        className={item.leaving ? "pms-toast is-leaving" : "pms-toast"}
                        role="button"
                        tabIndex={0}
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
                        <span className="pms-toast-icon" style={{ color: meta.color }}>
                            {meta.icon}
                        </span>
                        <span className="pms-toast-copy">
                            <span className="pms-toast-kicker">{meta.label}</span>
                            <span className="pms-toast-title">{item.title}</span>
                            {item.body && <span className="pms-toast-text">{item.body}</span>}
                        </span>
                        <button
                            type="button"
                            className="pms-toast-close"
                            aria-label="Dismiss notification"
                            onClick={(event) => {
                                event.stopPropagation();
                                dismiss(item.id);
                            }}
                        >
                            <CloseOutlined />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
