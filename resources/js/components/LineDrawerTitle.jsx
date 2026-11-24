import React from "react";

export function formWhen(form, periodId) {
    const year = form?.school_year?.label;
    const periods = form?.school_year?.periods ?? [];
    const matched = periods.find((row) => Number(row.id) === Number(periodId));
    const period =
        matched?.label
        ?? (Number(form?.rating_period?.id) === Number(periodId) ? form.rating_period.label : null);

    return [year, period].filter(Boolean).join(" · ");
}

export default function LineDrawerTitle({ name, when }) {
    return (
        <div className="pms-drawer-title">
            <div className="pms-drawer-title-name">{name}</div>
            {when ? <div className="pms-drawer-title-when">{when}</div> : null}
        </div>
    );
}
