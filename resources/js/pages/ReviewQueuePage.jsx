import React, { useState } from "react";
import { Button, Card, Empty, Table, Tabs, Tag } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { APPROVAL_QUEUE_STATUSES, statusMeta } from "~/utils/constants";
import { dimensionPhrase } from "~/utils/ratingScale";
import PageHeader from "~/components/PageHeader";
import FormCards from "~/components/FormCards";
import FormListFilters, { matchesListKind } from "~/components/FormListFilters";
import { useAuth } from "~/hooks/useAuth";
import RatingPage, { scoreable } from "~/pages/RatingPage";
import CommitmentPage from "~/pages/CommitmentPage";

function QueueList({ rows, loading, empty, columns, onOpen, filters }) {
    return (
        <Card className="pms-list-panel">
            {filters}
            {rows.length === 0 && !loading ? (
                <Empty description={empty} />
            ) : (
                <>
                    <div className="pms-mobile-only">
                        <FormCards forms={rows} showSubmitted sideAction actionLabel={() => "View"} onOpen={onOpen} />
                    </div>
                    <div className="pms-desktop-only">
                        <Table
                            rowKey="id"
                            loading={loading}
                            dataSource={rows}
                            columns={columns}
                            pagination={{ pageSize: 15 }}
                            scroll={{ x: 900 }}
                        />
                    </div>
                </>
            )}
        </Card>
    );
}

