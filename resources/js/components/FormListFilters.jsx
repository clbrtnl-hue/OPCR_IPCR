import React from "react";
import { Select, Space } from "antd";
import { STATUS_META, statusMeta } from "~/utils/constants";

export const STATUS_ORDER = Object.keys(STATUS_META);

export function matchesListKind(row, formKind, statusFilter = "all") {
    if (formKind !== "all" && row.type !== formKind) return false;
    if (statusFilter !== "all" && row.status !== statusFilter) return false;

    return true;
}

export function statusRank(status) {
    const index = STATUS_ORDER.indexOf(status);

    return index < 0 ? STATUS_ORDER.length : index;
}

export function byStatusThenYear(a, b) {
    const byStatus = statusRank(a.status) - statusRank(b.status);

    if (byStatus) return byStatus;

    const year = String(b.school_year?.label ?? "").localeCompare(String(a.school_year?.label ?? ""), undefined, {
        numeric: true,
        sensitivity: "base",
    });

    return year || (a.rating_period?.seq ?? 0) - (b.rating_period?.seq ?? 0) || a.id - b.id;
}

export default function FormListFilters({ rows, formKind, onFormKind, statusFilter = "all", onStatusFilter }) {
    const types = [...new Set(rows.map((row) => row.type).filter(Boolean))];
    const statuses = [...new Set(rows.map((row) => row.status).filter(Boolean))].sort(
        (left, right) => statusRank(left) - statusRank(right)
    );
    const showType = types.length > 1;
    const showStatus = Boolean(onStatusFilter) && statuses.length > 0;

    if (!showType && !showStatus) {
        return null;
    }

    return (
        <Space wrap className="pms-form-filters">
            {showType && (
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
            {showStatus && (
                <Select
                    value={statusFilter}
                    onChange={onStatusFilter}
                    style={{ minWidth: 200 }}
                    options={[
                        { value: "all", label: "All statuses" },
                        ...statuses.map((status) => ({
                            value: status,
                            label: statusMeta(status).label,
                        })),
                    ]}
                />
            )}
        </Space>
    );
}
