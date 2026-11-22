import React from "react";
import { Select, Space } from "antd";

export function matchesListKind(row, formKind) {
    if (formKind !== "all" && row.type !== formKind) return false;

    return true;
}

export default function FormListFilters({ rows, formKind, onFormKind }) {
    const types = [...new Set(rows.map((row) => row.type).filter(Boolean))];

    if (types.length < 2) {
        return null;
    }

    return (
        <Space wrap className="pms-form-filters">
            <Select
                value={formKind}
                onChange={onFormKind}
                style={{ minWidth: 150 }}
                options={[
                    { value: "all", label: "All forms" },
                    ...types.map((type) => ({ value: type, label: type.toUpperCase() })),
                ]}
            />
        </Space>
    );
}
