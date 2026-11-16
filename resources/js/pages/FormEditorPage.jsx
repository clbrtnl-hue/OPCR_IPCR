import React, { useEffect, useMemo, useRef, useState } from "react";
import {
    Alert,
    Button,
    Badge,
    Card,
    Col,
    Descriptions,
    Drawer,
    Dropdown,
    Empty,
    Modal,
    Popconfirm,
    Row,
    Segmented,
    Space,
    Spin,
    Steps,
    Tag,
    Timeline,
    Tooltip,
    Typography,
    message,
} from "antd";
import {
    CheckCircleOutlined,
    CheckOutlined,
    ClockCircleOutlined,
    DownOutlined,
    EditOutlined,
    ExpandOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    LockOutlined,
    MessageOutlined,
    MinusOutlined,
    NotificationOutlined,
    PrinterOutlined,
    ProfileOutlined,
    SafetyCertificateOutlined,
    SendOutlined,
    ShareAltOutlined,
    StarOutlined,
    TrophyOutlined,
    UndoOutlined,
} from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useParams, useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import api from "~/utils/api";
import { downloadFile } from "~/utils/download";
import { ADJECTIVAL_COLORS, nextReviewStop, statusMeta } from "~/utils/constants";
import { dimensionPhrase } from "~/utils/ratingScale";
import { useAuth } from "~/hooks/useAuth";
import PageHeader from "~/components/PageHeader";
import { toPlainText } from "~/components/RichTextView";

dayjs.extend(relativeTime);
import CascadeModal from "~/components/CascadeModal";
import TemplatePicker from "~/components/TemplatePicker";
import OpcrSheet from "~/components/OpcrSheet";
import IpcrSheet from "~/components/IpcrSheet";
import PdfPreview from "~/components/PdfPreview";
import CopyFromYear from "~/components/CopyFromYear";
import CopyPreviousPeriod from "~/components/CopyPreviousPeriod";
import CommentThread from "~/components/CommentThread";
import FormHistory from "~/components/FormHistory";
import ReturnPlacesModal from "~/components/ReturnPlacesModal";
import { RETURN_FIELD_LABELS } from "~/utils/returnMarks";

/**
 * What each step in a form's life looks like. The icon carries the meaning at a
 * glance — a return is not the same kind of event as an approval, and a stage
 * nobody filled is quieter still.
 */
const HISTORY_META = {
    draft:       { icon: <EditOutlined />,           color: "#8c8c8c" },
    commitment_review: { icon: <SafetyCertificateOutlined />, color: "#d48806" },
    commitments_approved: { icon: <CheckCircleOutlined />, color: "#7cb305" },
    head_review: { icon: <SendOutlined />,           color: "#1677ff" },
    dean_review: { icon: <SendOutlined />,           color: "#1d4ed8" },
    vp_review:   { icon: <SendOutlined />,           color: "#2f54eb" },
    qa_approval: { icon: <SafetyCertificateOutlined />, color: "#d48806" },
    approved:    { icon: <CheckCircleOutlined />,    color: "#7cb305" },
    published:   { icon: <NotificationOutlined />,   color: "#1e3a72" },
    qa_rating:   { icon: <StarOutlined />,           color: "#722ed1" },
    rated:       { icon: <TrophyOutlined />,         color: "#13c2c2" },
    final:       { icon: <LockOutlined />,           color: "#389e0d" },
    returned:    { icon: <UndoOutlined />,           color: "#d4380d" },
    default:     { icon: <ClockCircleOutlined />,    color: "#8c8c8c" },
};


function sheetName(form) {
    if (!form) return "";

    if (form.type === "opcr") {
        return form.office_head?.name || form.owner?.name || form.org_unit?.name;
    }

    return form.owner?.name || form.org_unit?.name;
}

const WHOLE_YEAR_LABEL = "January to December";

const FILES_REQUIRED = 2;

function writeUpGap(name, hasNarrative, files) {
    const short = Math.max(0, FILES_REQUIRED - files);

    if (hasNarrative && short > 1) return `${name} still needs two files.`;
    if (hasNarrative && short === 1) return `${name} still needs one more file.`;
    if (!hasNarrative && short === 0) return `${name} still needs a narrative.`;
    if (!hasNarrative && short === 1) return `${name} still needs a narrative and one more file.`;

    return `${name} still needs a narrative and two files.`;
}

/** A line the writer reports themselves is done only with a narrative and two files. */
function writeUpGaps(form) {
    if (form?.type !== "ipcr") return [];

    const gaps = [];

    for (const output of form.outputs ?? []) {
        for (const line of output.indicators ?? []) {
            if ((line.children ?? []).length || (line.assignments ?? []).length) continue;

            const periodId = line.rating_period_id;
            const records = (line.accomplishments ?? []).filter(
                (row) => !periodId || row.rating_period_id === periodId
            );
            const narrative = (row) => toPlainText(row.actual_accomplishment);
            const done = records.some(
                (row) => narrative(row) && (row.attachments ?? []).length >= FILES_REQUIRED
            );

            if (done) continue;

            const text = toPlainText(line.description);
            const name = text ? (text.length > 60 ? `${text.slice(0, 59)}…` : text) : "A line";
            const hasNarrative = records.some((row) => narrative(row));
            const files = records.reduce(
                (most, row) => Math.max(most, (row.attachments ?? []).length),
                0
            );

            gaps.push(writeUpGap(name, hasNarrative, files));
        }
    }

    return gaps;
}

