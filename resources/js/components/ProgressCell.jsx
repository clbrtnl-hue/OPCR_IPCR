import React, { useEffect, useState } from "react";
import { Button, InputNumber, Popover, Progress, Space, Tooltip, Typography } from "antd";
import { PROGRESS_META, progressTone } from "~/utils/constants";
import ProgressStatusSelect from "~/components/ProgressStatusSelect";

export function statusForPct(pct, current) {
    if (current === "deferred") return "deferred";
    if (Number(pct) >= 100) return "completed";

    return Number(pct) > 0 ? "ongoing" : "not_started";
}

export default function ProgressCell({
    status = "not_started",
    pct = 0,
    computed = false,
    computedHint = "Rolled up from the work committed under this line — update that instead.",
    editable = false,
    saving = false,
    onSave,
}) {
    const value = Math.max(0, Math.min(100, Number(pct ?? 0)));
    const meta = PROGRESS_META[status] ?? PROGRESS_META.not_started;
    const tone = progressTone(status, value);
    const done = status === "completed" || value >= 100;

    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(value);

    useEffect(() => setDraft(value), [value]);

    const bar = (
        <div className="pms-progress" data-done={done ? "yes" : undefined}>
            <Progress
                percent={value}
                showInfo={false}
                size="small"
                strokeColor={tone}
                trailColor="#f0f0f0"
            />
            <span className="pms-progress-label" style={{ color: tone }}>
                {value}% · {meta.label}
            </span>
        </div>
    );

    if (computed) {
        return <Tooltip title={computedHint}>{bar}</Tooltip>;
    }

    if (!editable) {
        return bar;
    }

    const save = (values) => {
        onSave?.(values);
        setOpen(false);
    };

    return (
        <Popover
            trigger="click"
            open={open}
            onOpenChange={setOpen}
            title="How far along is this line?"
            content={
                <Space direction="vertical" size={8} style={{ width: 230 }}>
                    <ProgressStatusSelect
                        style={{ width: "100%" }}
                        value={status}
                        onChange={(next) => save({ progress_status: next })}
                    />
                    <Space size={6}>
                        <InputNumber
                            size="small"
                            min={0}
                            max={100}
                            value={draft}
                            formatter={(v) => `${v}%`}
                            parser={(v) => String(v).replace("%", "")}
                            onChange={(next) => setDraft(next ?? 0)}
                            onPressEnter={() =>
                                save({
                                    progress_status: statusForPct(draft, status),
                                    progress_pct: draft,
                                })
                            }
                        />
                        <Button
                            size="small"
                            type="primary"
                            loading={saving}
                            onClick={() =>
                                save({
                                    progress_status: statusForPct(draft, status),
                                    progress_pct: draft,
                                })
                            }
                        >
                            Save
                        </Button>
                    </Space>
                    <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                        100% marks the line completed and turns it green.
                    </Typography.Text>
                </Space>
            }
        >
            <div className="pms-progress-editable">{bar}</div>
        </Popover>
    );
}
