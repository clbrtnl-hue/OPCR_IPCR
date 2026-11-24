import React, { useEffect, useMemo, useRef, useState } from "react";
import { Card, Empty, Select, Space, Tag } from "antd";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { scoreable } from "~/pages/RatingPage";
import FormEditorPage from "~/pages/FormEditorPage";

function arrivalTime(form) {
    const stamp = form.stage_arrived_at || form.submitted_at || form.created_at;
    const time = stamp ? dayjs(stamp).valueOf() : Number.POSITIVE_INFINITY;

    return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time;
}

export default function CommitmentPage({ periodFilter = null, focusFormId = null }) {
    const { user } = useAuth();
    const [selectedId, setSelectedId] = useState(null);
    const leavingId = useRef(null);
    const wantedId = focusFormId ? Number(focusFormId) : null;

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["review-queue", "mine", user?.id, "approval"],
        queryFn: () => api.get("pcr-forms?queue=1").then((r) => r.data),
    });

    const waiting = useMemo(() => {
        const forms = queue.filter((form) => {
            if (
                periodFilter &&
                Number(form.rating_period_id ?? form.rating_period?.id) !== Number(periodFilter)
            ) {
                return false;
            }

            if (scoreable(form, user)) return false;

            if (form.status === "commitment_review") return true;

            return (
                form.status === "qa_approval" &&
                (user?.workflow?.opcr?.approver_roles ?? ["qa"]).includes(user?.role)
            );
        });

        return [...forms].sort((a, b) => arrivalTime(a) - arrivalTime(b) || a.id - b.id);
    }, [queue, periodFilter, user]);
    const waitingRef = useRef(waiting);
    waitingRef.current = waiting;

    const openWaiting = (id) => {
        setSelectedId(id);
    };

    useEffect(() => {
        if (isLoading) return;

        if (leavingId.current && !waiting.some((item) => item.id === leavingId.current)) {
            leavingId.current = null;
        }

        const rest = leavingId.current
            ? waiting.filter((item) => item.id !== leavingId.current)
            : waiting;

        if (wantedId && rest.some((item) => item.id === wantedId)) {
            if (selectedId !== wantedId) {
                openWaiting(wantedId);
            }

            return;
        }

        if (selectedId && rest.some((item) => item.id === selectedId)) return;

        const nextId = rest[0]?.id ?? null;

        if ((selectedId ?? null) === nextId) return;

        setSelectedId(nextId);
    }, [isLoading, selectedId, waiting, wantedId]);

    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(selectedId)],
        queryFn: () => api.get(`pcr-forms/${selectedId}`).then((r) => r.data),
        enabled: Boolean(selectedId),
    });

    const periodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const periods = form?.school_year?.periods ?? [];

    return (
        <>
            <Card className="pms-rate-picker" size="small" style={{ marginBottom: 16 }}>
                <Space wrap className="pms-rate-pick">
                    <span>Form waiting for approval:</span>
                    <Select
                        className="pms-rate-form"
                        classNames={{ popup: { root: "pms-rate-form-popup" } }}
                        popupMatchSelectWidth
                        loading={isLoading}
                        placeholder="Select a form"
                        value={selectedId}
                        onChange={(value) => {
                            leavingId.current = null;
                            openWaiting(value);
                        }}
                        options={waiting.map((item) => ({
                            value: item.id,
                            label: `${item.type.toUpperCase()} — ${item.owner?.name ?? item.org_unit?.name} (${item.school_year?.label})`,
                        }))}
                    />
                    {periodId && (
                        <Tag color="blue">
                            {periods.find((period) => period.id === periodId)?.label}
                        </Tag>
                    )}
                </Space>
            </Card>

            {!selectedId ? (
                <Card className="pms-list-panel">
                    <Empty
                        description={
                            waiting.length === 0
                                ? "No forms are waiting for approval."
                                : "Pick a form above to review its commitments."
                        }
                    />
                </Card>
            ) : (
                <FormEditorPage key={selectedId} formId={selectedId} hideTitle hideProgress />
            )}
        </>
    );
}
