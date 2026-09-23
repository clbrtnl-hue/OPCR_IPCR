import React, { useMemo, useState } from "react";
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
    Form,
    Input,
    Modal,
    Popconfirm,
    Row,
    Segmented,
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
    EditOutlined,
    ExpandOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    LockOutlined,
    MessageOutlined,
    MinusOutlined,
    NotificationOutlined,
    PlusOutlined,
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
import { useParams } from "react-router-dom";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import api from "~/utils/api";
import { downloadFile } from "~/utils/download";
import { ADJECTIVAL_COLORS, NARRATIVE_MAX, SECTION_LABELS, STATUS_META } from "~/utils/constants";
import { useAuth } from "~/hooks/useAuth";
import PageHeader from "~/components/PageHeader";
import { toPlainText } from "~/components/RichTextView";

dayjs.extend(relativeTime);
import CascadeModal from "~/components/CascadeModal";
import TemplatePicker from "~/components/TemplatePicker";
import OpcrSheet from "~/components/OpcrSheet";
import IpcrSheet from "~/components/IpcrSheet";
import RichText from "~/components/RichText";
import PdfPreview from "~/components/PdfPreview";
import CopyFromYear from "~/components/CopyFromYear";
import CommentThread from "~/components/CommentThread";
import FormHistory from "~/components/FormHistory";

/**
 * What each step in a form's life looks like. The icon carries the meaning at a
 * glance — a return is not the same kind of event as an approval, and a stage
 * nobody filled is quieter still.
 */