function ExportMenu({ excelBusy, onPrint, onExcel }) {
    return (
        <Dropdown
            menu={{
                items: [
                    { key: "print", icon: <PrinterOutlined />, label: "Print" },
                    { key: "excel", icon: <FileExcelOutlined />, label: "Excel", disabled: excelBusy },
                ],
                onClick: ({ key }) => {
                    if (key === "print") onPrint();
                    if (key === "excel" && !excelBusy) onExcel();
                },
            }}
        >
            <Button icon={<PrinterOutlined />} aria-label="Print or Excel">
                <span className="pms-btn-text">Print</span>
                <DownOutlined />
            </Button>
        </Dropdown>
    );
}

// An OPCR is planned and published first; it is rated later on the same document.
const OPCR_CHAIN = ["draft", "qa_approval", "approved", "published", "qa_rating", "rated", "final"];

function TargetList({ targets, notedIds }) {
    const noted = new Set(notedIds);

    return (
        <ul className="pms-assigned-targets">
            {targets.map((target) => (
                <li
                    key={target.id}
                    data-assigned-target={target.id}
                    className={noted.has(target.id) ? "is-noted" : undefined}
                >
                    <strong>{target.output_title}</strong>
                    {" — "}
                    {toPlainText(target.description)}
                    {target.assigned_by_name ? ` · ${target.assigned_by_name}` : ""}
                </li>
            ))}
        </ul>
    );
}

function AssignedTargets({ targets, notedIds }) {
    const noted = new Set(notedIds);
    const fromNotice = noted.size ? targets.filter((target) => noted.has(target.id)) : [];
    const rest = noted.size ? targets.filter((target) => !noted.has(target.id)) : targets;

    return (
        <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message={fromNotice.length ? "From this notification" : "Targets assigned to you"}
            description={
                <>
                    {fromNotice.length > 0 && <TargetList targets={fromNotice} notedIds={notedIds} />}
                    {rest.length > 0 && (
                        <>
                            {fromNotice.length > 0 && (
                                <div className="pms-assigned-also">Also assigned to you</div>
                            )}
                            <TargetList targets={rest} notedIds={[]} />
                        </>
                    )}
                </>
            }
        />
    );
}