export default function ReviewQueuePage() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const isQa = user?.role === "qa";
    const monitors = ["admin", "qa", "hr"].includes(user?.role);
    const seesRating = user?.role !== "president";
    const periodId = Number(searchParams.get("period")) || null;
    const requested = searchParams.get("tab");
    const tab = !seesRating
        ? "approval"
        : requested === "rating"
          ? "rating"
          : requested === "viewing" && isQa
            ? "viewing"
            : "approval";
    const [formKind, setFormKind] = useState("all");
    const [statusKind, setStatusKind] = useState("all");

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["review-queue", viewOnly ? "oversight" : "mine"],
        queryFn: () =>
            api.get(viewOnly ? "pcr-forms?oversight=review" : "pcr-forms?queue=1").then((r) => r.data),
    });
    const { data: ratedForms = [], isLoading: ratingLoading } = useQuery({
        queryKey: ["qa-queue", "oversight"],
        queryFn: () => api.get("pcr-forms?oversight=rating").then((r) => r.data),
        enabled: viewOnly,
    });

    const rows = periodId
        ? queue.filter(
              (form) => Number(form.rating_period_id ?? form.rating_period?.id) === periodId
          )
        : queue;
    const approvalRows = monitors
        ? rows.filter((form) => APPROVAL_QUEUE_STATUSES.includes(form.status))
        : rows.filter((form) => !scoreable(form, user));
    const ratedRows = periodId
        ? ratedForms.filter(
              (form) => Number(form.rating_period_id ?? form.rating_period?.id) === periodId
          )
        : ratedForms;
    const ratingRows = viewOnly
        ? ratedRows
        : rows.filter((form) => !APPROVAL_QUEUE_STATUSES.includes(form.status));
    const activeFormKind = formKind === "all" || ratingRows.some((row) => row.type === formKind) ? formKind : "all";
    const activeStatusKind = statusKind === "all" || ratingRows.some((row) => row.status === statusKind) ? statusKind : "all";
    const visibleRating = ratingRows.filter((row) => matchesListKind(row, activeFormKind, activeStatusKind));

    const selectTab = (key) => {
        const next = new URLSearchParams(searchParams);

        if (key === "approval") {
            next.delete("tab");
        } else {
            next.set("tab", key);
        }

        setSearchParams(next, { replace: true });
    };

    const openForm = (record) => navigate(`/forms/${record.id}`, { state: { from: "/review-queue" } });

    const columns = [
        {
            title: "Form",
            dataIndex: "type",
            render: (type) => <Tag color={type === "opcr" ? "geekblue" : "blue"}>{type.toUpperCase()}</Tag>,
        },
        {
            title: "Ratee",
            key: "ratee",
            render: (_, record) => record.owner?.name || `${record.org_unit?.name} (office)`,
        },
        {
            title: "Unit",
            key: "unit",
            render: (_, record) => record.org_unit?.name,
        },
        {
            title: "School year",
            key: "year",
            render: (_, record) => record.school_year?.label,
        },
        {
            title: "Period",
            key: "period",
            render: (_, record) => record.rating_period?.label ?? "Whole year",
        },
        {
            title: "Submitted",
            dataIndex: "submitted_at",
            render: (v) => (v ? dayjs(v).format("MMM D, YYYY") : "—"),
        },
        {
            title: "Status",
            dataIndex: "status",
            render: (status, record) => {
                const meta = statusMeta(status, record);
                return <Tag color={meta.color}>{meta.label}</Tag>;
            },
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Button type="primary" size="small" onClick={() => openForm(record)}>
                    View
                </Button>
            ),
        },
    ];

    const approvalSubtitle = viewOnly
        ? "Commitments and office targets waiting for approval. You can open them, but you cannot approve them."
        : user?.role === "president"
          ? "IPCR commitments waiting for your approval."
          : user?.role === "qa"
            ? "Commitments and office targets waiting for your approval."
            : user?.role === "hr"
              ? "Commitments and office targets waiting for your approval."
              : user?.role === "vp"
                ? "Forms the heads have endorsed and that are waiting for your review."
                : "Forms your unit has submitted and that are waiting for your review.";

    const scoresHere = !monitors || isQa || user?.role === "hr";
    const ratingSubtitle = scoresHere
        ? `Score each success indicator on ${dimensionPhrase(user)}. A is their average; the final rating is the mean of the section averages.`
        : viewOnly
          ? "Forms in review or with QA. Open one to read the scores."
          : "Forms in review or with QA. Open one to read it, or to score it when it is waiting on you.";
    const viewingSubtitle = "Forms in review or with QA. Open one to read it.";
    const subtitle = tab === "viewing" ? viewingSubtitle : tab === "rating" ? ratingSubtitle : approvalSubtitle;
    const showingList = tab === "viewing" || (tab === "rating" && monitors && !isQa && user?.role !== "hr");

    return (
        <>
            <PageHeader title="Review Queue" subtitle={subtitle} />

            {seesRating && (
                <Tabs
                    className="pms-line-tabs"
                    activeKey={tab}
                    onChange={selectTab}
                    style={{ marginBottom: 16 }}
                    items={[
                        { key: "approval", label: "Commitment approval" },
                        ...(isQa ? [{ key: "viewing", label: "Viewing" }] : []),
                        { key: "rating", label: "Rating" },
                    ]}
                />
            )}

            {tab === "rating" && scoresHere ? (
                <RatingPage embedded periodFilter={periodId} />
            ) : tab === "approval" && !viewOnly ? (
                <CommitmentPage periodFilter={periodId} />
            ) : (
                <QueueList
                    rows={showingList ? visibleRating : approvalRows}
                    loading={tab === "rating" && viewOnly ? ratingLoading : isLoading}
                    empty={
                        showingList && ratingRows.length > 0
                            ? "No forms match this filter."
                            : showingList
                              ? "Nothing is waiting to be rated or closed."
                              : "Nothing is waiting for approval."
                    }
                    columns={columns}
                    onOpen={openForm}
                    filters={
                        showingList && monitors ? (
                            <FormListFilters
                                rows={ratingRows}
                                formKind={activeFormKind}
                                statusKind={activeStatusKind}
                                onFormKind={setFormKind}
                                onStatusKind={setStatusKind}
                            />
                        ) : null
                    }
                />
            )}
        </>
    );
}
