import React from "react";
import { Button, Tag } from "antd";
import dayjs from "dayjs";
import { STATUS_META } from "~/utils/constants";

export default function FormCards({ forms, onOpen, actionLabel, showSubmitted = false }) {
    return (
        <div className="pms-form-cards">
            {forms.map((form) => {
                const meta = STATUS_META[form.status] ?? { label: form.status, color: "default" };
                const who = form.owner?.name || `${form.org_unit?.name ?? "Office"} (office)`;

                return (
                    <article key={form.id} className="pms-form-card">
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
                        <Button type="primary" block onClick={() => onOpen(form)}>
                            {actionLabel ? actionLabel(form) : "Open"}
                        </Button>
                    </article>
                );
            })}
        </div>
    );
}