export default function FormEditorPage({ formId = null }) {
    const { id: routeId } = useParams();
    const [searchParams] = useSearchParams();
    const focusLineId = Number(searchParams.get("line")) || null;
    const focusTargetsKey = [
        ...new Set(
            [searchParams.get("targets"), searchParams.get("target")]
                .filter(Boolean)
                .join(",")
                .split(",")
                .map((value) => Number(value))
                .filter((value) => value > 0)
        ),
    ].join(",");
    const focusTargetIds = focusTargetsKey ? focusTargetsKey.split(",").map(Number) : [];
    const focusOutputId = Number(searchParams.get("output")) || null;
    const notedPeriodId = Number(searchParams.get("period")) || null;
    const focusRemarks = searchParams.get("remarks") === "1";
    const focusedTarget = useRef(null);
    // Normalised: children invalidate ["pcr-form", String(...)], and a numeric
    // id from the prop would silently miss that cache key.
    const id = String(formId ?? routeId);
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [periodId, setPeriodId] = useState(null);
    const [returnModal, setReturnModal] = useState(false);
    const [returnMode, setReturnMode] = useState("approval");
    const [panelOpen, setPanelOpen] = useState(false);
    const [pdfOpen, setPdfOpen] = useState(false);
    const [excelBusy, setExcelBusy] = useState(false);
    const [sheetFull, setSheetFull] = useState(false);
    const [checking, setChecking] = useState(false);
    const [issues, setIssues] = useState(null);
    const [writeUpIssues, setWriteUpIssues] = useState(null);
    const [copyOpen, setCopyOpen] = useState(false);
    const [previousOpen, setPreviousOpen] = useState(false);
    const [cascadeOpen, setCascadeOpen] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);

    const { data: opcrTargets = [] } = useQuery({
        queryKey: ["opcr-targets", id],
        queryFn: () => api.get(`pcr-forms/${id}/opcr-targets`).then((r) => r.data),
        enabled: Boolean(id),
    });

    const { data: form, isLoading } = useQuery({
        queryKey: ["pcr-form", id],
        queryFn: () => api.get(`pcr-forms/${id}`).then((r) => r.data),
    });

    useEffect(() => {
        if (focusRemarks && form && !focusLineId) {
            setPanelOpen(true);
        }
    }, [focusRemarks, focusLineId, form?.id]);

    useEffect(() => {
        if (!focusTargetsKey || !form || focusedTarget.current === focusTargetsKey) return;

        const items = focusTargetsKey
            .split(",")
            .map((targetId) => document.querySelector(`[data-assigned-target="${targetId}"]`))
            .filter(Boolean);

        if (!items.length) return;

        focusedTarget.current = focusTargetsKey;
        items.forEach((item) => item.classList.add("is-new"));
        items[0].scrollIntoView({ behavior: "smooth", block: "center" });
        const timer = setTimeout(() => items.forEach((item) => item.classList.remove("is-new")), 2200);

        return () => clearTimeout(timer);
    }, [focusTargetsKey, form]);

    const periods = form?.school_year?.periods ?? [];
    // A period-pinned IPCR covers exactly one review period; the OPCR (and
    // legacy year-wide IPCRs) still switch between the year's two periods.
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const periodFromNotice = periods.some((period) => period.id === notedPeriodId) ? notedPeriodId : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periodFromNotice ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const exportExcel = async () => {
        if (!form) return;

        setExcelBusy(true);

        try {
            await downloadFile(
                `pcr-forms/${form.id}/xlsx?rating_period_id=${activePeriodId ?? ""}`,
                `${form.type.toUpperCase()} - ${sheetName(form)}.xlsx`,
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            );
        } catch {
            message.error("The Excel file could not be prepared.");
        } finally {
            setExcelBusy(false);
        }
    };

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", id] });

    const move = useMutation({
        mutationFn: ({ status, note, fixes }) =>
            api.post(`pcr-forms/${form.id}/status`, { status, note, fixes }),
        onSuccess: () => {
            message.success("Form updated.");
            setReturnModal(false);
            refresh();
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
        },
    });

    const finalize = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/finalize-rating`, { rating_period_id: activePeriodId }),
        onSuccess: ({ data }) => {
            message.success(
                `Rated ${Number(data.summary.final_average).toFixed(2)} — ${data.summary.adjectival}. It stays in your queue until you close the form.`
            );
            refresh();
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
        },
    });

    const isOwner = useMemo(() => {
        if (!form || !user) return false;
        if (form.type === "ipcr") return form.user_id === user.id;
        if (user.role === "president") return true;
        return form.org_unit_id === user.org_unit_id && user.role === "program_head";
    }, [form, user]);

    if (isLoading || !form) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    const viewedPeriod = periods.find((p) => p.id === activePeriodId);
    // An IPCR belongs to one review period. The OPCR covers the whole year, so
    // one locked semester does not close it — only a year whose every period
    // is locked does.
    const yearClosed =
        form.type === "opcr" && periods.length > 0 && periods.every((period) => period.is_locked);
    const periodLocked = form.type === "opcr" ? yearClosed : Boolean(viewedPeriod?.is_locked);
    const isOpcr = form.type === "opcr";
    const commitmentsApproved = Boolean(form.commitment_approved_at);
    const returnedFromRating =
        form.status === "returned" &&
        [...(form.status_logs ?? [])]
            .sort((a, b) => b.id - a.id)
            .find((log) => log.to_status === "returned")?.from_status === "qa_rating";
    // Commitments stay editable until they are approved. A return from rating
    // does not reopen them. An OPCR sent back from rating keeps its targets too.
    const planningOpen =
        !isOpcr && ["draft", "returned"].includes(form.status) && !commitmentsApproved;
    const canEditCommitment =
        isOwner &&
        !periodLocked &&
        (isOpcr ? form.status === "draft" || (form.status === "returned" && !returnedFromRating) : planningOpen);
    const workOpen =
        isOpcr ||
        commitmentsApproved ||
        !["draft", "returned", "commitment_review"].includes(form.status);
    const canRecordProgress =
        isOwner && workOpen && !["rated", "final"].includes(form.status) && !periodLocked;
    // Cascade opens once commitments are approved, and closes when rating starts.
    const handoutOpen =
        commitmentsApproved && ["commitments_approved", "returned"].includes(form.status);
    // Offer only what the server would accept: the organization decides which
    // roles hand work out, and terminal roles deliver their own commitments.
    const canAssignHeadings =
        isOwner &&
        !periodLocked &&
        Boolean(user.capabilities?.assign_outputs) &&
        (isOpcr ? !["rated", "final"].includes(form.status) : handoutOpen);
    const canAssign =
        isOwner &&
        !periodLocked &&
        Boolean(user.capabilities?.assign_indicators) &&
        (isOpcr ? !["rated", "final"].includes(form.status) : handoutOpen);
    const opcrHandoutClosed =
        isOpcr &&
        (["published", "qa_rating", "rated", "final"].includes(form.status) || returnedFromRating);
    const canNameAccountable = (canAssignHeadings || canAssign) && !opcrHandoutClosed;
    const canCascade = canAssign && !opcrHandoutClosed;

    const samePerson = (left, right) => Number(left) === Number(right);
    const namedReviewerId = form.review_steps?.find((step) => step.status === form.status)?.reviewer?.id;

    // Authority is being named on this form. One person may hold two posts.
    // QA rates their own staff here and does not send that IPCR upward.
    const isMyReviewStep =
        !isOpcr &&
        (namedReviewerId
            ? samePerson(namedReviewerId, user.id)
            : (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) ||
              (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)) ||
              (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id)));

    const opcrRules = user.workflow?.opcr ?? {
        creator_roles: ["president"],
        approver_roles: ["qa"],
        publisher_roles: ["president"],
        rating_trigger_roles: ["president"],
    };
    const raterRoles = user.workflow?.rater_roles ?? ["qa"];
    const holds = (roles) => roles.includes(user.role);
    const isRater = holds(raterRoles);
    const canCreateOpcr = holds(opcrRules.creator_roles);
    const canApproveOpcr = holds(opcrRules.approver_roles);
    const canPublishOpcr = holds(opcrRules.publisher_roles);
    const canTriggerRating = holds(opcrRules.rating_trigger_roles);

    const qaRatesStaff =
        isRater &&
        ((form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) ||
            (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)));

    const canScore =
        !periodLocked &&
        !isOpcr &&
        ((namedReviewerId && samePerson(namedReviewerId, user.id)) ||
            (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) ||
            (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)) ||
            (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id)) ||
            (isRater && form.status === "qa_rating"));
    // Q, E, T and A stay off the working sheet until someone is actually rating.
    // They stay once the rating is done. The printed form always includes them.
    const showScores = (
        isOpcr
            ? ["qa_rating", "rated", "final"]
            : ["head_review", "dean_review", "director_review", "vp_review", "qa_rating", "rated", "final"]
    ).includes(form.status);

    const nextStop = nextReviewStop(form);
    const nextStatus = nextStop.status;

    const opcrAction = !isOpcr
        ? null
        : canTriggerRating && form.status === "returned" && returnedFromRating
          ? { status: "qa_rating", label: "Send for rating", short: "For rating" }
          : canCreateOpcr && ["draft", "returned"].includes(form.status)
            ? { status: "qa_approval", label: "Send for approval", short: "Send" }
            : canApproveOpcr && form.status === "qa_approval"
              ? { status: "approved", label: "Approve targets" }
              : canPublishOpcr && form.status === "approved"
                ? { status: "published", label: "Publish" }
                : null;

    const canUnpublish = isOpcr && canPublishOpcr && form.status === "published";
    const canSendForRating = isOpcr && canTriggerRating && form.status === "published";
    const canClose = form.status === "rated" && (isRater || user.role === "president");
    const canSendCommitments = canEditCommitment && !isOpcr;
    const canSubmitForRating =
        isOwner &&
        !isOpcr &&
        !periodLocked &&
        commitmentsApproved &&
        ["commitments_approved", "returned"].includes(form.status);
    const canDecideCommitments =
        !isOpcr &&
        form.status === "commitment_review" &&
        samePerson(form.commitment_approver?.id, user.id);
    // The owner always sees the step bar. Someone else sees it only while the
    // form is waiting on them.
    const seesSteps =
        isOwner ||
        canDecideCommitments ||
        isMyReviewStep ||
        ((isRater && form.status === "qa_rating") || (canApproveOpcr && isOpcr && form.status === "qa_approval"));
    const approverLabel = form.commitment_approver?.label || "approval";
    const canReturnOpcr = isOpcr && canApproveOpcr && form.status === "qa_approval";
    const canReturnFromRating = isRater && form.status === "qa_rating";

    // Each review point carries its own commitments — January to June is not
    // rated on the same lines as July to December.
    const linesFor = (output) =>
        (output.indicators ?? []).filter(
            (i) => !i.rating_period_id || i.rating_period_id === activePeriodId
        );

    const lineCount = (form.outputs ?? []).reduce(
        (total, output) => total + linesFor(output).length,
        0
    );

    const summary = form.summaries?.find((s) => s.rating_period_id === activePeriodId);
    const reviewSteps = form.review_steps ?? [];
    const ratingChain = reviewSteps.length
        ? reviewSteps.map((step) => step.status)
        : ["head_review", "dean_review", "director_review", "vp_review", "qa_rating"];
    // Commitment approval and rating are separate sequences. Rating opens
    // once commitments are approved, and its first step is the draft the
    // writer still has to submit for review.
    const chain = isOpcr
        ? OPCR_CHAIN
        : commitmentsApproved
          ? ["commitments_approved", ...ratingChain, "rated", "final"]
          : ["draft", "commitment_review"];

    // A stage with nobody to fill it is passed over rather than stranding the
    // form, so the tracker should say so instead of implying a wait.
    const skippedStages = isOpcr
        ? []
        : reviewSteps.length
          ? reviewSteps.filter((step) => !step.filled).map((step) => step.status)
          : [
                !form.head_reviewer_id && "head_review",
                !form.dean_reviewer_id && "dean_review",
                !form.vp_reviewer_id && "vp_review",
            ].filter(Boolean);

    const metaFor = (status) => statusMeta(status, form);

    const whoIsAt = (status) => {
        const step = reviewSteps.find((item) => item.status === status);

        if (step) return step.reviewer?.name ?? null;

        const person =
            status === "commitment_review"
                ? form.commitment_approver
                : status === "head_review"
                ? form.head_reviewer
                : status === "dean_review"
                  ? form.dean_reviewer
                  : status === "vp_review"
                    ? form.vp_reviewer
                    : null;

        return person?.name ?? null;
    };
    // A reviewer sees only the step the form is waiting on. The owner keeps
    // the whole sequence.
    const shownChain = isOwner || !chain.includes(form.status) ? chain : [form.status];
    const stepIndex = Math.max(shownChain.indexOf(form.status), 0);
    const returnMarks = form.return_marks ?? [];
    const openReturn = (mode) => {
        setReturnMode(mode);
        setReturnModal(true);
    };

    return (
        <>
            <PageHeader
                title={`${form.type.toUpperCase()} — ${sheetName(form)}`}
                subtitle={[
                    form.school_year?.label,
                    form.org_unit?.name,
                    formPeriodId &&
                        (periods.find((p) => p.id === formPeriodId)?.label ??
                            form.rating_period?.label),
                ]
                    .filter(Boolean)
                    .join(" · ")}
                extra={
                    <div className="pms-form-head">
                        <Tag color={metaFor(form.status).color}>{metaFor(form.status).label}</Tag>
                        <Space wrap={false} className="pms-form-tools" size={8}>
                            <Badge count={(form.comments_count ?? 0) || 0} size="small" offset={[-4, 4]}>
                                <Button
                                    icon={<MessageOutlined />}
                                    onClick={() => setPanelOpen(true)}
                                >
                                    Remarks &amp; history
                                </Button>
                            </Badge>
                            <ExportMenu
                                excelBusy={excelBusy}
                                onPrint={() => setPdfOpen(true)}
                                onExcel={exportExcel}
                            />
                            {canSendCommitments && (
                                <Button
                                    type="primary"
                                    loading={move.isPending}
                                    disabled={!form.commitment_approver}
                                    onClick={() => move.mutate({ status: "commitment_review" })}
                                >
                                    <span className="pms-btn-long">
                                        {form.commitment_approver ? `Send to ${approverLabel}` : "Send"}
                                    </span>
                                    <span className="pms-btn-short">Send</span>
                                </Button>
                            )}
                            {canSubmitForRating && (
                                <Button
                                    type="primary"
                                    loading={move.isPending}
                                    onClick={() => {
                                        const gaps = writeUpGaps(form);

                                        if (gaps.length) {
                                            setWriteUpIssues(gaps);

                                            return;
                                        }

                                        move.mutate({ status: "head_review" });
                                    }}
                                >
                                    {isRater ? "Start rating" : "Submit for review"}
                                </Button>
                            )}
                            {opcrAction && (
                                <Button
                                    type="primary"
                                    loading={move.isPending || checking}
                                    onClick={async () => {
                                        if (opcrAction.status !== "qa_approval") {
                                            move.mutate({ status: opcrAction.status });

                                            return;
                                        }

                                        setChecking(true);

                                        try {
                                            const { data } = await api.get(
                                                `pcr-forms/${form.id}/readiness`
                                            );

                                            if (data.ready) {
                                                move.mutate({ status: opcrAction.status });
                                            } else {
                                                setIssues(data.issues);
                                            }
                                        } finally {
                                            setChecking(false);
                                        }
                                    }}
                                >
                                    <span className="pms-btn-long">{opcrAction.label}</span>
                                    <span className="pms-btn-short">
                                        {opcrAction.short ?? opcrAction.label}
                                    </span>
                                </Button>
                            )}
                        </Space>
                        <Space wrap={false} className="pms-form-actions" size={8}>
                        {canDecideCommitments && (
                            <>
                                <Button danger onClick={() => openReturn("approval")}>
                                    Return
                                </Button>
                                <Popconfirm
                                    title="Approve these commitments?"
                                    description="The writer can then hand work down and record tasks."
                                    okText="Approve"
                                    onConfirm={() => move.mutate({ status: "commitments_approved" })}
                                >
                                    <Button type="primary" loading={move.isPending}>
                                        Approve commitments
                                    </Button>
                                </Popconfirm>
                            </>
                        )}
                        {canReturnOpcr && (
                            <Button danger onClick={() => openReturn("approval")}>
                                Return
                            </Button>
                        )}
                        {canUnpublish && (
                            <Popconfirm
                                title="Unpublish this OPCR?"
                                description="It goes back to draft and its targets stop being available to new IPCR lines."
                                onConfirm={() => move.mutate({ status: "draft" })}
                            >
                                <Button>Unpublish</Button>
                            </Popconfirm>
                        )}
                                {canSendForRating && (
                            <Button
                                type="primary"
                                loading={move.isPending}
                                onClick={() => move.mutate({ status: "qa_rating" })}
                            >
                                <span className="pms-btn-long">Send for rating</span>
                                <span className="pms-btn-short">For rating</span>
                            </Button>
                        )}
                        {canClose && (
                            <Popconfirm
                                title="Close this form?"
                                description="It becomes final and can no longer be reopened or edited."
                                okText="Close form"
                                onConfirm={() => move.mutate({ status: "final" })}
                            >
                                <Button
                                    type="primary"
                                    icon={<LockOutlined />}
                                    loading={move.isPending}
                                >
                                    Close form
                                </Button>
                            </Popconfirm>
                        )}
                        {canReturnFromRating && (
                            <Button danger onClick={() => openReturn("writeup")}>
                                Return
                            </Button>
                        )}
                        {canScore && isRater && (
                            <Button
                                type="primary"
                                loading={finalize.isPending}
                                onClick={() => finalize.mutate()}
                            >
                                Finalize rating
                            </Button>
                        )}
                        {isMyReviewStep && (
                            <>
                                <Button danger onClick={() => openReturn("writeup")}>
                                    Return
                                </Button>
                                {!qaRatesStaff && (
                                    <Button
                                        type="primary"
                                        loading={move.isPending}
                                        onClick={() => move.mutate({ status: nextStatus })}
                                    >
                                        <span className="pms-btn-long">
                                            {`Send to ${nextStop.label}`}
                                        </span>
                                        <span className="pms-btn-short">
                                            {`To ${nextStop.short}`}
                                        </span>
                                    </Button>
                                )}
                            </>
                        )}
                        </Space>
                    </div>
                }
            />

            {canScore && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="Enter the rating on each line"
                    description={`Set ${dimensionPhrase(user)} on each line. Send the form on once every line has a score.`}
                />
            )}

            {periodLocked && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        form.type === "opcr"
                            ? `${form.school_year?.label || "This school year"} is closed`
                            : `${viewedPeriod.label} is locked`
                    }
                    description={
                        form.type === "opcr"
                            ? "This school year is finished. The OPCR is view-only now — ask an administrator to unlock a review period."
                            : "This period is finished. It is view-only now — ask an administrator to unlock it."
                    }
                />
            )}

            {planningOpen && isOwner && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="Commitments need approval first"
                    description={
                        form.commitment_wait
                            ? form.commitment_wait
                            : form.commitment_approver
                              ? `Send them to ${approverLabel}. Tasks and handing work down stay closed until they are approved.`
                              : "There is nobody to approve these commitments yet."
                    }
                />
            )}

            {form.status === "commitment_review" && !isOpcr && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        isOwner
                            ? `Waiting for ${approverLabel} to approve your commitments`
                            : "These commitments are waiting for approval"
                    }
                    description="Tasks and handing work down open after approval."
                />
            )}

            {form.status === "commitments_approved" && isOwner && (
                <Alert
                    type="success"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="Commitments approved"
                    description="You can hand work to your team and record tasks. Submit for review once every line has a narrative and two files."
                />
            )}

            {form.status === "returned" && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="This form was sent back to you"
                    description={
                        returnMarks.length ? (
                            <ul className="pms-return-list">
                                {returnMarks.map((mark) => (
                                    <li key={mark.id}>
                                        <strong>{RETURN_FIELD_LABELS[mark.field] ?? "This part"}.</strong>{" "}
                                        {mark.note}
                                    </li>
                                ))}
                            </ul>
                        ) : isOpcr && returnedFromRating ? (
                            "Targets and success indicators stay as they are. Update the actual accomplishment and the evidence, then send it back for rating."
                        ) : (
                            "Read the remarks below, make the corrections, then submit it again."
                        )
                    }
                />
            )}

            {seesSteps && (
            <Card style={{ marginBottom: 16 }}>
                <Steps
                    size="small"
                    className={`pms-chain${shownChain.length <= 2 ? " is-compact" : ""}`}
                    labelPlacement="vertical"
                    current={stepIndex}
                    status={form.status === "returned" ? "error" : "process"}
                    items={shownChain.map((status, index) => {
                        // Approved commitments sit at the start of the rating
                        // sequence as a draft, until the writer submits them.
                        const reviewDraft = status === "commitments_approved";
                        const meta = HISTORY_META[reviewDraft ? "draft" : status] ?? HISTORY_META.default;
                        const skipped = skippedStages.includes(status);
                        const done = index < stepIndex;
                        const here = index === stepIndex;

                        return {
                            title: (
                                <>
                                    <span className="pms-chain-long">
                                        {reviewDraft ? "Draft" : metaFor(status).label}
                                    </span>
                                    <span className="pms-chain-short">
                                        {
                                            {
                                                draft: "Draft",
                                                commitment_review: "Approval",
                                                commitments_approved: "Draft",
                                                head_review: "Head",
                                                dean_review: "Dean",
                                                director_review: "Director",
                                                vp_review: "VP",
                                                qa_approval: "QA",
                                                approved: "Approved",
                                                published: "Live",
                                                qa_rating: "Rating",
                                                rated: "Rated",
                                                final: "Final",
                                                returned: "Returned",
                                            }[status] ?? metaFor(status).label.replace(/^With /i, "")
                                        }
                                    </span>
                                </>
                            ),
                            // Naming the person beats "waiting for the program
                            // head" when the form already knows who that is.
                            description: skipped
                                ? "Skipped — nobody fills this stage"
                                : here
                                  ? reviewDraft
                                    ? "With the writer"
                                    : whoIsAt(status) ?? metaFor(status).who
                                  : undefined,
                            status: skipped ? "finish" : undefined,
                            icon: (
                                <span
                                    className={
                                        "pms-chain-dot" +
                                        (here ? " is-here" : "") +
                                        (done ? " is-done" : "") +
                                        (skipped ? " is-skipped" : "")
                                    }
                                >
                                    {skipped ? <MinusOutlined /> : done ? <CheckOutlined /> : meta.icon}
                                </span>
                            ),
                        };
                    })}
                />
            </Card>
            )}

            <Row gutter={16}>
                <Col xs={24} lg={24}>
                    <Card title="Commitments" style={{ marginBottom: 16 }}>
                        <div className="pms-commit-bar">
                            <div className="pms-commit-period">
                                {isOpcr ? (
                                    <Tag color="blue">{WHOLE_YEAR_LABEL}</Tag>
                                ) : formPeriodId ? (
                                    <Tag color="blue">
                                        {periods.find((p) => p.id === formPeriodId)?.label ??
                                            form.rating_period?.label}
                                    </Tag>
                                ) : (
                                    <Segmented
                                        block
                                        value={activePeriodId}
                                        onChange={setPeriodId}
                                        options={periods.map((p) => ({
                                            value: p.id,
                                            label: p.is_active ? `${p.label} ★` : p.label,
                                        }))}
                                    />
                                )}
                            </div>
                            <div className="pms-commit-actions">
                                {!isOpcr && canEditCommitment && form.outputs.length === 0 && (
                                    <Button
                                        className="pms-commit-text"
                                        size="small"
                                        aria-label="Copy previous period"
                                        onClick={() => setPreviousOpen(true)}
                                    >
                                        <span className="pms-btn-long">Copy previous period</span>
                                        <span className="pms-btn-short">Copy period</span>
                                    </Button>
                                )}
                                {isOpcr && canEditCommitment && form.outputs.length === 0 && (
                                    <Button
                                        className="pms-commit-text"
                                        size="small"
                                        aria-label="Start from another year"
                                        onClick={() => setCopyOpen(true)}
                                    >
                                        <span className="pms-btn-long">Start from another year</span>
                                        <span className="pms-btn-short">Copy year</span>
                                    </Button>
                                )}
                                {canEditCommitment && (
                                    <Button
                                        className="pms-commit-text"
                                        size="small"
                                        icon={<ProfileOutlined />}
                                        onClick={() => setTemplateOpen(true)}
                                    >
                                        Insert template
                                    </Button>
                                )}
                                {canCascade && form.outputs.length > 0 && (
                                    <Button
                                        className="pms-commit-text"
                                        size="small"
                                        icon={<ShareAltOutlined />}
                                        aria-label="Cascade to people"
                                        onClick={() => setCascadeOpen(true)}
                                    >
                                        <span className="pms-btn-long">Cascade to people</span>
                                        <span className="pms-btn-short">Cascade</span>
                                    </Button>
                                )}
                                {(isOpcr || form.outputs.length > 0) && (
                                    <Tooltip title="Open the sheet full width">
                                        <Button
                                            size="small"
                                            icon={<ExpandOutlined />}
                                            onClick={() => setSheetFull(true)}
                                        />
                                    </Tooltip>
                                )}
                            </div>
                        </div>
                        {!isOpcr && (form.assigned_targets ?? []).length > 0 && (
                            <AssignedTargets
                                targets={form.assigned_targets}
                                notedIds={focusTargetIds}
                            />
                        )}
                        {isOpcr ? (
                            <OpcrSheet
                                form={form}
                                periodId={activePeriodId}
                                canEdit={canEditCommitment}
                                canAssign={canNameAccountable}
                                canRecordProgress={canRecordProgress}
                                showScores={showScores}
                                summary={summary}
                                focusLineId={focusLineId}
                                focusOutputId={focusOutputId}
                                returnMarks={returnMarks}
                            />
                        ) : form.outputs.length === 0 && !canEditCommitment ? (
                            <Empty description="No commitments yet." />
                        ) : (
                            <IpcrSheet
                                form={form}
                                periodId={activePeriodId}
                                canEdit={canEditCommitment}
                                canRecordProgress={canRecordProgress}
                                canAssign={canAssign}
                                canAssignHeadings={canAssignHeadings}
                                canScore={canScore}
                                showScores={showScores}
                                opcrTargets={opcrTargets}
                                summary={summary}
                                focusLineId={focusLineId}
                                focusOutputId={focusOutputId}
                                returnMarks={returnMarks}
                            />
                        )}
                    </Card>
                </Col>

            </Row>

            <Drawer
                open={sheetFull}
                onClose={() => setSheetFull(false)}
                placement="right"
                width="100%"
                className="pms-sheet-drawer"
                title={
                    <Space size={12} wrap>
                        <span>
                            {form.type.toUpperCase()} — {sheetName(form)}
                        </span>
                        <Tag color={metaFor(form.status).color}>
                            {metaFor(form.status).label}
                        </Tag>
                        <Typography.Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>
                            {form.school_year?.label} · {lineCount} commitment
                            {lineCount === 1 ? "" : "s"} in{" "}
                            {isOpcr
                                ? WHOLE_YEAR_LABEL
                                : periods.find((p) => p.id === activePeriodId)?.label}
                        </Typography.Text>
                    </Space>
                }
                extra={
                    <div className="pms-sheet-drawer-tools">
                        <div className="pms-sheet-drawer-period">
                            {isOpcr ? (
                                <Tag color="blue">{WHOLE_YEAR_LABEL}</Tag>
                            ) : formPeriodId ? (
                                <Tag color="blue">
                                    {periods.find((p) => p.id === formPeriodId)?.label ??
                                        form.rating_period?.label}
                                </Tag>
                            ) : (
                                <Segmented
                                    block
                                    size="small"
                                    value={activePeriodId}
                                    onChange={setPeriodId}
                                    options={periods.map((p) => ({
                                        value: p.id,
                                        label: p.is_active ? `${p.label} ★` : p.label,
                                    }))}
                                />
                            )}
                        </div>
                        <div className="pms-sheet-drawer-actions">
                            <ExportMenu
                                excelBusy={excelBusy}
                                onPrint={() => setPdfOpen(true)}
                                onExcel={exportExcel}
                            />
                            <Button onClick={() => setSheetFull(false)}>Close</Button>
                        </div>
                    </div>
                }
            >
                {isOpcr ? (
                    <OpcrSheet
                        form={form}
                        periodId={activePeriodId}
                        canEdit={canEditCommitment}
                        canAssign={canNameAccountable}
                        canRecordProgress={canRecordProgress}
                        showScores={showScores}
                        summary={summary}
                        returnMarks={returnMarks}
                    />
                ) : (
                    <IpcrSheet
                        form={form}
                        periodId={activePeriodId}
                        canEdit={canEditCommitment}
                        canRecordProgress={canRecordProgress}
                        canAssign={canAssign}
                        canAssignHeadings={canAssignHeadings}
                        canScore={canScore}
                        showScores={showScores}
                        opcrTargets={opcrTargets}
                        summary={summary}
                        returnMarks={returnMarks}
                    />
                )}
            </Drawer>

            <Modal
                open={Boolean(writeUpIssues)}
                onCancel={() => setWriteUpIssues(null)}
                title="Finish the write-up first"
                okText="Go back and finish"
                onOk={() => setWriteUpIssues(null)}
                cancelButtonProps={{ style: { display: "none" } }}
            >
                <Typography.Paragraph type="secondary">
                    This form stays with you until every line has a narrative and two files. It is
                    not sent for review yet.
                </Typography.Paragraph>
                <ul style={{ paddingLeft: 18, margin: 0 }}>
                    {(writeUpIssues ?? []).map((issue, index) => (
                        <li key={`${index}-${issue}`} style={{ marginBottom: 4 }}>
                            {issue}
                        </li>
                    ))}
                </ul>
            </Modal>

            <Modal
                open={Boolean(issues)}
                onCancel={() => setIssues(null)}
                title={`${issues?.length ?? 0} thing${issues?.length === 1 ? "" : "s"} need attention`}
                okText="Send to QA anyway"
                onOk={() => {
                    setIssues(null);
                    move.mutate({ status: "qa_approval" });
                }}
                cancelText="Go back and fix"
            >
                <Typography.Paragraph type="secondary">
                    QA will see the form as it stands. These are the gaps they would most likely
                    send it back for.
                </Typography.Paragraph>
                <ul style={{ paddingLeft: 18, margin: 0 }}>
                    {(issues ?? []).map((issue) => (
                        <li key={issue} style={{ marginBottom: 4 }}>
                            {issue}
                        </li>
                    ))}
                </ul>
            </Modal>

            <CopyFromYear
                open={copyOpen}
                onClose={() => setCopyOpen(false)}
                form={form}
            />

            <CopyPreviousPeriod
                open={previousOpen}
                onClose={() => setPreviousOpen(false)}
                form={form}
            />

            <CascadeModal
                open={cascadeOpen}
                onClose={() => setCascadeOpen(false)}
                form={form}
                periodId={activePeriodId}
            />

            <TemplatePicker
                open={templateOpen}
                onClose={() => setTemplateOpen(false)}
                form={form}
            />

            <PdfPreview
                open={pdfOpen}
                onClose={() => setPdfOpen(false)}
                url={`pcr-forms/${form.id}/pdf?rating_period_id=${activePeriodId}`}
                filename={`${form.type.toUpperCase()} - ${sheetName(form)}.pdf`}
                title={`${form.type.toUpperCase()} — ${sheetName(form)}`}
            />

            <Drawer
                title={`${form.type.toUpperCase()} — remarks and history`}
                placement="right"
                width={560}
                open={panelOpen}
                onClose={() => setPanelOpen(false)}
            >
{summary && (
                        <Card title="Rating" style={{ marginBottom: 16 }}>
                            <Descriptions column={1} size="small">
                                {summary.strategic_average != null && (
                                    <Descriptions.Item label="Strategic priority">
                                        {Number(summary.strategic_average).toFixed(2)}
                                    </Descriptions.Item>
                                )}
                                {summary.core_average != null && (
                                    <Descriptions.Item label="Core functions">
                                        {Number(summary.core_average).toFixed(2)}
                                    </Descriptions.Item>
                                )}
                                {summary.support_average != null && (
                                    <Descriptions.Item label="Support functions">
                                        {Number(summary.support_average).toFixed(2)}
                                    </Descriptions.Item>
                                )}
                                <Descriptions.Item label="Final average rating">
                                    <Typography.Text strong>
                                        {Number(summary.final_average).toFixed(2)}
                                    </Typography.Text>
                                </Descriptions.Item>
                                <Descriptions.Item label="Adjectival rating">
                                    <Tag color={ADJECTIVAL_COLORS[summary.adjectival]}>{summary.adjectival}</Tag>
                                </Descriptions.Item>
                            </Descriptions>
                        </Card>
                    )}

                    <Card title="Remarks" style={{ marginBottom: 16 }}>
                        <CommentThread formId={form.id} />
                    </Card>

                    <Card title="History" styles={{ body: { paddingTop: 12 } }}>
                        <FormHistory formId={form.id} />
                    </Card>
            </Drawer>

            <ReturnPlacesModal
                open={returnModal}
                form={form}
                kind={returnMode}
                confirming={move.isPending}
                onCancel={() => setReturnModal(false)}
                onSubmit={(fixes) => move.mutate({ status: "returned", fixes })}
            />
        </>
    );
}
