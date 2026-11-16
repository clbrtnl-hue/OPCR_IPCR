import React from "react";
import { Select, Space } from "antd";
import { STATUS_META, statusMeta } from "~/utils/constants";

function statusChoices(rows) {
    const present = [...new Set(rows.map((row) => row.status).filter(Boolean))];
    const known = Object.keys(STATUS_META).filter((status) => present.includes(status));
    const extra = present.filter((status) => !STATUS_META[status]);

    return [...known, ...extra].map((status) => ({
        value: status,
        label: statusMeta(status).label,
    }));
}

export function matchesListKind(row, formKind, statusKind) {
    if (formKind !== "all" && row.type !== formKind) return false;
    if (statusKind !== "all" && row.status !== statusKind) return false;

    return true;
}

export default function FormListFilters({ rows, formKind, statusKind, onFormKind, onStatusKind }) {
    const types = [...new Set(rows.map((row) => row.type).filter(Boolean))];
    const statuses = statusChoices(rows);

    if (types.length < 2 && statuses.length < 2) {
        return null;
    }

    const statusValue = statuses.some((item) => item.value === statusKind) ? statusKind : "all";

    return (
        <Space wrap style={{ marginBottom: 16 }}>
            {types.length > 1 && (
                <Select
                    value={formKind}
                    onChange={onFormKind}
                    style={{ minWidth: 150 }}
                    options={[
                        { value: "all", label: "All forms" },
                        ...types.map((type) => ({ value: type, label: type.toUpperCase() })),
                    ]}
                />
            )}
            {statuses.length > 1 && (
                <Select
                    value={statusValue}
                    onChange={onStatusKind}
                    style={{ minWidth: 220 }}
                    options={[{ value: "all", label: "All statuses" }, ...statuses]}
                />
            )}
        </Space>
    );
}
