import React, { useState } from "react";
import { Badge, Button, Card, Empty, Popconfirm, Select, Space, Table, Tabs, Tag, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { APPROVAL_QUEUE_STATUSES, statusMeta } from "~/utils/constants";
import { formIsWithReviewer } from "~/utils/formReviewer";
import { dimensionPhrase } from "~/utils/ratingScale";
import PageHeader from "~/components/PageHeader";
import FormCards from "~/components/FormCards";
import FormListFilters, { formYearId, matchesListKind, yearChoices } from "~/components/FormListFilters";
import ListPager, { tablePager, usePaged } from "~/components/ListPager";
import { useAuth } from "~/hooks/useAuth";
import RatingPage, { scoreable } from "~/pages/RatingPage";
import CommitmentPage from "~/pages/CommitmentPage";

const NUDGE_STATUSES = new Set([
    "commitment_review",
    "head_review",
    "dean_review",
    "director_review",
    "vp_review",
    "qa_rating",
    "qa_approval",
]);

function QueueList({ rows, loading, empty, columns, onOpen, filters, renderCardActions }) {
    const cardPage = usePaged(rows, 15);

    return (
        <Card className="pms-list-panel pms-list-fill">
            {filters}
            {rows.length === 0 && !loading ? (
                <Empty description={empty} />
            ) : (
                <>
                    <div className="pms-mobile-only pms-paged">
                        <FormCards
                            forms={cardPage.rows}
                            showSubmitted
                            sideAction
                            actionLabel={() => "View"}
                            onOpen={onOpen}
                            renderActions={renderCardActions}
                        />
                        <ListPager
                            page={cardPage.page}
                            pageSize={cardPage.pageSize}
                            total={cardPage.total}
                            onChange={cardPage.setPage}
                        />
                    </div>
                    <div className="pms-desktop-only">
                        <Table
                            rowKey="id"
                            loading={loading}
                            dataSource={rows}
                            columns={columns}
                            pagination={tablePager(15)}
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
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";
    const isQa = user?.role === "qa";
    const monitors = ["admin", "qa"].includes(user?.role);
    const canNudge = Boolean(user?.capabilities?.follow_up);
    const periodId = Number(searchParams.get("period")) || null;
    const focusFormId = Number(searchParams.get("form")) || null;
    const [yearChoice, setYearChoice] = useState(null);

    const { data: years = [], isLoading: yearsLoading } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const nudge = useMutation({
        mutationFn: (formId) => api.post(`pcr-forms/${formId}/nudge`),
        onSuccess: ({ data: payload }) => {
            message.success(payload?.message ?? "Follow-up sent.");
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
            queryClient.invalidateQueries({ queryKey: ["report-summary"] });
        },
        onError: (error) => {
            message.error(error?.response?.data?.message ?? "Could not send the follow-up.");
        },
    });
    const requested = searchParams.get("tab");
    const tab = requested === "rating"
        ? "rating"
        : requested === "viewing" && isQa
          ? "viewing"
          : "approval";
    const [formKind, setFormKind] = useState("all");

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

    const activeYear = years.find((year) => year.is_active);
    const focusForm = [...queue, ...ratedForms].find((form) => form.id === focusFormId);
    const focusYear = focusForm ? formYearId(focusForm) : null;
    const resolvedYear = yearChoice ?? (focusYear != null ? String(focusYear) : activeYear ? String(activeYear.id) : null);
    const yearReady = !yearsLoading && resolvedYear != null;
    const inSelectedYear = (form) => yearReady && formYearId(form) === Number(resolvedYear);
    const yearOptions = yearChoices(years);

    const rows = (periodId
        ? queue.filter(
              (form) => Number(form.rating_period_id ?? form.rating_period?.id) === periodId
          )
        : queue
    ).filter(inSelectedYear);
    const approvalRows = monitors
        ? rows.filter((form) => APPROVAL_QUEUE_STATUSES.includes(form.status))
        : rows.filter((form) => !scoreable(form, user));
    // Oversight rating list should not keep finished forms — rated/final belong
    // on Reports / Closing, not the active Rating tab.
    const ratedRows = (periodId
        ? ratedForms.filter(
              (form) => Number(form.rating_period_id ?? form.rating_period?.id) === periodId
          )
        : ratedForms
    ).filter((form) => !["rated", "final"].includes(form.status) && inSelectedYear(form));
    const ratingRows = viewOnly
        ? ratedRows
        : rows.filter((form) => !APPROVAL_QUEUE_STATUSES.includes(form.status));
    const activeFormKind = formKind === "all" || ratingRows.some((row) => row.type === formKind) ? formKind : "all";
    const visibleRating = ratingRows.filter((row) => matchesListKind(row, activeFormKind));

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
            align: "right",
            width: 160,
            render: (_, record) => {
                const owner = record.owner?.name || record.org_unit?.name || "this form";
                const locked = Boolean(record.rating_period?.is_locked);
                const onMyStep = formIsWithReviewer(user, record);
                const showNudge = canNudge && NUDGE_STATUSES.has(record.status) && !onMyStep;

                return (
                    <div
                        style={{
                            display: "inline-flex",
                            gap: 8,
                            alignItems: "center",
                            justifyContent: "flex-end",
                        }}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <span
                            style={{
                                display: "inline-flex",
                                justifyContent: "flex-end",
                                minWidth: 58,
                            }}
                        >
                            {showNudge ? (
                                <Popconfirm
                                    title="Send a follow-up?"
                                    description={
                                        locked
                                            ? "This review period is locked. Unlock it before sending a follow-up."
                                            : `Ask the reviewer about ${owner}'s form.`
                                    }
                                    okText="Remind"
                                    cancelText="Cancel"
                                    disabled={locked || nudge.isPending}
                                    onConfirm={() => nudge.mutate(record.id)}
                                >
                                    <Button
                                        size="small"
                                        disabled={locked || nudge.isPending}
                                        title={
                                            locked
                                                ? "Unlock the review period before sending a follow-up."
                                                : "Ask the reviewer to check this form"
                                        }
                                    >
                                        Remind
                                    </Button>
                                </Popconfirm>
                            ) : null}
                        </span>
                        <Button type="primary" size="small" onClick={() => openForm(record)}>
                            View
                        </Button>
                    </div>
                );
            },
        },
    ];

    const approvalSubtitle = viewOnly
        ? "Commitments and office targets waiting for approval. You can open them, but you cannot approve them."
        : user?.role === "president"
          ? "IPCR commitments waiting for your approval."
            : user?.role === "qa"
              ? "Commitments and office targets waiting for your approval."
              : user?.role === "vp"
                ? "Forms the heads have endorsed and that are waiting for your review."
                : "Forms your unit has submitted and that are waiting for your review.";

    const scoresHere = !monitors || isQa;
    const ratingSubtitle = scoresHere
        ? `Score each success indicator on ${dimensionPhrase(user)}. A is their average; the final rating is the mean of the section averages.`
        : viewOnly
          ? "Forms in review or with QA. Open one to read the scores."
          : "Forms in review or with QA. Open one to read it, or to score it when it is waiting on you.";
    const viewingSubtitle = "Forms in review or with QA. Open one to read it.";
    const subtitle = tab === "viewing" ? viewingSubtitle : tab === "rating" ? ratingSubtitle : approvalSubtitle;
    const showingList = tab === "viewing" || (tab === "rating" && monitors && !isQa);
    const approverRoles = user?.workflow?.opcr?.approver_roles ?? ["qa"];
    const commitmentBadge = rows.filter((form) => {
        if (scoreable(form, user)) return false;
        if (form.status === "commitment_review") return true;

        return form.status === "qa_approval" && approverRoles.includes(user?.role);
    }).length;
    const approvalBadge = viewOnly ? approvalRows.length : commitmentBadge;
    const ratingBadge = scoresHere && !viewOnly
        ? rows.filter((form) => scoreable(form, user)).length
        : ratingRows.length;
    const tabLabel = (text, count) => (
        <span style={{ display: "inline-flex", alignItems: "center", gap: 8 }}>
            {text}
            <Badge count={count} size="small" />
        </span>
    );

    const queueTabs = (
        <>
            {yearOptions.length > 0 && (
                <Select
                    value={resolvedYear == null ? undefined : Number(resolvedYear)}
                    onChange={(value) => setYearChoice(String(value))}
                    style={{ minWidth: 180, marginBottom: 16 }}
                    options={yearOptions.map((year) => ({
                        value: year.id,
                        label: year.is_active ? `${year.label} ★` : year.label,
                    }))}
                />
            )}
            <Tabs
                className="pms-line-tabs"
                activeKey={tab}
                onChange={selectTab}
                style={{ marginBottom: 16 }}
                items={[
                    { key: "approval", label: tabLabel("Commitment approval", approvalBadge) },
                    ...(isQa ? [{ key: "viewing", label: "Viewing" }] : []),
                    { key: "rating", label: tabLabel("Rating", ratingBadge) },
                ]}
            />
        </>
    );

    return (
        <>
            <PageHeader title="Review Queue" subtitle={subtitle} />

            {tab === "rating" ? (
                yearReady ? (
                    <RatingPage
                        embedded
                        periodFilter={periodId}
                        yearFilter={resolvedYear}
                        focusFormId={focusFormId}
                        ahead={queueTabs}
                    />
                ) : (
                    <>
                        {queueTabs}
                        <Card loading />
                    </>
                )
            ) : (
                <>
                    {queueTabs}
                    {tab === "approval" && !viewOnly ? (
                        yearReady ? (
                            <CommitmentPage periodFilter={periodId} yearFilter={resolvedYear} focusFormId={focusFormId} />
                        ) : (
                            <Card loading />
                        )
                    ) : (
                        <QueueList
                            rows={showingList ? visibleRating : approvalRows}
                            loading={tab === "rating" && viewOnly ? ratingLoading || yearsLoading : isLoading || yearsLoading}
                            empty={
                                showingList && ratingRows.length > 0
                                    ? "No forms match this filter."
                                    : showingList
                                      ? "Nothing is waiting to be rated or closed."
                                      : "Nothing is waiting for approval."
                            }
                            columns={columns}
                            onOpen={openForm}
                            renderCardActions={
                                canNudge
                                    ? (record, openBtn) => {
                                          const owner = record.owner?.name || record.org_unit?.name || "this form";
                                          const locked = Boolean(record.rating_period?.is_locked);
                                          const onMyStep = formIsWithReviewer(user, record);
                                          if (!NUDGE_STATUSES.has(record.status) || onMyStep) return openBtn;

                                          return (
                                              <Space size={4} direction="vertical" style={{ width: "100%" }}>
                                                  <Popconfirm
                                                      title="Send a follow-up?"
                                                      description={
                                                          locked
                                                              ? "This review period is locked. Unlock it before sending a follow-up."
                                                              : `Ask the reviewer about ${owner}'s form.`
                                                      }
                                                      okText="Remind"
                                                      cancelText="Cancel"
                                                      disabled={locked || nudge.isPending}
                                                      onConfirm={() => nudge.mutate(record.id)}
                                                  >
                                                      <Button
                                                          size="small"
                                                          block
                                                          disabled={locked || nudge.isPending}
                                                          title={
                                                              locked
                                                                  ? "Unlock the review period before sending a follow-up."
                                                                  : "Ask the reviewer to check this form"
                                                          }
                                                      >
                                                          Remind
                                                      </Button>
                                                  </Popconfirm>
                                                  {openBtn}
                                              </Space>
                                          );
                                      }
                                    : undefined
                            }
                            filters={
                                showingList && monitors ? (
                                    <FormListFilters
                                        rows={ratingRows}
                                        formKind={activeFormKind}
                                        onFormKind={setFormKind}
                                    />
                                ) : null
                            }
                        />
                    )}
                </>
            )}
        </>
    );
}