const HISTORY_META = {
    draft:       { icon: <EditOutlined />,           color: "#8c8c8c" },
    head_review: { icon: <SendOutlined />,           color: "#1677ff" },
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


const WHOLE_YEAR_LABEL = "January to December";

const IPCR_CHAIN = ["draft", "head_review", "vp_review", "qa_rating", "rated", "final"];
// An OPCR is planned and published first; it is rated later on the same document.
const OPCR_CHAIN = ["draft", "qa_approval", "approved", "published", "qa_rating", "rated", "final"];

export default function FormEditorPage({ formId = null }) {
    const { id: routeId } = useParams();
    // Normalised: children invalidate ["pcr-form", String(...)], and a numeric
    // id from the prop would silently miss that cache key.
    const id = String(formId ?? routeId);
    const { user, can } = useAuth();
    const queryClient = useQueryClient();
    const [outputForm] = Form.useForm();
    const [outputModal, setOutputModal] = useState(false);
    const [periodId, setPeriodId] = useState(null);
    const [returnModal, setReturnModal] = useState(false);
    const [panelOpen, setPanelOpen] = useState(false);
    const [pdfOpen, setPdfOpen] = useState(false);
    const [excelBusy, setExcelBusy] = useState(false);
    const [sheetFull, setSheetFull] = useState(false);
    const [checking, setChecking] = useState(false);
    const [issues, setIssues] = useState(null);
    const [copyOpen, setCopyOpen] = useState(false);
    const [cascadeOpen, setCascadeOpen] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);
    const [returnNote, setReturnNote] = useState("");

    const { data: opcrTargets = [] } = useQuery({
        queryKey: ["opcr-targets", id],
        queryFn: () => api.get(`pcr-forms/${id}/opcr-targets`).then((r) => r.data),
        enabled: Boolean(id),
    });

    const { data: form, isLoading } = useQuery({
        queryKey: ["pcr-form", id],
        queryFn: () => api.get(`pcr-forms/${id}`).then((r) => r.data),
    });

    const periods = form?.school_year?.periods ?? [];
    // A period-pinned IPCR covers exactly one review period; the OPCR (and
    // legacy year-wide IPCRs) still switch between the year's two periods.
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const exportExcel = async () => {
        if (!form) return;

        setExcelBusy(true);

        try {
            await downloadFile(
                `pcr-forms/${form.id}/xlsx?rating_period_id=${activePeriodId ?? ""}`,
                `${form.type.toUpperCase()} - ${form.owner?.name ?? form.org_unit?.name}.xlsx`,
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            );
        } catch {
            message.error("The Excel file could not be prepared.");
        } finally {
            setExcelBusy(false);
        }
    };

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", id] });

    const addOutput = useMutation({
        mutationFn: (values) => api.post("pcr-outputs", { ...values, form_id: form.id }),
        onSuccess: () => {
            message.success("Output added.");
            setOutputModal(false);
            outputForm.resetFields();
            refresh();
        },
    });

    const move = useMutation({
        mutationFn: ({ status, note }) => api.post(`pcr-forms/${form.id}/status`, { status, note }),
        onSuccess: () => {
            message.success("Form updated.");
            setReturnModal(false);
            setReturnNote("");
            refresh();
        },
    });

    const isOwner = useMemo(() => {
        if (!form || !user) return false;
        // Mirrors PcrWorkflow::owns() — admin bypasses, as everywhere else.
        if (user.role === "admin") return true;
        if (form.type === "ipcr") return form.user_id === user.id;
        if (user.role === "president") return true;
        return form.org_unit_id === user.org_unit_id && user.role === "program_head";
    }, [form, user, can]);

    if (isLoading || !form) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    const canEditCommitment = isOwner && ["draft", "returned"].includes(form.status);
    const canRecordProgress = isOwner && !["rated", "final"].includes(form.status);
    // Handing part of a commitment to someone else is not editing its wording,
    // so it stays available once the form is published and in flight.
    // Offer only what the server would accept: the organization decides which
    // roles hand work out, and terminal roles deliver their own commitments.
    const canAssignHeadings =
        isOwner && !["rated", "final"].includes(form.status) && Boolean(user.capabilities?.assign_outputs);
    const canAssign =
        isOwner && !["rated", "final"].includes(form.status) && Boolean(user.capabilities?.assign_indicators);
    const isOpcr = form.type === "opcr";

    const isAdmin = user.role === "admin";

    // Authority is being named on this form, not holding a role — a VP's own
    // IPCR is reviewed by the president, and one person may hold two posts.
    const isMyReviewStep =
        !isOpcr &&
        ((form.status === "head_review" && form.head_reviewer_id === user.id) ||
            (form.status === "vp_review" && form.vp_reviewer_id === user.id) ||
            (isAdmin && ["head_review", "vp_review"].includes(form.status)));

    const nextStatus = form.status === "head_review" ? "vp_review" : "qa_rating";

    // The OPCR's planning lane: admin sends the targets to QA, QA approves them,
    // admin publishes, and only then can anyone tie an IPCR line to them.
    const drivesOpcr = isAdmin || user.role === "president";

    const opcrAction = !isOpcr
        ? null
        : drivesOpcr && ["draft", "returned"].includes(form.status)
          ? { status: "qa_approval", label: "Send to QA for approval" }
          : user.role === "qa" && form.status === "qa_approval"
            ? { status: "approved", label: "Approve targets" }
            : drivesOpcr && form.status === "approved"
              ? { status: "published", label: "Publish" }
              : null;

    const canUnpublish = isOpcr && drivesOpcr && form.status === "published";
    const canClose = form.status === "rated" && can("qa", "president");
    const canReturnOpcr = isOpcr && user.role === "qa" && form.status === "qa_approval";

    const sections = ["strategic", "core", "support"].filter(
        (section) => !(isOpcr === false && section === "strategic")
    );

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
    const chain = isOpcr ? OPCR_CHAIN : IPCR_CHAIN;

    // A stage with nobody to fill it is passed over rather than stranding the
    // form, so the tracker should say so instead of implying a wait.
    const skippedStages = isOpcr
        ? []
        : [
              !form.head_reviewer_id && "head_review",
              !form.vp_reviewer_id && "vp_review",
          ].filter(Boolean);

    const whoIsAt = (status) => {
        const person =
            status === "head_review"
                ? form.head_reviewer
                : status === "vp_review"
                  ? form.vp_reviewer
                  : null;

        return person?.name ?? null;
    };
    const stepIndex = Math.max(chain.indexOf(form.status), 0);

    return (
        <>
            <PageHeader
                title={`${form.type.toUpperCase()} — ${form.owner?.name ?? form.org_unit?.name}`}
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
                    <Space wrap>
                        <Tag color={STATUS_META[form.status].color}>{STATUS_META[form.status].label}</Tag>
                        <Badge count={(form.comments_count ?? 0) || 0} size="small" offset={[-4, 4]}>
                            <Button
                                icon={<MessageOutlined />}
                                onClick={() => setPanelOpen(true)}
                            >
                                Remarks &amp; history
                            </Button>
                        </Badge>
                        <Button icon={<PrinterOutlined />} onClick={() => setPdfOpen(true)}>
                            Print
                        </Button>
                        <Button icon={<FileExcelOutlined />} loading={excelBusy} onClick={exportExcel}>
                            Excel
                        </Button>
                        {canEditCommitment && !isOpcr && (
                            <Button
                                type="primary"
                                loading={move.isPending}
                                onClick={() => move.mutate({ status: "head_review" })}
                            >
                                Submit for review
                            </Button>
                        )}
                        {canReturnOpcr && (
                            <Button danger onClick={() => setReturnModal(true)}>
                                Return
                            </Button>
                        )}
                        {opcrAction && (
                            <Button
                                type="primary"
                                loading={move.isPending || checking}
                                onClick={async () => {
                                    // Only the hand-over to QA is worth checking;
                                    // approving and publishing come after.
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
                                {opcrAction.label}
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
                        {isMyReviewStep && (
                            <>
                                <Button danger onClick={() => setReturnModal(true)}>
                                    Return
                                </Button>
                                <Button
                                    type="primary"
                                    loading={move.isPending}
                                    onClick={() => move.mutate({ status: nextStatus })}
                                >
                                    Endorse
                                </Button>
                            </>
                        )}
                    </Space>
                }
            />

            {form.status === "returned" && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="This form was sent back to you"
                    description="Read the remarks below, make the corrections, then submit it again."
                />
            )}

            <Card style={{ marginBottom: 16 }}>
                <Steps
                    size="small"
                    className="pms-chain"
                    labelPlacement="vertical"
                    current={stepIndex}
                    status={form.status === "returned" ? "error" : "process"}
                    items={chain.map((status, index) => {
                        const meta = HISTORY_META[status] ?? HISTORY_META.default;
                        const skipped = skippedStages.includes(status);
                        const done = index < stepIndex;
                        const here = index === stepIndex;

                        return {
                            title: STATUS_META[status].label,
                            // Naming the person beats "waiting for the program
                            // head" when the form already knows who that is.
                            description: skipped
                                ? "Skipped — nobody fills this stage"
                                : whoIsAt(status) ?? STATUS_META[status].who,
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

            <Row gutter={16}>
                <Col xs={24} lg={24}>
                    <Card
                        title="Commitments"
                        style={{ marginBottom: 16 }}
                        extra={
                            <Space>
                                {isOpcr ? (
                                    <Tag color="blue">{WHOLE_YEAR_LABEL}</Tag>
                                ) : formPeriodId ? (
                                    <Tag color="blue">
                                        {periods.find((p) => p.id === formPeriodId)?.label ??
                                            form.rating_period?.label}
                                    </Tag>
                                ) : (
                                    <Segmented
                                        value={activePeriodId}
                                        onChange={setPeriodId}
                                        options={periods.map((p) => ({
                                            value: p.id,
                                            label: p.is_active ? `${p.label} ★` : p.label,
                                        }))}
                                    />
                                )}
                                {canEditCommitment && !isOpcr && (
                                    <Button
                                        type="primary"
                                        size="small"
                                        icon={<PlusOutlined />}
                                        onClick={() => setOutputModal(true)}
                                    >
                                        Add output
                                    </Button>
                                )}
                                {isOpcr && canEditCommitment && form.outputs.length === 0 && (
                                    <Button size="small" onClick={() => setCopyOpen(true)}>
                                        Start from another year
                                    </Button>
                                )}
                                {canEditCommitment && (
                                    <Button
                                        size="small"
                                        icon={<ProfileOutlined />}
                                        onClick={() => setTemplateOpen(true)}
                                    >
                                        Insert template
                                    </Button>
                                )}
                                {canAssign && form.outputs.length > 0 && (
                                    <Button
                                        size="small"
                                        icon={<ShareAltOutlined />}
                                        onClick={() => setCascadeOpen(true)}
                                    >
                                        Cascade to people
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
                            </Space>
                        }
                    >
                        {!isOpcr && (form.assigned_targets ?? []).length > 0 && (
                            <Alert
                                type="info"
                                showIcon
                                style={{ marginBottom: 16 }}
                                message="Office targets assigned to you"
                                description={
                                    <ul className="pms-assigned-targets">
                                        {(form.assigned_targets ?? []).map((target) => (
                                            <li key={target.id}>
                                                <strong>{target.output_title}</strong>
                                                {" — "}
                                                {toPlainText(target.description)}
                                            </li>
                                        ))}
                                    </ul>
                                }
                            />
                        )}
                        {isOpcr ? (
                            <OpcrSheet
                                form={form}
                                periodId={activePeriodId}
                                canEdit={canEditCommitment}
                                canAssign={canAssignHeadings || canAssign}
                                canRecordProgress={canRecordProgress}
                                summary={summary}
                            />
                        ) : form.outputs.length === 0 ? (
                            <Empty description="No commitments yet. Add an MFO/PPA and write your own success indicators, then link each one to an assigned office target." />
                        ) : (
                            <IpcrSheet
                                form={form}
                                periodId={activePeriodId}
                                canEdit={canEditCommitment}
                                canRecordProgress={canRecordProgress}
                                canAssign={canAssign}
                                canAssignHeadings={canAssignHeadings}
                                opcrTargets={opcrTargets}
                                summary={summary}
                                onAddOutput={(section) => {
                                    outputForm.setFieldsValue({ section });
                                    setOutputModal(true);
                                }}
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
                            {form.type.toUpperCase()} — {form.owner?.name ?? form.org_unit?.name}
                        </span>
                        <Tag color={STATUS_META[form.status].color}>
                            {STATUS_META[form.status].label}
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
                    <Space>
                        {isOpcr ? (
                            <Tag color="blue">{WHOLE_YEAR_LABEL}</Tag>
                        ) : formPeriodId ? (
                            <Tag color="blue">
                                {periods.find((p) => p.id === formPeriodId)?.label ??
                                    form.rating_period?.label}
                            </Tag>
                        ) : (
                            <Segmented
                                size="small"
                                value={activePeriodId}
                                onChange={setPeriodId}
                                options={periods.map((p) => ({
                                    value: p.id,
                                    label: p.is_active ? `${p.label} ★` : p.label,
                                }))}
                            />
                        )}
                        <Button icon={<PrinterOutlined />} onClick={() => setPdfOpen(true)}>
                            Print
                        </Button>
                        <Button icon={<FileExcelOutlined />} loading={excelBusy} onClick={exportExcel}>
                            Excel
                        </Button>
                        <Button onClick={() => setSheetFull(false)}>Close</Button>
                    </Space>
                }
            >
                {isOpcr ? (
                    <OpcrSheet
                        form={form}
                        periodId={activePeriodId}
                        canEdit={canEditCommitment}
                        canAssign={canAssignHeadings || canAssign}
                        canRecordProgress={canRecordProgress}
                        summary={summary}
                    />
                ) : (
                    <IpcrSheet
                        form={form}
                        periodId={activePeriodId}
                        canEdit={canEditCommitment}
                        canRecordProgress={canRecordProgress}
                        canAssign={canAssign}
                        canAssignHeadings={canAssignHeadings}
                        opcrTargets={opcrTargets}
                        summary={summary}
                        onAddOutput={(section) => {
                            outputForm.setFieldsValue({ section });
                            setOutputModal(true);
                        }}
                    />
                )}
            </Drawer>

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
                filename={`${form.type.toUpperCase()} - ${form.owner?.name ?? form.org_unit?.name}.pdf`}
                title={`${form.type.toUpperCase()} — ${form.owner?.name ?? form.org_unit?.name}`}
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

            <Modal
                title="Add output (MFO/PPA)"
                open={outputModal}
                onCancel={() => setOutputModal(false)}
                onOk={() => outputForm.submit()}
                confirmLoading={addOutput.isPending}
            >
                <Form
                    form={outputForm}
                    layout="vertical"
                    requiredMark={false}
                    initialValues={{ section: "core" }}
                    onFinish={(v) => addOutput.mutate(v)}
                >
                    <Form.Item name="section" label="Section" rules={[{ required: true }]}>
                        <Select
                            options={sections.map((value) => ({ value, label: SECTION_LABELS[value] }))}
                        />
                    </Form.Item>
                    <Form.Item noStyle shouldUpdate={(a, b) => a.section !== b.section}>
                        {({ getFieldValue }) => {
                            const section = getFieldValue("section");

                            return (
                                <Form.Item
                                    name="title"
                                    label={isOpcr ? "Output" : "MFO/PPA"}
                                    rules={[
                                        { required: true, message: "Name the MFO/PPA." },
                                        { max: NARRATIVE_MAX, message: `Keep this to ${NARRATIVE_MAX} characters.` },
                                    ]}
                                    extra={
                                        isOpcr
                                            ? "The MFO/PPA column — for example “Research” or “1.1 Entrance Exam”."
                                            : section === "support"
                                              ? "Support Functions are your own — for example “Submission of DTR”."
                                              : "Type your own MFO/PPA. Success indicators under it can be picked from the college OPCR."
                                    }
                                >
                                    <Input.TextArea
                                        autoSize={{ minRows: 2, maxRows: 6 }}
                                        maxLength={NARRATIVE_MAX}
                                        showCount
                                        placeholder={
                                            isOpcr ? "Research" : section === "support" ? "Submission of DTR" : "Instruction"
                                        }
                                    />
                                </Form.Item>
                            );
                        }}
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Return this form"
                open={returnModal}
                onCancel={() => setReturnModal(false)}
                okText="Return"
                okButtonProps={{ danger: true, disabled: !returnNote.trim() }}
                confirmLoading={move.isPending}
                onOk={() => move.mutate({ status: "returned", note: returnNote })}
            >
                <Typography.Paragraph>
                    Say what needs fixing. The writer sees this remark and can correct the form and resubmit.
                </Typography.Paragraph>
                <RichText
                    rows={4}
                    value={returnNote}
                    onChange={setReturnNote}
                    placeholder="The evidence attached does not match the stated target."
                />
            </Modal>
        </>
    );
}
