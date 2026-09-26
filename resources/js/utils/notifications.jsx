import React from "react";
import {
    BellOutlined,
    CalendarOutlined,
    CheckCircleOutlined,
    LockOutlined,
    ClockCircleOutlined,
    MessageOutlined,
    NotificationOutlined,
    SafetyCertificateOutlined,
    StarOutlined,
    TrophyOutlined,
    UndoOutlined,
    UserAddOutlined,
    WarningOutlined,
} from "@ant-design/icons";

export const NOTIFICATION_META = {
    assignment: { label: "Assignment", color: "#2a78d6", icon: <UserAddOutlined /> },
    mention: { label: "Mention", color: "#eb2f96", icon: <MessageOutlined /> },
    comment: { label: "Remark", color: "#1baf7a", icon: <MessageOutlined /> },
    review: { label: "For review", color: "#2f54eb", icon: <NotificationOutlined /> },
    approval: { label: "For approval", color: "#d48806", icon: <SafetyCertificateOutlined /> },
    rating: { label: "For rating", color: "#722ed1", icon: <StarOutlined /> },
    rated: { label: "Rated", color: "#13c2c2", icon: <TrophyOutlined /> },
    approved: { label: "Approved", color: "#389e0d", icon: <CheckCircleOutlined /> },
    published: { label: "Published", color: "#1e3a72", icon: <NotificationOutlined /> },
    returned: { label: "Returned", color: "#d4380d", icon: <UndoOutlined /> },
    due_soon: { label: "Due soon", color: "#d48806", icon: <ClockCircleOutlined /> },
    due_today: { label: "Due today", color: "#d46b08", icon: <ClockCircleOutlined /> },
    overdue: { label: "Overdue", color: "#d4380d", icon: <WarningOutlined /> },
    period: { label: "Period", color: "#1e3a72", icon: <CalendarOutlined /> },
    unclosed: { label: "Needs closing", color: "#1e3a72", icon: <LockOutlined /> },
    default: { label: "Update", color: "#8c8c8c", icon: <BellOutlined /> },
};

export function notificationMeta(type) {
    return NOTIFICATION_META[type] ?? { ...NOTIFICATION_META.default, label: type ?? "Update" };
}

export function dayLabel(dayjsValue, now) {
    if (dayjsValue.isSame(now, "day")) return "Today";
    if (dayjsValue.isSame(now.subtract(1, "day"), "day")) return "Yesterday";
    if (dayjsValue.isSame(now, "year")) return dayjsValue.format("dddd, MMMM D");

    return dayjsValue.format("MMMM D, YYYY");
}
