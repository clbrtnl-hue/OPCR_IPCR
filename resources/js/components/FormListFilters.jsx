import React from "react";
import { Select, Space } from "antd";
import { STATUS_META, statusMeta } from "~/utils/constants";

export const STATUS_ORDER = Object.keys(STATUS_META);

export function formYearId(form) {
    const id = form?.school_year_id ?? form?.school_year?.id;

    return id == null || id === "" ? null : Number(id);
}

export function matchesListKind(row, formKind, statusFilter = "all", yearFilter = "all") {
    if (formKind !== "all" && row.type !== formKind) return false;
    if (statusFilter !== "all" && row.status !== statusFilter) return false;
    if (yearFilter !== "all" && yearFilter != null) {
        const id = row.school_year_id ?? row.school_year?.id;

        if (Number(id) !== Number(yearFilter)) return false;
    }

    return true;
}

export function statusRank(status) {
    const index = STATUS_ORDER.indexOf(status);

    return index < 0 ? STATUS_ORDER.length : index;
}

function compareYearLabel(a, b) {
    return String(b.school_year?.label ?? "").localeCompare(String(a.school_year?.label ?? ""), undefined, {
        numeric: true,
        sensitivity: "base",
    });
}

/** Active school year first, then newer labels. */
function yearDelta(a, b, activeYearId) {
    const rank = (row) => {
        const id = row.school_year_id ?? row.school_year?.id;

        return activeYearId && Number(id) === Number(activeYearId) ? 0 : 1;
    };

    return rank(a) - rank(b) || compareYearLabel(a, b);
}

/** Active school year first, then newer labels, then the review period. */
export function byActiveYear(a, b, activeYearId) {
    return yearDelta(a, b, activeYearId) || (a.rating_period?.seq ?? 0) - (b.rating_period?.seq ?? 0) || a.id - b.id;
}

/** Active year first, then status inside that year. */
export function byYearThenStatus(a, b, activeYearId) {
    const year = yearDelta(a, b, activeYearId);

    if (year) return year;

    return statusRank(a.status) - statusRank(b.status) || (a.rating_period?.seq ?? 0) - (b.rating_period?.seq ?? 0) || a.id - b.id;
}

export function yearChoices(years) {
    return [...years].sort((a, b) => {
        if (Boolean(a.is_active) !== Boolean(b.is_active)) return a.is_active ? -1 : 1;

        return String(b.label ?? "").localeCompare(String(a.label ?? ""), undefined, {
            numeric: true,
            sensitivity: "base",
        });
    });
}

export default function FormListFilters({
    rows,
    formKind,
    onFormKind,
    statusFilter = "all",
    onStatusFilter,
    years = [],
    yearFilter = "all",
    onYearFilter,
}) {
    const types = [...new Set(rows.map((row) => row.type).filter(Boolean))];
    const statuses = [...new Set(rows.map((row) => row.status).filter(Boolean))].sort(
        (left, right) => statusRank(left) - statusRank(right)
    );
    const listedYears = yearChoices(years);
    const showType = types.length > 1;
    const showStatus = Boolean(onStatusFilter) && statuses.length > 0;
    const showYear = Boolean(onYearFilter) && listedYears.length > 0;

    if (!showType && !showStatus && !showYear) {
        return null;
    }

    return (
        <Space wrap className="pms-form-filters">
            {showYear && (
                <Select
                    value={yearFilter}
                    onChange={onYearFilter}
                    style={{ minWidth: 180 }}
                    options={[
                        { value: "all", label: "All years" },
                        ...listedYears.map((year) => ({
                            value: String(year.id),
                            label: year.is_active ? `${year.label} ★` : year.label,
                        })),
                    ]}
                />
            )}
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
