import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
    Select,
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
import api from "~/utils/api";
import { downloadFile } from "~/utils/download";
import { ADJECTIVAL_COLORS, RATING_LEGEND, statusMeta } from "~/utils/constants";
import { ratingRules, scoreOptions } from "~/utils/ratingScale";
import { useAuth } from "~/hooks/useAuth";
import PageHeader from "~/components/PageHeader";
import { toPlainText } from "~/components/RichTextView";
import AssignedTargets from "~/components/AssignedTargets";
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
import FormSupportDesk from "~/components/FormSupportDesk";
import { RETURN_FIELD_LABELS, displayReturnMarks } from "~/utils/returnMarks";

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

function lineLabel(line) {
    const text = toPlainText(line?.description);

    return text ? (text.length > 60 ? `${text.slice(0, 59)}…` : text) : "A target";
}

/**
 * Someone named on the line writes the narrative on their own commitment.
 * A blank one blocks submit on an IPCR and on the college OPCR.
 */
function accountableNarrativeGaps(form) {
    const periodId = form?.type === "ipcr" ? form.rating_period_id : null;
    const gaps = [];

    for (const output of form?.outputs ?? []) {
        for (const line of output.indicators ?? []) {
            if (line.closed_at) continue;

            const people = new Map();

            for (const row of line.assignments ?? []) {
                if (!row.user) continue;
                if (periodId && row.rating_period_id && row.rating_period_id !== periodId) continue;

                people.set(Number(row.user.id), row.user.name);
            }

            const children = (line.children ?? []).filter((child) => {
                if (child.closed_at || !child.output?.form?.owner) return false;
                if (periodId && child.rating_period_id && child.rating_period_id !== periodId) return false;

                return true;
            });

            for (const child of children) {
                people.set(Number(child.output.form.owner.id), child.output.form.owner.name);
            }

            if (people.size === 0) continue;

            const target = lineLabel(line);

            for (const [id, name] of people) {
                const theirs = children.filter((child) => Number(child.output.form.owner.id) === id);

                if (theirs.length === 0) {
                    gaps.push(`${name} still needs a narrative for “${target}”.`);

                    continue;
                }

                for (const child of theirs) {
                    if (Number(child.recorded_count ?? 0) > 0) continue;

                    const label = lineLabel(child);
                    gaps.push(`${name} still needs a narrative for “${label === "A target" ? target : label}”.`);
                }
            }
        }
    }

    return gaps;
}

