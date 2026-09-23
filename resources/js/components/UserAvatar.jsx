import React from "react";
import { Avatar, Tooltip } from "antd";
import { UserOutlined } from "@ant-design/icons";
import { ROLE_LABELS } from "~/utils/constants";

const PALETTE = [
    "#1e3a72",
    "#2f6f9f",
    "#7a4f9c",
    "#a8552f",
    "#8a6d1f",
    "#4a5568",
];

const TITLES = /^(dr|engr|atty|prof|hon|rev|mr|ms|mrs)\.?$/i;
const EXTENSIONS = /^(jr|sr|ii|iii|iv|v|vi)\.?$/i;

/**
 * A name reads "Dr. Amabelle D. Pacana, CPA", so the first two words are the
 * title and the given name. The given name and the surname are what a person
 * recognises, so the title, the initial, the extension and the credentials
 * are all dropped before picking.
 */
function initials(name) {
    if (!name) return "";

    const parts = name
        .split(",")[0]
        .replace(/\(.*?\)/g, "")
        .split(/\s+/)
        .filter((part) => /\p{L}/u.test(part[0] ?? ""))
        .filter((part) => !TITLES.test(part) && !EXTENSIONS.test(part))
        .filter((part) => part.replace(/\./g, "").length > 1);

    const picked = parts.length > 1 ? [parts[0], parts[parts.length - 1]] : parts.slice(0, 1);

    return picked.map((part) => part[0].toUpperCase()).join("");
}

/**
 * A stable colour per person. The parameter default only covers `undefined`,
 * and plenty of callers pass an explicit null — a system-generated audit entry
 * has no author — so the empty case is handled inside.
 */
function tint(name) {
    const value = name ?? "";
    let hash = 0;

    for (let i = 0; i < value.length; i += 1) {
        hash = value.charCodeAt(i) + ((hash << 5) - hash);
    }

    return PALETTE[Math.abs(hash) % PALETTE.length];
}

export default function UserAvatar({
    user,
    name,
    image,
    role,
    size = "default",
    showTooltip = true,
    ...rest
}) {
    const label = user?.name ?? name;
    const src = user?.image ?? image;
    const userRole = user?.role ?? role;

    const avatar = (
        <Avatar
            size={size}
            src={src ? `/uploads/profile/${src}` : undefined}
            style={src ? undefined : { backgroundColor: tint(label), color: "#fff" }}
            icon={!src && !label ? <UserOutlined /> : undefined}
            {...rest}
        >
            {!src && initials(label)}
        </Avatar>
    );

    if (!showTooltip || !label) {
        return avatar;
    }

    return (
        <Tooltip title={userRole ? `${label} · ${ROLE_LABELS[userRole] ?? userRole}` : label}>
            {avatar}
        </Tooltip>
    );
}
