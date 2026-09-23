import React, { useMemo, useState } from "react";
import { Button, Empty, Segmented, Skeleton, Space, Tag, Timeline, Tooltip, Typography } from "antd";
import {
    CheckCircleOutlined,
    ClockCircleOutlined,
    MessageOutlined,
    PaperClipOutlined,
    SwapOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { ROLE_LABELS, STATUS_META, VIZ } from "~/utils/constants";

const STAGE_COLORS = {
    head: "blue",
    vp: "geekblue",
    qa: "purple",
    president: "gold",
    employee: "default",
};

const KIND_META = {
    movement: { label: "Movement", icon: <SwapOutlined />, color: VIZ.series[0] },
    remark: { label: "Remarks", icon: <MessageOutlined />, color: VIZ.series[2] },
    evidence: { label: "Evidence", icon: <PaperClipOutlined />, color: VIZ.ordinal[3] },
    delegation: { label: "Delegation", icon: <UserAddOutlined />, color: VIZ.series[1] },
    progress: { label: "Progress", icon: <CheckCircleOutlined />, color: VIZ.good },
};

const PAGE = 40;

function titleFor(event) {
    if (event.kind === "movement") {
        return STATUS_META[event.to]?.label ?? event.to;
    }

    if (event.kind === "remark") return "Remark";
    if (event.kind === "evidence") return "Evidence attached";
    if (event.kind === "delegation") return "Handed over";

    return "Commitment completed";
}

function Stages({ stages }) {
    const steps = [
        { key: "opened_at", label: "Opened" },
        { key: "submitted_at", label: "Submitted" },
        { key: "published_at", label: "Published" },
        { key: "reviewed_at", label: "Head" },
        { key: "vp_reviewed_at", label: "VP" },
        { key: "rated_at", label: "Rated" },
        { key: "closed_at", label: "Closed" },
    ].filter((step) => stages?.[step.key]);

    if (!steps.length) return null;

    const first = dayjs(stages[steps[0].key]);
    const last = dayjs(stages[steps[steps.length - 1].key]);

    return (
        <div className="pms-stages">
            {steps.map((step, index) => (
                <div key={step.key} className="pms-stage">
                    <span className="pms-stage-label">{step.label}</span>
                    <strong>{dayjs(stages[step.key]).format("MMM D, YYYY")}</strong>
                    {index > 0 && (
                        <span className="pms-stage-gap">
                            +{dayjs(stages[step.key]).diff(dayjs(stages[steps[index - 1].key]), "day")}d
                        </span>
                    )}
                </div>
            ))}
            {steps.length > 1 && (
                <div className="pms-stage pms-stage-total">
                    <span className="pms-stage-label">End to end</span>
                    <strong>{Math.max(last.diff(first, "day"), 0)} days</strong>
                </div>
            )}
        </div>
    );
}

export default function FormHistory({ formId }) {
    const [kind, setKind] = useState("all");
    const [shown, setShown] = useState(PAGE);

    const { data, isLoading } = useQuery({
        queryKey: ["form-history", String(formId)],
        queryFn: () => api.get(`pcr-forms/${formId}/history`).then((r) => r.data),
    });

    const events = data?.events ?? [];

    const counts = useMemo(
        () =>
            events.reduce((tally, event) => {
                tally[event.kind] = (tally[event.kind] ?? 0) + 1;

                return tally;
            }, {}),
        [events]
    );

    const filtered = kind === "all" ? events : events.filter((event) => event.kind === kind);
    const page = filtered.slice(0, shown);

    if (isLoading) {
        return <Skeleton active paragraph={{ rows: 6 }} />;
    }

    if (!events.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing has happened to this form yet." />;
    }

    const options = [
        { value: "all", label: `All ${events.length}` },
        ...Object.entries(KIND_META)
            .filter(([key]) => counts[key])
            .map(([key, meta]) => ({ value: key, label: `${meta.label} ${counts[key]}` })),
    ];

    let lastDay = null;

    const items = page.flatMap((event) => {
        const meta = KIND_META[event.kind] ?? KIND_META.movement;
        const at = dayjs(event.at);
        const day = at.format("YYYY-MM-DD");
        const rows = [];

        if (day !== lastDay) {
            lastDay = day;

            rows.push({
                key: `day-${day}-${event.at}`,
                dot: <span className="pms-history-day-dot" />,
                children: <div className="pms-history-day">{at.format("dddd, MMMM D, YYYY")}</div>,
            });
        }

        rows.push({
            key: `${event.kind}-${event.at}-${event.line ?? ""}-${event.detail ?? ""}-${rows.length}`,
            dot: (
                <span className="pms-history-dot" style={{ color: meta.color }}>
                    {meta.icon}
                </span>
            ),
            children: (
                <div className="pms-history-entry">
                    <Space size={6} wrap>
                        <Typography.Text strong>{titleFor(event)}</Typography.Text>
                        {event.kind === "movement" && event.from && (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                from {STATUS_META[event.from]?.label ?? event.from}
                            </Typography.Text>
                        )}
                        {event.kind === "remark" && (event.role || event.stage) && (
                            <Tag color={STAGE_COLORS[event.stage] ?? "default"} style={{ marginInlineEnd: 0 }}>
                                {ROLE_LABELS[event.role] ?? event.stage}
                            </Tag>
                        )}
                    </Space>

                    {event.detail && (
                        <div className="pms-history-detail">
                            {event.kind === "delegation" ? `Assigned to ${event.detail}` : event.detail}
                        </div>
                    )}

                    {event.line && (
                        <Typography.Paragraph
                            type="secondary"
                            ellipsis={{ rows: 2, tooltip: event.line }}
                            style={{ fontSize: 12, margin: "2px 0 0" }}
                        >
                            {event.line}
                        </Typography.Paragraph>
                    )}

                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {event.actor ?? "The system"} ·{" "}
                        <Tooltip title={at.format("dddd, MMMM D, YYYY h:mm A")}>
                            <span>{at.fromNow()}</span>
                        </Tooltip>
                    </Typography.Text>
                </div>
            ),
        });

        return rows;
    });

    return (
        <div className="pms-history">
            <Stages stages={data?.stages} />

            <Segmented
                size="small"
                value={kind}
                onChange={(value) => {
                    setKind(value);
                    setShown(PAGE);
                }}
                options={options}
                style={{ margin: "12px 0" }}
            />

            <Timeline items={items} />

            {filtered.length > page.length && (
                <Button block size="small" icon={<ClockCircleOutlined />} onClick={() => setShown(shown + PAGE)}>
                    Show {Math.min(PAGE, filtered.length - page.length)} older
                </Button>
            )}
        </div>
    );
}