/** A line the writer reports themselves is done only with a narrative and two files. */
function writeUpGaps(form) {
    if (form?.type !== "ipcr") return [];

    const gaps = accountableNarrativeGaps(form);

    for (const output of form.outputs ?? []) {
        for (const line of output.indicators ?? []) {
            if ((line.children ?? []).length || (line.assignments ?? []).length) continue;
            if (line.closed_at && line.closed_reason === "target_replaced") continue;

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

function countsTowardRating(line) {
    if (line?.closed_at || line?.replacement_status === "pending") return false;

    return !line?.needs_commitment_approval || Boolean(line?.line_approved_at);
}

/** A note when rating is early or the school year is already over. Null when December has arrived. */
function ratingPeriodWarning(schoolYear) {
    const start = schoolYear?.start_date;
    const end = schoolYear?.end_date;

    if (!start || !end) {
        return "The rating period has not arrived. Rating opens in December.";
    }

    const startDate = dayjs(start).startOf("day");
    const endDate = dayjs(end).endOf("day");
    let opening = dayjs(`${startDate.year()}-12-01`).startOf("day");

    if (opening.isBefore(startDate, "day")) {
        opening = opening.add(1, "year");
    }

    const today = dayjs().startOf("day");

    if (today.isBefore(opening, "day")) {
        return "The rating period has not arrived. Rating opens in December.";
    }

    if (today.isAfter(endDate, "day")) {
        return "This school year has ended.";
    }

    return null;
}

/** Office targets that are not yet at 100%. The president may still start rating. */
function unfinishedOpcrWork(form) {
    const names = [];

    for (const output of form?.outputs ?? []) {
        for (const line of output.indicators ?? []) {
            if (!countsTowardRating(line)) continue;
            if (line.progress_status === "completed" || Number(line.progress_pct ?? 0) >= 100) continue;

            const text = toPlainText(line.description);
            names.push(text ? (text.length > 60 ? `${text.slice(0, 59)}…` : text) : "A target");
        }
    }

    return names;
}

const SOFT_ALERT_MS = 5000;

function OpcrRateLegend({ user }) {
    const bands = user?.workflow?.rating?.bands?.length ? user.workflow.rating.bands : RATING_LEGEND;

    return (
        <Space wrap>
            {bands.map((band) => (
                <Tag key={band.label} color={ADJECTIVAL_COLORS[band.label]}>
                    {band.value} — {band.label}
                    {band.range ? ` (${band.range})` : ""}
                </Tag>
            ))}
        </Space>
    );
}

function SelfRateLegend({ user }) {
    const bands = user?.workflow?.rating?.bands?.length ? user.workflow.rating.bands : RATING_LEGEND;

    return (
        <Space wrap>
            <Typography.Text type="secondary">
                Self-rating is optional. The next reviewer sees these scores and can replace them.
            </Typography.Text>
            {bands.map((band) => (
                <Tag key={band.label} color={ADJECTIVAL_COLORS[band.label]}>
                    {band.value} — {band.label}
                    {band.range ? ` (${band.range})` : ""}
                </Tag>
            ))}
        </Space>
    );
}

/**
 * Sticky (lock / returned): stay until closed.
 * Soft (planning / waiting / approved): auto-hide after a few seconds, also closable.
 */
function FormStatusAlerts({
    form,
    isOwner,
    isOpcr,
    periodLocked,
    hideLockAlert = false,
    viewedPeriod,
    planningOpen,
    approverLabel,
    returnMarks,
    returnedFromRating,
}) {
    const [hidden, setHidden] = useState(() => new Set());
    const softKey = periodLocked
        ? null
        : planningOpen
          ? "planning"
          : form.status === "commitment_review" && !isOpcr
            ? "commitment_review"
            : form.status === "commitments_approved"
              ? "commitments_approved"
              : null;

    useEffect(() => {
        setHidden(new Set());
    }, [form.id, form.status, periodLocked, planningOpen]);

    useEffect(() => {
        if (!softKey || !isOwner) return undefined;
        const timer = window.setTimeout(() => {
            setHidden((prev) => {
                if (prev.has(softKey)) return prev;
                const next = new Set(prev);
                next.add(softKey);
                return next;
            });
        }, SOFT_ALERT_MS);
        return () => window.clearTimeout(timer);
    }, [softKey, isOwner, form.id]);

    const hide = (key) =>
        setHidden((prev) => {
            if (prev.has(key)) return prev;
            const next = new Set(prev);
            next.add(key);
            return next;
        });

    const alertProps = (key) => ({
        showIcon: true,
        closable: true,
        style: { marginBottom: 16 },
        onClose: () => hide(key),
    });

    if (periodLocked) {
        if (hideLockAlert || hidden.has("locked")) return null;
        return (
            <Alert
                type="warning"
                {...alertProps("locked")}
                message={
                    form.type === "opcr"
                        ? `${form.school_year?.label || "This school year"} is closed`
                        : `${viewedPeriod.label} is locked`
                }
                description={
                    form.type === "opcr"
                        ? "The OPCR is view-only now — ask an administrator to unlock a review period."
                        : "It is view-only now — ask an administrator to unlock it."
                }
            />
        );
    }

    if (!isOwner) return null;

    return (
        <>
            {planningOpen && softKey === "planning" && !hidden.has("planning") && (
                <Alert
                    type="info"
                    {...alertProps("planning")}
                    message="Commitments need approval first"
                    description={
                        form.commitment_wait
                            ? form.commitment_wait
                            : form.commitment_approver
                              ? `Send them to ${approverLabel}. You can name accountable people now; tasks stay closed until commitments are approved.`
                              : "There is nobody to approve these commitments yet."
                    }
                />
            )}
            {softKey === "commitment_review" && !hidden.has("commitment_review") && (
                <Alert
                    type="info"
                    {...alertProps("commitment_review")}
                    message={`Waiting for ${approverLabel} to approve your commitments`}
                    description="Tasks and handing work down open after approval."
                />
            )}
            {softKey === "commitments_approved" && !hidden.has("commitments_approved") && (
                <Alert
                    type="success"
                    {...alertProps("commitments_approved")}
                    message="Commitments approved"
                    description="You can hand work to your team and record tasks. Submit for review once every line has a narrative and two files. Self-rating is optional."
                />
            )}
            {form.status === "returned" && !hidden.has("returned") && (
                <Alert
                    type="warning"
                    {...alertProps("returned")}
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
                            form.commitment_approved_at
                                ? "Read the feedback below, make the corrections, then submit it again. Self-rating is optional."
                                : "Read the feedback below, make the corrections, then submit it again."
                        )
                    }
                />
            )}
        </>
    );
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

// The president rates the published OPCR and closes it. QA approves the plan only.
const OPCR_CHAIN = ["draft", "qa_approval", "approved", "published", "final"];
// A form already sent to QA for rating keeps that older sequence.
const OPCR_SENT_CHAIN = ["draft", "qa_approval", "approved", "published", "qa_rating", "rated", "final"];

export default function FormEditorPage({ formId = null, periodBar = null, yearSelect = null, filters = null, scoreEdit = null, hideTitle = false, hideProgress = false, hideLockAlert = false, toolbarExtra = null, scoreLegend = null, liveSummary = null }) {
    const { id: routeId } = useParams();
    const [searchParams] = useSearchParams();
    const focusLineId = Number(searchParams.get("line")) || null;
    // Return notices use fix=1 — highlight the row, leave the drawer closed.
    const openFocusedLine = searchParams.get("fix") !== "1";
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
    const [panelOpen, setPanelOpen] = useState(false);
    const [pdfOpen, setPdfOpen] = useState(false);
    const [excelBusy, setExcelBusy] = useState(false);
    const [sheetFull, setSheetFull] = useState(false);
    const replaceRun = useRef(() => {});
    const [replaceUi, setReplaceUi] = useState(null);
    const restartRun = useRef(() => {});
    const [restartUi, setRestartUi] = useState(null);
    const onRestartUi = useCallback((ui) => {
        if (!ui) {
            restartRun.current = () => {};
            setRestartUi(null);

            return;
        }

        restartRun.current = ui.run;
        setRestartUi((prev) => {
            const next = {
                showSend: ui.showSend,
                count: ui.count,
                ready: ui.ready,
                sendPending: ui.sendPending,
                showDecide: ui.showDecide,
                waiting: ui.waiting,
                decidePending: ui.decidePending,
            };

            if (
                prev &&
                prev.showSend === next.showSend &&
                prev.count === next.count &&
                prev.ready === next.ready &&
                prev.sendPending === next.sendPending &&
                prev.showDecide === next.showDecide &&
                prev.waiting === next.waiting &&
                prev.decidePending === next.decidePending
            ) {
                return prev;
            }

            return next;
        });
    }, []);
    const onReplaceUi = useCallback((ui) => {
        if (!ui) {
            replaceRun.current = () => {};
            setReplaceUi(null);

            return;
        }

        replaceRun.current = ui.run;
        setReplaceUi((prev) => {
            const next = {
                showReplace: ui.showReplace,
                editing: ui.editing,
                changedCount: ui.changedCount,
                proposePending: ui.proposePending,
                showDecide: ui.showDecide,
                decidePending: ui.decidePending,
            };

            if (
                prev &&
                prev.showReplace === next.showReplace &&
                prev.editing === next.editing &&
                prev.changedCount === next.changedCount &&
                prev.proposePending === next.proposePending &&
                prev.showDecide === next.showDecide &&
                prev.decidePending === next.decidePending
            ) {
                return prev;
            }

            return next;
        });
    }, []);
    const [phoneChain, setPhoneChain] = useState(
        () => typeof window !== "undefined" && window.matchMedia("(max-width: 991px)").matches
    );
    useEffect(() => {
        const query = window.matchMedia("(max-width: 991px)");
        const sync = () => setPhoneChain(query.matches);
        sync();
        query.addEventListener("change", sync);
        return () => query.removeEventListener("change", sync);
    }, []);
    const [checking, setChecking] = useState(false);
    const [issues, setIssues] = useState(null);
    const [writeUpIssues, setWriteUpIssues] = useState(null);
    const [selfScores, setSelfScores] = useState({});
    const selfDirty = useRef(new Set());
    const [opcrRatingOn, setOpcrRatingOn] = useState(false);
    const [finalizing, setFinalizing] = useState(false);
    const [copyOpen, setCopyOpen] = useState(false);
    const [previousOpen, setPreviousOpen] = useState(false);
    const [cascadeOpen, setCascadeOpen] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);
    const [arrived, setArrived] = useState(null);
    const showLine = (arrive) => {
        if (arrive == null) return;

        if (typeof arrive === "object") {
            setArrived({
                id: arrive.id,
                outputId: arrive.outputId ?? null,
                openOutput: Boolean(arrive.openOutput),
                tick: Date.now(),
            });
            return;
        }

        setArrived({ id: arrive, outputId: null, openOutput: false, tick: Date.now() });
    };

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
        selfDirty.current = new Set();
        setOpcrRatingOn(false);
    }, [form?.id, form?.status, form?.rating_period_id]);

    useEffect(() => {
        if (!form || scoreEdit) return;
        if (form.type !== "ipcr" || Number(form.user_id) !== Number(user?.id)) return;
        if (!form.commitment_approved_at) return;
        if (!["commitments_approved", "returned"].includes(form.status)) return;

        const periodForScores = form.rating_period_id;

        setSelfScores((prev) => {
            const next = {};

            (form.outputs ?? []).forEach((output) => {
                (output.indicators ?? []).forEach((indicator) => {
                    if (periodForScores && indicator.rating_period_id && indicator.rating_period_id !== periodForScores) {
                        return;
                    }

                    if (selfDirty.current.has(indicator.id) && prev[indicator.id]) {
                        next[indicator.id] = prev[indicator.id];

                        return;
                    }

                    const existing = (indicator.ratings ?? []).find(
                        (row) => !periodForScores || row.rating_period_id === periodForScores
                    );

                    next[indicator.id] = {
                        q: existing?.q ?? null,
                        e: existing?.e ?? null,
                        t: existing?.t ?? null,
                        remarks: existing?.remarks ?? "",
                    };
                });
            });

            return next;
        });
    }, [form, user?.id, scoreEdit]);

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

    useEffect(() => {
        if (!opcrRatingOn || !form || form.type !== "opcr") return;

        setSelfScores((prev) => {
            const next = {};

            (form.outputs ?? []).forEach((output) => {
                (output.indicators ?? []).forEach((indicator) => {
                    if (!countsTowardRating(indicator)) return;
                    if (activePeriodId && indicator.rating_period_id && indicator.rating_period_id !== activePeriodId) {
                        return;
                    }

                    if (selfDirty.current.has(indicator.id) && prev[indicator.id]) {
                        next[indicator.id] = prev[indicator.id];

                        return;
                    }

                    const existing = (indicator.ratings ?? []).find(
                        (row) => !activePeriodId || row.rating_period_id === activePeriodId
                    );

                    next[indicator.id] = {
                        q: existing?.q ?? null,
                        e: existing?.e ?? null,
                        t: existing?.t ?? null,
                        remarks: existing?.remarks ?? "",
                    };
                });
            });

            return next;
        });
    }, [opcrRatingOn, form, activePeriodId]);

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

    const saveSelfRating = useMutation({
        mutationFn: (ratings) =>
            api.post("pcr-ratings", {
                form_id: form.id,
                rating_period_id: form.rating_period_id ?? activePeriodId,
                ratings,
            }),
        onSuccess: () => {
            selfDirty.current = new Set();
            queryClient.invalidateQueries({ queryKey: ["pcr-form", id] });
        },
    });

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
    // OPCR delivery opens once targets are published (or sent back from rating).
    const opcrDeliveryOpen =
        ["published", "qa_rating", "rated", "final"].includes(form.status) || returnedFromRating;
    const canRecordProgress =
        isOwner &&
        workOpen &&
        !["rated", "final"].includes(form.status) &&
        !periodLocked &&
        (!isOpcr || opcrDeliveryOpen);
    // Name people while writing commitments (like OPCR), and keep handing work
    // out after approval until rating starts.
    const handoutOpen =
        (planningOpen && !commitmentsApproved) ||
        (commitmentsApproved && ["commitments_approved", "returned"].includes(form.status));
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

    // Named on this form for the step it is in. The step bar uses this.
    // Scoring, returning, and sending on happen in the Rating tab.
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

    // Actual accomplishments stay hidden while the plan is still being written
    // or approved. IPCR waits on commitment approval; OPCR waits on publish.
    const showAccomplishments = isOpcr ? opcrDeliveryOpen : workOpen;

    const opcrAction = !isOpcr
        ? null
        : canCreateOpcr && (form.status === "draft" || (form.status === "returned" && !returnedFromRating))
          ? { status: "qa_approval", label: "Send for approval", short: "Send" }
          : canApproveOpcr && form.status === "qa_approval"
            ? { status: "approved", label: "Approve targets" }
            : canPublishOpcr && form.status === "approved"
              ? { status: "published", label: "Publish" }
              : null;

    const canStartOpcrRating =
        isOpcr &&
        canTriggerRating &&
        (form.status === "published" || (form.status === "returned" && returnedFromRating));
    const canSendCommitments = canEditCommitment && !isOpcr;
    const canSubmitForRating =
        isOwner &&
        !isOpcr &&
        !periodLocked &&
        commitmentsApproved &&
        ["commitments_approved", "returned"].includes(form.status);
    const selfRateRows = () =>
        Object.entries(selfScores).flatMap(([indicatorId, value]) => {
            const hasScore = ["q", "e", "t"].some((key) => value?.[key] != null && value[key] !== "");
            const remarks = String(value?.remarks ?? "").trim();
            const touched = selfDirty.current.has(Number(indicatorId));

            if (!hasScore && remarks === "" && !touched) return [];

            return [{
                indicator_id: Number(indicatorId),
                q: value?.q ?? null,
                e: value?.e ?? null,
                t: value?.t ?? null,
                remarks: remarks || null,
            }];
        });
    const editingOwnScores = !scoreEdit && (canSubmitForRating || opcrRatingOn);
    const selfScoreEdit = editingOwnScores
        ? {
            canEdit: true,
            scores: selfScores,
            choices: scoreOptions(user),
            onChange: (indicatorId, field, value) => {
                selfDirty.current.add(Number(indicatorId));
                setSelfScores((prev) => ({
                    ...prev,
                    [indicatorId]: { ...(prev[indicatorId] ?? {}), [field]: value },
                }));
            },
        }
        : null;
    const sheetScoreEdit = scoreEdit ?? selfScoreEdit;
    const sheetLegend = scoreLegend ?? (
        opcrRatingOn
            ? <OpcrRateLegend user={user} />
            : selfScoreEdit
              ? <SelfRateLegend user={user} />
              : null
    );
    const startOpcrRating = () => {
        const narrativeGaps = accountableNarrativeGaps(form);

        if (narrativeGaps.length) {
            setWriteUpIssues(narrativeGaps);

            return;
        }

        const reasons = [];
        const periodNote = ratingPeriodWarning(form.school_year);
        const gaps = unfinishedOpcrWork(form);

        if (periodNote) reasons.push(periodNote);

        if (gaps.length) {
            const listed = gaps.slice(0, 5).join("; ");
            const extra = gaps.length > 5 ? `, and ${gaps.length - 5} more` : "";
            reasons.push(`Not all work is finished yet: ${listed}${extra}.`);
        }

        if (reasons.length === 0) {
            setOpcrRatingOn(true);

            return;
        }

        Modal.confirm({
            title: "Start rating?",
            content: (
                <div>
                    {reasons.map((reason) => (
                        <p key={reason} style={{ marginBottom: 8 }}>{reason}</p>
                    ))}
                    <p style={{ marginBottom: 0 }}>You can still proceed.</p>
                </div>
            ),
            okText: "Proceed",
            cancelText: "Cancel",
            onOk: () => setOpcrRatingOn(true),
        });
    };
    const finalizeOpcrRating = async () => {
        const narrativeGaps = accountableNarrativeGaps(form);

        if (narrativeGaps.length) {
            setWriteUpIssues(narrativeGaps);

            return;
        }

        const dimensions = ratingRules(user).dimensions.map((dimension) => dimension.key);
        const targets = (form.outputs ?? []).flatMap((output) =>
            (output.indicators ?? []).filter((line) => {
                if (!countsTowardRating(line)) return false;

                return !line.rating_period_id || !activePeriodId || line.rating_period_id === activePeriodId;
            })
        );
        const missing = targets.filter((line) => {
            const value = selfScores[line.id] ?? selfScores[String(line.id)];

            return dimensions.some((key) => value?.[key] == null || value[key] === "");
        });

        if (targets.length === 0 || missing.length > 0) {
            Modal.warning({
                title: "Rating is not finished",
                content: targets.length === 0
                    ? "There are no targets to rate."
                    : `${missing.length} target${missing.length === 1 ? "" : "s"} still ${missing.length === 1 ? "has" : "have"} no rating. Rate every target before finalizing.`,
            });

            return;
        }

        if (!activePeriodId) {
            Modal.warning({
                title: "Rating is not finished",
                content: "There is no rating period for this school year yet.",
            });

            return;
        }

        const ratings = targets.map((line) => {
            const value = selfScores[line.id] ?? selfScores[String(line.id)] ?? {};

            return {
                indicator_id: line.id,
                q: value.q ?? null,
                e: value.e ?? null,
                t: value.t ?? null,
                remarks: String(value.remarks ?? "").trim() || null,
            };
        });

        setFinalizing(true);

        try {
            await saveSelfRating.mutateAsync(ratings);
            await api.post(`pcr-forms/${form.id}/finalize-rating`, {
                rating_period_id: activePeriodId,
            });
            message.success("Rating finalized.");
            setOpcrRatingOn(false);
            refresh();
            queryClient.invalidateQueries({ queryKey: ["review-queue"] });
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
        } catch {
            // The request interceptor already reports the server message.
        } finally {
            setFinalizing(false);
        }
    };
    // Score columns appear once rating has started, or while the owner is
    // optionally scoring their own IPCR before they submit it.
    const showScores = Boolean(sheetScoreEdit) || (
        isOpcr
            ? ["qa_rating", "rated", "final"]
            : ["head_review", "dean_review", "director_review", "vp_review", "qa_rating", "rated", "final"]
    ).includes(form.status);
    const canDecideCommitments =
        !isOpcr &&
        form.status === "commitment_review" &&
        samePerson(form.commitment_approver?.id, user.id);
    // Plain value, not a hook: this sits after the loading return.
    const vpLinkByTarget = (() => {
        if (!canDecideCommitments) return null;

        const targets = form.assigned_targets ?? [];
        const hasOffice = targets.some((target) => target.source === "opcr");
        const fromVp = targets.filter((target) => target.source && target.source !== "opcr");

        if (!hasOffice || fromVp.length === 0) return null;

        return Object.fromEntries(
            fromVp.map((target) => [
                String(target.id),
                target.assigned_by_name ? `From ${target.assigned_by_name}` : "VP commitment",
            ])
        );
    })();
    // Step progress is for the account owner only — reviewers keep the status tag.
    const seesSteps = isOwner;
    const approverLabel = form.commitment_approver?.label || "approval";
    const canReturnOpcr = isOpcr && canApproveOpcr && form.status === "qa_approval";

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

    const savedSummary = form.summaries?.find((s) => s.rating_period_id === activePeriodId);
    const summary = liveSummary ?? savedSummary;
    const reviewSteps = form.review_steps ?? [];
    const ratingChain = reviewSteps.length
        ? reviewSteps.map((step) => step.status)
        : ["head_review", "dean_review", "director_review", "vp_review", "qa_rating"];
    // Commitment approval and rating are separate sequences. Rating opens
    // once commitments are approved, and its first step is the draft the
    // writer still has to submit for review. A return clears that sequence:
    // the writer sees only Returned until they send the form on again.
    const chain = form.status === "returned"
        ? ["returned"]
        : isOpcr
          ? (["qa_rating", "rated"].includes(form.status) ? OPCR_SENT_CHAIN : OPCR_CHAIN)
          : commitmentsApproved
            ? ["commitments_approved", ...ratingChain, "rated", "final"]
            : ["draft", "commitment_review"];

    // A stage with nobody to fill it is passed over. The sequence shows only
    // the people who actually review this form.
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
    // the whole sequence, minus any stage this office skips.
    const shownChain = (isOwner || !chain.includes(form.status) ? chain : [form.status])
        .filter((status) => status === form.status || !skippedStages.includes(status));
    const stepIndex = Math.max(shownChain.indexOf(form.status), 0);
    const returnMarks = displayReturnMarks(form.return_marks, form.status);

    const formBar = (className = "pms-form-bar") => (
        <div className={className}>
            <Tag
                className={phoneChain && seesSteps ? "pms-form-status" : undefined}
                color={metaFor(form.status).color}
            >
                {metaFor(form.status).label}
            </Tag>
            <Space wrap={false} className="pms-form-tools" size={8}>
                <Badge count={(form.comments_count ?? 0) || 0} size="small" offset={[-4, 4]}>
                    <Button icon={<MessageOutlined />} onClick={() => setPanelOpen(true)}>
                        Feedback &amp; history
                    </Button>
                </Badge>
                {yearSelect}
                <ExportMenu
                    excelBusy={excelBusy}
                    onPrint={() => setPdfOpen(true)}
                    onExcel={exportExcel}
                />
                {canSendCommitments && (
                    <Button
                        type="primary"
                        loading={move.isPending}
                        disabled={!form.commitment_approver || Boolean(form.commitment_wait)}
                        onClick={() => move.mutate({ status: "commitment_review" })}
                    >
                        <span className="pms-btn-long">
                            {form.commitment_approver ? `Send to ${approverLabel}` : "Send"}
                        </span>
                        <span className="pms-btn-short">Send</span>
                    </Button>
                )}
                {selfScoreEdit && (
                    <Button
                        loading={saveSelfRating.isPending}
                        disabled={selfRateRows().length === 0}
                        onClick={() => {
                            saveSelfRating.mutate(selfRateRows(), {
                                onSuccess: () => message.success("Self-rating saved."),
                            });
                        }}
                    >
                        <span className="pms-btn-long">Save self-rating</span>
                        <span className="pms-btn-short">Save rating</span>
                    </Button>
                )}
                {canSubmitForRating && (
                    <Button
                        type="primary"
                        loading={move.isPending || saveSelfRating.isPending}
                        onClick={async () => {
                            const gaps = writeUpGaps(form);

                            if (gaps.length) {
                                setWriteUpIssues(gaps);

                                return;
                            }

                            const ratings = selfScoreEdit ? selfRateRows() : [];

                            if (ratings.length) {
                                try {
                                    await saveSelfRating.mutateAsync(ratings);
                                } catch {
                                    return;
                                }
                            }

                            move.mutate({ status: "head_review" });
                        }}
                    >
                        {isRater ? "Send to the President" : "Submit for review"}
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
                                const { data } = await api.get(`pcr-forms/${form.id}/readiness`);

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
                        <span className="pms-btn-short">{opcrAction.short ?? opcrAction.label}</span>
                    </Button>
                )}
            </Space>
            <Space wrap={false} className="pms-form-actions" size={8}>
                {typeof toolbarExtra === "function" ? toolbarExtra() : toolbarExtra}
                {canDecideCommitments && (
                    <>
                        <Button danger onClick={() => setReturnModal(true)}>
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
                    <Button danger onClick={() => setReturnModal(true)}>
                        Return
                    </Button>
                )}
                {replaceUi?.showReplace && (
                    <Button onClick={() => replaceRun.current("start")}>Replace</Button>
                )}
                {replaceUi?.editing && (
                    <>
                        <Typography.Text type="secondary">
                            Edit the cells you want. Highlighted ones will be sent.
                        </Typography.Text>
                        <Button onClick={() => replaceRun.current("cancel")}>Cancel</Button>
                        <Button
                            type="primary"
                            disabled={!replaceUi.changedCount}
                            loading={replaceUi.proposePending}
                            onClick={() => replaceRun.current("send")}
                        >
                            Send to QA{replaceUi.changedCount ? ` (${replaceUi.changedCount})` : ""}
                        </Button>
                    </>
                )}
                {replaceUi?.showDecide && (
                    <>
                        <Button
                            type="primary"
                            loading={replaceUi.decidePending}
                            onClick={() => replaceRun.current("approve")}
                        >
                            Approve changes
                        </Button>
                        <Button
                            loading={replaceUi.decidePending}
                            onClick={() => replaceRun.current("reject")}
                        >
                            Send back
                        </Button>
                    </>
                )}
                {restartUi?.showSend && (
                    <>
                        <Button
                            type="primary"
                            disabled={!restartUi.ready}
                            loading={restartUi.sendPending}
                            onClick={() => restartRun.current("send")}
                        >
                            Send for approval{restartUi.count ? ` (${restartUi.count})` : ""}
                        </Button>
                        {!restartUi.ready && (
                            <Typography.Text type="secondary">
                                Write every new commitment first.
                            </Typography.Text>
                        )}
                    </>
                )}
                {restartUi?.showDecide && (
                    <>
                        <Button
                            type="primary"
                            loading={restartUi.decidePending}
                            onClick={() => restartRun.current("approve")}
                        >
                            Approve ({restartUi.waiting})
                        </Button>
                        <Button
                            loading={restartUi.decidePending}
                            onClick={() => restartRun.current("reject")}
                        >
                            Send back
                        </Button>
                    </>
                )}
                {canStartOpcrRating && !opcrRatingOn && (
                    <Button type="primary" onClick={startOpcrRating}>
                        <span className="pms-btn-long">Start rating</span>
                        <span className="pms-btn-short">Rate</span>
                    </Button>
                )}
                {opcrRatingOn && (
                    <Button type="primary" loading={finalizing} onClick={finalizeOpcrRating}>
                        <span className="pms-btn-long">Finalize rating</span>
                        <span className="pms-btn-short">Finalize</span>
                    </Button>
                )}
            </Space>
        </div>
    );

    return (
        <>
            <PageHeader
                title={hideTitle ? null : `${form.type.toUpperCase()} — ${sheetName(form)}`}
                subtitle={
                    hideTitle ? null : (
                    <>
                        <span className="pms-desktop-only">
                            {(filters
                                ? [form.org_unit?.name]
                                : [
                                      form.school_year?.label,
                                      form.org_unit?.name,
                                      formPeriodId &&
                                          (periods.find((p) => p.id === formPeriodId)?.label ??
                                              form.rating_period?.label),
                                  ]
                            )
                                .filter(Boolean)
                                .join(" · ")}
                        </span>
                        <span className="pms-mobile-only pms-form-sub">
                            {[form.school_year?.label, form.org_unit?.name].filter(Boolean).join(" · ")}
                        </span>
                    </>
                    )
                }
                extra={
                    <div className="pms-form-head">
                        {filters}
                        {formBar()}
                    </div>
                }
            />

            {periodBar}

            {user?.role === "admin" && (
                <FormSupportDesk
                    form={form}
                    onDone={() => {
                        refresh();
                        queryClient.invalidateQueries({ queryKey: ["review-queue"] });
                        queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
                        queryClient.invalidateQueries({ queryKey: ["my-forms"] });
                    }}
                />
            )}

            <FormStatusAlerts
                form={form}
                isOwner={isOwner}
                isOpcr={isOpcr}
                periodLocked={periodLocked}
                hideLockAlert={hideLockAlert}
                viewedPeriod={viewedPeriod}
                planningOpen={planningOpen}
                approverLabel={approverLabel}
                returnMarks={returnMarks}
                returnedFromRating={returnedFromRating}
            />

            {seesSteps && phoneChain && (
            <Card className="pms-chain-card">
                <p className="pms-chain-now">
                    <span
                        className="pms-chain-now-icon"
                        style={{
                            color: (HISTORY_META[shownChain[stepIndex] ?? form.status] ?? HISTORY_META.default).color,
                        }}
                    >
                        {(HISTORY_META[shownChain[stepIndex] ?? form.status] ?? HISTORY_META.default).icon}
                    </span>
                    <strong>
                        {metaFor(shownChain[stepIndex] ?? form.status).label}
                        {(shownChain[stepIndex] ?? form.status) === "final" ? " - Closed" : ""}
                    </strong>
                    {whoIsAt(shownChain[stepIndex] ?? form.status) && (
                        <span className="pms-chain-now-name">{whoIsAt(shownChain[stepIndex] ?? form.status)}</span>
                    )}
                </p>
            </Card>
            )}

            {seesSteps && !phoneChain && (
            <Card className="pms-chain-card">
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
                        const done = index < stepIndex;
                        const here = index === stepIndex;
                        const shortLabel =
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
                            }[status] ?? metaFor(status).label.replace(/^With /i, "");

                        return {
                            title: phoneChain ? (
                                shortLabel
                            ) : (
                                <>
                                    <span className="pms-chain-long">
                                        {reviewDraft ? "Draft" : metaFor(status).label}
                                    </span>
                                    <span className="pms-chain-short">{shortLabel}</span>
                                </>
                            ),
                            // Naming the person beats "waiting for the program
                            // head" when the form already knows who that is.
                            // The phone keeps the straight line and the short name only.
                            description: phoneChain
                                ? undefined
                                : here
                                  ? reviewDraft
                                    ? "With the writer"
                                    : whoIsAt(status) ?? metaFor(status).who
                                  : undefined,
                            icon: (
                                <span
                                    className={
                                        "pms-chain-dot" +
                                        (here ? " is-here" : "") +
                                        (done ? " is-done" : "")
                                    }
                                >
                                    {done ? <CheckOutlined /> : meta.icon}
                                </span>
                            ),
                        };
                    })}
                />
            </Card>
            )}

            <Row gutter={16}>
                <Col xs={24} lg={24}>
                    <Card
                        title="Commitments"
                        style={{ marginBottom: 16 }}
                        extra={formBar("pms-form-bar pms-form-bar-sheet")}
                    >
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
                                    <Select
                                        style={{ minWidth: 180 }}
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
                                {(isOpcr || canEditCommitment || form.outputs.length > 0) && (
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
                                form={form}
                                targets={form.assigned_targets}
                                notedIds={focusTargetIds}
                                canWrite={canEditCommitment}
                                periodId={activePeriodId}
                                onArrive={showLine}
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
                                showProgress={!hideProgress}
                                scoreEdit={sheetScoreEdit}
                                scoreLegend={sheetLegend}
                                showAccomplishments={showAccomplishments}
                                summary={summary}
                                focusLineId={focusLineId}
                                focusOutputId={focusOutputId}
                                openFocusedLine={openFocusedLine}
                                returnMarks={returnMarks}
                                onExpand={() => setSheetFull(true)}
                                onReplaceUi={onReplaceUi}
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
                                showScores={showScores}
                                showProgress={!hideProgress}
                                scoreEdit={sheetScoreEdit}
                                scoreLegend={sheetLegend}
                                showAccomplishments={showAccomplishments}
                                opcrTargets={opcrTargets}
                                vpLinkByTarget={vpLinkByTarget}
                                summary={summary}
                                focusLineId={focusLineId}
                                focusOutputId={focusOutputId}
                                openFocusedLine={openFocusedLine}
                                returnMarks={returnMarks}
                                assignedOnly={user?.role === "employee"}
                                arriveLineId={sheetFull ? null : arrived?.id}
                                arriveOutputId={sheetFull ? null : arrived?.outputId}
                                arriveOpenOutput={sheetFull ? false : Boolean(arrived?.openOutput)}
                                arriveTick={sheetFull ? null : arrived?.tick}
                                onExpand={() => setSheetFull(true)}
                                onRestartUi={onRestartUi}
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
                        {!hideTitle && (
                            <span>
                                {form.type.toUpperCase()} — {sheetName(form)}
                            </span>
                        )}
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
                                <Select
                                    size="small"
                                    style={{ minWidth: 160 }}
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
                                showProgress={!hideProgress}
                        scoreEdit={sheetScoreEdit}
                        scoreLegend={sheetLegend}
                        showAccomplishments={showAccomplishments}
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
                        showScores={showScores}
                                showProgress={!hideProgress}
                        scoreEdit={sheetScoreEdit}
                        scoreLegend={sheetLegend}
                        showAccomplishments={showAccomplishments}
                        opcrTargets={opcrTargets}
                        vpLinkByTarget={vpLinkByTarget}
                        summary={summary}
                        returnMarks={returnMarks}
                        assignedOnly={user?.role === "employee"}
                        arriveLineId={sheetFull ? arrived?.id : null}
                        arriveOutputId={sheetFull ? arrived?.outputId : null}
                        arriveOpenOutput={sheetFull ? Boolean(arrived?.openOutput) : false}
                        arriveTick={sheetFull ? arrived?.tick : null}
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
                    This form is not submitted yet. Finish the items below first.
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
                title={`${form.type.toUpperCase()} — feedback and history`}
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

                    <Card title="Feedback" className="pms-rate-feedback" style={{ marginBottom: 16 }}>
                        <CommentThread formId={form.id} />
                    </Card>

                    <Card title="History" styles={{ body: { paddingTop: 12 } }}>
                        <FormHistory formId={form.id} />
                    </Card>
            </Drawer>

            <ReturnPlacesModal
                open={returnModal}
                form={form}
                confirming={move.isPending}
                onCancel={() => setReturnModal(false)}
                onSubmit={(fixes) => move.mutate({ status: "returned", fixes })}
            />
        </>
    );
}
