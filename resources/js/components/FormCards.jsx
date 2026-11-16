import React from "react";
import { Button, Tag } from "antd";
import dayjs from "dayjs";
import { statusMeta } from "~/utils/constants";

export default function FormCards({ forms, onOpen, actionLabel, showSubmitted = false, sideAction = false }) {
    return (
        <div className="pms-form-cards">
            {forms.map((form) => {
                const meta = statusMeta(form.status, form);
                const who = form.owner?.name || `${form.org_unit?.name ?? "Office"} (office)`;
                const label = actionLabel ? actionLabel(form) : "Open";
                const details = (
                    <>
                        <div className="pms-form-card-top">
                            <Tag color={form.type === "opcr" ? "geekblue" : "blue"}>{form.type?.toUpperCase()}</Tag>
                            <Tag color={meta.color}>{meta.label}</Tag>
                        </div>
                        <strong>{who}</strong>
                        <span>
                            {form.school_year?.label}
                            {form.rating_period?.label ? ` · ${form.rating_period.label}` : ""}
                            {form.org_unit?.name ? ` · ${form.org_unit.name}` : ""}
                        </span>
                        {showSubmitted && (
                            <span>{form.submitted_at ? dayjs(form.submitted_at).format("MMM D, YYYY") : "Not submitted"}</span>
                        )}
                    </>
                );

                return (
                    <article key={form.id} className={sideAction ? "pms-form-card is-side" : "pms-form-card"}>
                        {sideAction ? <div className="pms-form-card-main">{details}</div> : details}
                        <Button
                            type={sideAction ? "text" : "primary"}
                            block={!sideAction}
                            className={sideAction ? "pms-form-card-open" : undefined}
                            onClick={() => onOpen(form)}
                        >
                            {label}
                        </Button>
                    </article>
                );
            })}
        </div>
    );
}
