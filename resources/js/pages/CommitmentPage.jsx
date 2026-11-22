import React, { useEffect, useMemo, useRef, useState } from "react";
import { Button, Card, Drawer, Empty, Popconfirm, Select, Space, Table, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { SECTION_LABELS, statusMeta } from "~/utils/constants";
import RichText from "~/components/RichText";
import RichTextView, { toPlainText } from "~/components/RichTextView";
import ReturnPlacesModal from "~/components/ReturnPlacesModal";
import { RETURN_FIELD_LABELS, displayReturnMarks } from "~/utils/returnMarks";
import { scoreable } from "~/pages/RatingPage";

function samePerson(left, right) {
    return Number(left) === Number(right);
}

function arrivalTime(form) {
    const stamp = form.stage_arrived_at || form.submitted_at || form.created_at;
    const time = stamp ? dayjs(stamp).valueOf() : Number.POSITIVE_INFINITY;

    return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time;
}

function lineRemark(line, periodId) {
    const rating = (line.ratings ?? []).find((row) => row.rating_period_id === periodId);

    return rating?.remarks || "";
}

function formatTargetDate(value) {
    if (!value) return null;

    const stamp = dayjs(value);

    return stamp.isValid() ? stamp.format("MMM D, YYYY") : String(value);
}

function accountableLabel(line) {
    const names = (line.assignments ?? [])
        .map((row) => row.user?.name)
        .filter(Boolean);

    if (names.length) return names.join(", ");

    const plain = toPlainText(line.accountable || "");

    return plain || null;
}

export default function CommitmentPage({ periodFilter = null, focusFormId = null }) {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState(null);
    const [lineId, setLineId] = useState(null);
    const [chosenPeriodId, setChosenPeriodId] = useState(null);
    const [returnModal, setReturnModal] = useState(false);
    const [remarks, setRemarks] = useState({});
    const leavingId = useRef(null);
    const dirtyRemarks = useRef(new Set());
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
        setLineId(null);
        setChosenPeriodId(null);
    };

    const showNextAfter = (sentId) => {
        leavingId.current = sentId;
        const next = waitingRef.current.find((item) => item.id !== sentId);

        openWaiting(next?.id ?? null);
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
        setLineId(null);
        setChosenPeriodId(null);
    }, [isLoading, selectedId, waiting, wantedId]);

    const { data: form } = useQuery({
        queryKey: ["pcr-form", String(selectedId)],
        queryFn: () => api.get(`pcr-forms/${selectedId}`).then((r) => r.data),
        enabled: Boolean(selectedId),
    });

    const periodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const periods = form?.school_year?.periods ?? [];
    const activePeriodId =
        periodId ?? chosenPeriodId ?? periods.find((period) => period.is_active)?.id ?? periods[0]?.id;
    const isOpcr = form?.type === "opcr";
    const canApprove =
        Boolean(form) &&
        (isOpcr
            ? form.status === "qa_approval" &&
              (user?.workflow?.opcr?.approver_roles ?? ["qa"]).includes(user?.role)
            : form.status === "commitment_review" &&
              samePerson(form.commitment_approver?.id, user?.id));

    const indicators = useMemo(() => {
        if (!form) return [];

        return form.outputs.flatMap((output) =>
            (output.indicators ?? []).map((indicator) => {
                const line = { ...indicator, output };
                delete line.children;

                return line;
            })
        );
    }, [form]);
    const returnMarks = useMemo(
        () => displayReturnMarks(form?.return_marks, form?.status),
        [form?.return_marks, form?.status]
    );
    const marksForOutput = (outputId) =>
        returnMarks.filter(
            (mark) => mark.field === "title" && Number(mark.output_id) === Number(outputId)
        );
    const marksForLine = (indicatorId, field = null) =>
        returnMarks.filter((mark) => {
            if (mark.field === "title") return false;
            if (Number(mark.indicator_id) !== Number(indicatorId)) return false;

            return field ? mark.field === field : true;
        });
    const openLine = useMemo(
        () => indicators.find((row) => row.id === lineId) ?? null,
        [indicators, lineId]
    );

    const renderMarks = (marks) =>
        marks.map((mark) => (
            <div key={mark.id} className="pms-cell-mark">
                <strong>{RETURN_FIELD_LABELS[mark.field] ?? "This part"}.</strong> {mark.note}
            </div>
        ));

    const lineNoteField = (indicatorId, rows = 2) =>
        canApprove ? (
            <RichText
                rows={rows}
                value={remarks[indicatorId] ?? ""}
                onChange={(html) => {
                    dirtyRemarks.current.add(indicatorId);
                    setRemarks((prev) => ({ ...prev, [indicatorId]: html }));
                }}
            />
        ) : remarks[indicatorId] ? (
            <RichTextView html={remarks[indicatorId]} />
        ) : (
            <Typography.Text type="secondary">No line note yet</Typography.Text>
        );

    useEffect(() => {
        dirtyRemarks.current = new Set();
    }, [form?.id, activePeriodId]);

    useEffect(() => {
        if (!form || !activePeriodId) return;

        setRemarks((prev) => {
            const next = {};

            indicators.forEach((indicator) => {
                if (dirtyRemarks.current.has(indicator.id) && prev[indicator.id] != null) {
                    next[indicator.id] = prev[indicator.id];

                    return;
                }

                next[indicator.id] = lineRemark(indicator, activePeriodId);
            });

            return next;
        });
    }, [form, activePeriodId, indicators]);

    const refreshQueues = () => {
        queryClient.invalidateQueries({ queryKey: ["review-queue"] });
        queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
        queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
    };

    const approve = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/status`, {
                status: isOpcr ? "approved" : "commitments_approved",
            }),
        onSuccess: () => {
            message.success(isOpcr ? "Targets approved." : "Commitments approved.");
            refreshQueues();
            showNextAfter(form.id);
        },
    });

    const saveRemarks = useMutation({
        mutationFn: () =>
            api.post("pcr-ratings", {
                form_id: form.id,
                rating_period_id: activePeriodId,
                remarks_only: true,
                ratings: indicators.map((indicator) => ({
                    indicator_id: indicator.id,
                    remarks: remarks[indicator.id] ?? "",
                })),
            }),
        onSuccess: () => {
            message.success("Line notes saved.");
            dirtyRemarks.current = new Set();
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(selectedId)] });
        },
    });

    const returnForm = useMutation({
        mutationFn: (fixes) =>
            api.post(`pcr-forms/${form.id}/status`, { status: "returned", fixes }),
        onSuccess: () => {
            message.success("Form returned.");
            setReturnModal(false);
            refreshQueues();
            showNextAfter(form.id);
        },
    });

    const columns = [
        {
            title: "Output",
            dataIndex: ["output", "title"],
            width: 160,
            render: (title, record) => (
                <div>
                    <Tag>{SECTION_LABELS[record.output.section]}</Tag>
                    <div style={{ marginTop: 4 }}>{title}</div>
                    {renderMarks(marksForOutput(record.output?.id))}
                </div>
            ),
        },
        {
            title: "Success indicator",
            dataIndex: "description",
            width: 240,
            render: (value, record) => (
                <div>
                    <RichTextView html={value} />
                    {renderMarks(marksForLine(record.id, "description"))}
                </div>
            ),
        },
        {
            title: "Target date",
            dataIndex: "target_date",
            width: 120,
            render: (value, record) => {
                const label = formatTargetDate(value);

                return (
                    <div>
                        <span className="pms-review-date">{label || "—"}</span>
                        {renderMarks(marksForLine(record.id, "target_date"))}
                    </div>
                );
            },
        },
        ...(isOpcr
            ? [
                  {
                      title: "Budget",
                      dataIndex: "allotted_budget",
                      width: 110,
                      render: (value, record) => (
                          <div>
                              {value != null && value !== "" ? Number(value).toLocaleString() : "—"}
                              {renderMarks(marksForLine(record.id, "allotted_budget"))}
                          </div>
                      ),
                  },
                  {
                      title: "Accountable",
                      key: "accountable",
                      width: 140,
                      render: (_, record) => (
                          <div>
                              {accountableLabel(record) || "—"}
                              {renderMarks(marksForLine(record.id, "accountable"))}
                          </div>
                      ),
                  },
              ]
            : []),
        {
            title: "Line note",
            key: "remarks",
            width: 220,
            render: (_, record) => lineNoteField(record.id),
        },
    ];

    const actions = canApprove && (
        <Space wrap>
            <Button
                loading={saveRemarks.isPending}
                disabled={!activePeriodId || indicators.length === 0}
                onClick={() => saveRemarks.mutate()}
            >
                Save line notes
            </Button>
            <Button danger onClick={() => setReturnModal(true)}>
                Return
            </Button>
            <Popconfirm
                title={isOpcr ? "Approve these targets?" : "Approve these commitments?"}
                description={
                    isOpcr
                        ? "The office targets can then be published."
                        : "The writer can then hand work down and record tasks."
                }
                okText="Approve"
                onConfirm={() => approve.mutate()}
            >
                <Button type="primary" loading={approve.isPending}>
                    {isOpcr ? "Approve targets" : "Approve commitments"}
                </Button>
            </Popconfirm>
        </Space>
    );

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
                    {periodId ? (
                        <Tag color="blue">
                            {periods.find((period) => period.id === periodId)?.label}
                        </Tag>
                    ) : (
                        periods.length > 0 && (
                            <Select
                                style={{ minWidth: 180 }}
                                value={activePeriodId}
                                onChange={setChosenPeriodId}
                                options={periods.map((period) => ({ value: period.id, label: period.label }))}
                            />
                        )
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
                form && (
                    <Card
                        className="pms-rate-card"
                        title={
                            <Space>
                                <span>
                                    {form.type.toUpperCase()} — {form.owner?.name ?? form.org_unit?.name}
                                </span>
                                <Tag color={statusMeta(form.status, form).color}>
                                    {statusMeta(form.status, form).label}
                                </Tag>
                            </Space>
                        }
                        extra={actions}
                        style={{ marginBottom: 16 }}
                    >
                        <div className="pms-mobile-only pms-rate-rows">
                            {indicators.map((record) => (
                                <div key={record.id} className="pms-rate-row">
                                    <span className="pms-rate-summary">
                                        {toPlainText(record.description) || "Untitled line"}
                                    </span>
                                    <Button
                                        type="text"
                                        className="pms-rate-open"
                                        onClick={() => setLineId(record.id)}
                                    >
                                        Open
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <div className="pms-mobile-only pms-rate-actions">{actions}</div>
                        <div className="pms-desktop-only">
                            <Table
                                rowKey="id"
                                dataSource={indicators}
                                columns={columns}
                                pagination={false}
                                size="small"
                                scroll={{ x: isOpcr ? 1200 : 900 }}
                            />
                        </div>
                    </Card>
                )
            )}

            <Drawer
                className="pms-rate-drawer"
                title="Commitment"
                placement="right"
                width={420}
                open={Boolean(openLine)}
                onClose={() => setLineId(null)}
            >
                {openLine && (
                    <>
                        <div className="pms-rate-field">
                            <span>Output</span>
                            <div>{toPlainText(openLine.output?.title || openLine.output?.description) || "—"}</div>
                            {renderMarks(marksForOutput(openLine.output?.id))}
                        </div>
                        <div className="pms-rate-field">
                            <span>Success indicator</span>
                            <div>{toPlainText(openLine.description) || "Untitled line"}</div>
                            {renderMarks(marksForLine(openLine.id, "description"))}
                        </div>
                        <div className="pms-rate-field">
                            <span>Target date</span>
                            <div>
                                <span className="pms-review-date">
                                    {formatTargetDate(openLine.target_date) || "—"}
                                </span>
                                {renderMarks(marksForLine(openLine.id, "target_date"))}
                            </div>
                        </div>
                        {isOpcr && (
                            <>
                                <div className="pms-rate-field">
                                    <span>Budget</span>
                                    <div>
                                        {openLine.allotted_budget != null && openLine.allotted_budget !== ""
                                            ? Number(openLine.allotted_budget).toLocaleString()
                                            : "—"}
                                        {renderMarks(marksForLine(openLine.id, "allotted_budget"))}
                                    </div>
                                </div>
                                <div className="pms-rate-field">
                                    <span>Accountable</span>
                                    <div>
                                        {accountableLabel(openLine) || "—"}
                                        {renderMarks(marksForLine(openLine.id, "accountable"))}
                                    </div>
                                </div>
                            </>
                        )}
                        <div className="pms-rate-field">
                            <span>Line note</span>
                            <div>{lineNoteField(openLine.id, 3)}</div>
                        </div>
                    </>
                )}
            </Drawer>

            {form && (
                <ReturnPlacesModal
                    open={returnModal}
                    form={form}
                    confirming={returnForm.isPending}
                    onCancel={() => setReturnModal(false)}
                    onSubmit={(fixes) => returnForm.mutate(fixes)}
                />
            )}
        </>
    );
}
