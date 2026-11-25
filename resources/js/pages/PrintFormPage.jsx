import React, { useMemo, useState } from "react";
import { Button, Select, Space, Spin, message } from "antd";
import { FileExcelOutlined, PrinterOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { useParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { downloadFile } from "~/utils/download";
import { RATING_LEGEND, SECTION_LABELS } from "~/utils/constants";
import { useOrganization } from "~/hooks/useOrganization";
import RichTextView from "~/components/RichTextView";

function accountableFor(indicator) {
    const assigned = (indicator?.assignments ?? [])
        .map((row) => row.user?.name)
        .filter(Boolean);

    if (assigned.length > 0) return assigned.join(" / ");

    const delivered = (indicator?.children ?? [])
        .map((child) => child.output?.form?.owner?.name)
        .filter(Boolean);

    return delivered.length > 0 ? delivered.join(" / ") : (indicator?.accountable ?? "");
}

function score(value) {
    return value != null && value !== "" ? Number(value).toFixed(2) : "";
}

function longDate(value) {
    return value ? dayjs(value).format("D MMMM YYYY") : "";
}

const IPCR_BANDS = {
    strategic: "STRATEGIC PRIORITY",
    core: "CORE FUNCTIONS:",
    support: "SUPPORT FUNCTIONS:",
};

const IPCR_COLS = [
    "15.13%", "2.89%", "2.89%", "14.76%", "2.89%", "2.89%", "9.01%",
    "2.89%", "2.89%", "2.89%", "2.89%", "3.04%", "34.92%",
];

function onPaper(indicator, periodId) {
    if (!indicator) return false;
    if (indicator.rating_period_id && Number(indicator.rating_period_id) !== Number(periodId)) return false;
    if (indicator.closed_at) return false;
    if (indicator.replacement_status === "pending") return false;
    if (indicator.needs_commitment_approval && !indicator.line_approved_at) return false;

    return true;
}

function ipcrRows(form, periodId) {
    const rows = [];

    ["strategic", "core", "support"].forEach((section) => {
        const outputs = (form.outputs ?? []).filter((output) => output.section === section);

        if (outputs.length === 0) return;

        const lines = [];

        outputs.forEach((output) => {
            const title = output.outline_number
                ? `${output.outline_number}. ${output.title}`
                : output.title;
            const kept = (output.indicators ?? []).filter((indicator) => onPaper(indicator, periodId));

            if (kept.length === 0) {
                lines.push({ title, rowSpan: 1, indicator: null });

                return;
            }

            kept.forEach((indicator, index) => {
                lines.push({
                    title: index === 0 ? title : null,
                    rowSpan: index === 0 ? kept.length : 0,
                    indicator,
                });
            });
        });

        if (lines.length === 0) return;

        rows.push({ kind: "section", label: IPCR_BANDS[section] });
        lines.forEach((line) => rows.push({ kind: "line", ...line }));
    });

    return rows;
}

function IpcrPrint({ form, orgName, periodLabel, activePeriodId, summary }) {
    const ratee = (form.owner?.name ?? form.org_unit?.name ?? "").toUpperCase();
    const reviewer = (form.head_reviewer?.name ?? form.reviewed_by_name ?? "").toUpperCase();
    const approver = (form.vp_reviewer?.name ?? form.vp_reviewed_by_name ?? "").toUpperCase();
    const signed = longDate(form.submitted_at) || longDate(form.created_at);
    const rows = ipcrRows(form, activePeriodId);

    const lineCells = (row) => {
        const indicator = row.indicator;
        const accomplishment = indicator?.accomplishments?.find(
            (item) => item.rating_period_id === activePeriodId
        );
        const rating = indicator?.ratings?.find((item) => item.rating_period_id === activePeriodId);

        return (
            <>
                {row.rowSpan > 0 && (
                    <td rowSpan={row.rowSpan} className="m-a pms-ipcr-mid">
                        {row.title}
                    </td>
                )}
                <td colSpan={3} className="b-a">
                    <RichTextView html={indicator?.description} />
                </td>
                <td colSpan={3} className="m-t m-b m-r">
                    <RichTextView html={accomplishment?.actual_accomplishment} />
                </td>
                <td className="m-a pms-ipcr-center">{rating?.q ?? ""}</td>
                <td className="m-a pms-ipcr-center">{rating?.e ?? ""}</td>
                <td className="m-a pms-ipcr-center">{rating?.t ?? ""}</td>
                <td className="m-a pms-ipcr-center">{score(rating?.a)}</td>
                <td colSpan={2} className="m-a">
                    <RichTextView html={rating?.remarks} />
                </td>
            </>
        );
    };

    return (
        <div className="pms-print-sheet pms-print-official pms-print-ipcr">
            <table className="pms-ipcr">
                <colgroup>
                    {IPCR_COLS.map((width, index) => (
                        <col key={index} style={{ width }} />
                    ))}
                </colgroup>
                <tbody>
                    <tr>
                        <td colSpan={13} className="b-t b-l b-r pms-ipcr-title">
                            INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW
                        </td>
                    </tr>
                    <tr>
                        <td colSpan={13} className="b-l b-r pms-ipcr-lead">
                            I, {ratee}, of the {orgName} commit to deliver and agree to be rated on the
                            attainment of the following targets in accordance with the indicated measures
                            for the period {periodLabel}.
                        </td>
                    </tr>
                    <tr>
                        <td colSpan={6} className="b-l" />
                        <td colSpan={7} className="b-r pms-ipcr-center">{ratee}</td>
                    </tr>
                    <tr>
                        <td colSpan={6} className="b-l" />
                        <td colSpan={7} className="b-r pms-ipcr-center">Ratee</td>
                    </tr>
                    <tr>
                        <td colSpan={6} className="b-l b-b" />
                        <td colSpan={7} className="b-r b-b pms-ipcr-center">Date: {signed}</td>
                    </tr>

                    <tr>
                        <td colSpan={3} className="b-l b-t pms-ipcr-label">Reviewed by</td>
                        <td className="b-a pms-ipcr-center pms-ipcr-label">Date</td>
                        <td colSpan={7} className="b-t b-b b-r pms-ipcr-label">Approved by</td>
                        <td colSpan={2} className="b-t b-b b-r pms-ipcr-center pms-ipcr-label">Date</td>
                    </tr>
                    <tr className="pms-ipcr-signspace">
                        <td colSpan={3} className="b-l b-t" />
                        <td rowSpan={3} className="b-r b-b pms-ipcr-center pms-ipcr-mid">
                            {longDate(form.reviewed_at)}
                        </td>
                        <td colSpan={7} className="b-l b-t" />
                        <td colSpan={2} rowSpan={3} className="b-t b-r b-b pms-ipcr-center pms-ipcr-mid">
                            {longDate(form.vp_reviewed_at)}
                        </td>
                    </tr>
                    <tr className="pms-ipcr-signspace">
                        <td colSpan={3} className="b-l b-b" />
                        <td colSpan={7} className="b-l" />
                    </tr>
                    <tr>
                        <td colSpan={3} className="m-l m-r m-b pms-ipcr-center pms-ipcr-label">{reviewer}</td>
                        <td colSpan={7} className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">{approver}</td>
                    </tr>
                    <tr>
                        <td colSpan={3} className="m-l m-r m-t pms-ipcr-center pms-ipcr-label">Immediate Supervisor</td>
                        <td className="b-r b-t" />
                        <td colSpan={7} className="b-t b-r pms-ipcr-center pms-ipcr-label">Head of Office</td>
                        <td colSpan={2} className="b-t b-r" />
                    </tr>
                    <tr>
                        <td colSpan={13} className="m-a pms-ipcr-gap" />
                    </tr>

                    <tr>
                        <td rowSpan={2} className="m-a pms-ipcr-center pms-ipcr-label pms-ipcr-mid">MFO/PPA</td>
                        <td colSpan={3} className="b-l b-t pms-ipcr-center pms-ipcr-label">Success Indicator</td>
                        <td colSpan={3} className="b-l b-t b-r pms-ipcr-center pms-ipcr-label">Actual</td>
                        <td colSpan={4} className="m-t m-b b-r pms-ipcr-center pms-ipcr-label">Rating</td>
                        <td colSpan={2} rowSpan={2} className="m-a pms-ipcr-center pms-ipcr-label pms-ipcr-mid">Remarks</td>
                    </tr>
                    <tr>
                        <td colSpan={3} className="b-l b-b pms-ipcr-center pms-ipcr-label">(Target + Measure)</td>
                        <td colSpan={3} className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">Accomplishments</td>
                        <td className="b-r b-b pms-ipcr-center pms-ipcr-label">Q</td>
                        <td className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">E</td>
                        <td className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">T</td>
                        <td className="b-l b-b pms-ipcr-center pms-ipcr-label">A</td>
                    </tr>

                    {rows.map((row, index) => (
                        row.kind === "section" ? (
                            <tr key={`s-${index}`}>
                                <td colSpan={13} className="b-l b-r pms-ipcr-label">{row.label}</td>
                            </tr>
                        ) : (
                            <tr key={`l-${index}`}>{lineCells(row)}</tr>
                        )
                    ))}

                    <tr>
                        <td className="m-l m-t m-b">Final Average Rating</td>
                        <td colSpan={12} className="m-r m-t m-b pms-ipcr-center">
                            {score(summary?.final_average)}
                        </td>
                    </tr>
                    <tr>
                        <td className="m-l m-t m-b pms-ipcr-center">Adjectival Rating:</td>
                        <td colSpan={12} className="m-r m-t m-b pms-ipcr-center pms-ipcr-label">
                            {summary?.adjectival ?? ""}
                        </td>
                    </tr>
                    <tr>
                        <td colSpan={13} className="m-a">
                            Comments and Recommendations for Development Purposes:
                        </td>
                    </tr>
                    <tr>
                        <td colSpan={13} className="m-a pms-ipcr-comments">
                            <RichTextView html={form.header_note} />
                        </td>
                    </tr>

                    <tr>
                        <td colSpan={2} className="b-l b-t b-b b-r pms-ipcr-center pms-ipcr-label">Discussed with</td>
                        <td className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">Date</td>
                        <td colSpan={3} className="b-l b-b b-r pms-ipcr-center pms-ipcr-label">Assessed by</td>
                        <td className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">Date</td>
                        <td colSpan={5} className="b-l b-b b-r pms-ipcr-center pms-ipcr-label">Final Rating by</td>
                        <td className="b-l b-r b-b pms-ipcr-center pms-ipcr-label">Date</td>
                    </tr>
                    <tr className="pms-ipcr-signspace">
                        <td colSpan={2} className="b-l b-t b-r" />
                        <td rowSpan={3} className="b-l b-r b-t pms-ipcr-center pms-ipcr-mid">
                            {longDate(form.submitted_at)}
                        </td>
                        <td colSpan={3} rowSpan={2} className="b-l b-t b-r pms-ipcr-center">
                            I certify that I discussed my assessment of the performance with the employee
                        </td>
                        <td className="b-l b-r b-t" />
                        <td colSpan={5} className="b-l b-t b-r" />
                        <td className="b-l b-r b-t" />
                    </tr>
                    <tr>
                        <td colSpan={2} className="b-l b-r b-b pms-ipcr-center">{ratee}</td>
                        <td className="b-l b-r pms-ipcr-center pms-ipcr-mid">{longDate(form.reviewed_at)}</td>
                        <td colSpan={5} className="b-l b-r b-b pms-ipcr-center pms-ipcr-bottom">{approver}</td>
                        <td className="b-l b-r pms-ipcr-center pms-ipcr-mid">
                            {longDate(form.vp_reviewed_at || form.rated_at)}
                        </td>
                    </tr>
                    <tr>
                        <td colSpan={2} className="b-l b-t b-r b-b pms-ipcr-center">Employee</td>
                        <td colSpan={3} className="b-l b-r b-t pms-ipcr-center">Supervisor</td>
                        <td className="b-l b-r" />
                        <td colSpan={5} className="b-l b-r pms-ipcr-center pms-ipcr-bottom">Head of Office</td>
                        <td className="b-l b-r" />
                    </tr>
                    <tr>
                        <td colSpan={13} className="m-a">
                            Legend&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Q – Quality&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;E – Efficiency&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;T – Timeliness&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;A – Average
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

export default function PrintFormPage() {
    const { id } = useParams();
    const organization = useOrganization();
    const [periodId, setPeriodId] = useState(null);
    const [excelBusy, setExcelBusy] = useState(false);

    const { data } = useQuery({
        queryKey: ["print-form", id],
        queryFn: () => api.get(`reports/form/${id}/print`).then((r) => r.data),
    });

    const form = data?.form;
    const scale = (data?.scale?.length ? data.scale : RATING_LEGEND).map((band) => ({
        ...band,
        range: band.range ?? RATING_LEGEND.find((item) => item.value === band.value)?.range,
    }));
    const sealUrl = data?.seal_url;
    const officeHead = data?.office_head;
    const periods = form?.school_year?.periods ?? [];
    const formPeriodId = form?.type === "ipcr" ? (form?.rating_period_id ?? null) : null;
    const activePeriodId =
        formPeriodId ?? periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const rows = useMemo(() => {
        if (!form) return [];

        const out = [];

        ["strategic", "core", "support"].forEach((section) => {
            const outputs = form.outputs.filter((o) => o.section === section);

            if (outputs.length === 0) return;

            out.push({ kind: "section", section, label: SECTION_LABELS[section] });

            outputs.forEach((output) => {
                const title = output.outline_number
                    ? `${output.outline_number}. ${output.title}`
                    : output.title;
                const indent = (output.outline_depth ?? 0) * 12;

                (output.indicators ?? []).forEach((indicator, index) => {
                    out.push({
                        kind: "indicator",
                        section,
                        outputTitle: index === 0 ? title : null,
                        rowSpan: index === 0 ? output.indicators.length : 0,
                        indent,
                        indicator,
                    });
                });

                if ((output.indicators ?? []).length === 0) {
                    out.push({
                        kind: "indicator",
                        section,
                        outputTitle: title,
                        rowSpan: 1,
                        indent,
                        indicator: null,
                    });
                }
            });

            if (section === "core" || section === "support") {
                out.push({ kind: "average", section, label: "AVERAGE RATING" });
            }
        });

        return out;
    }, [form]);

    if (!form) {
        return (
            <div style={{ display: "grid", placeItems: "center", minHeight: "100vh" }}>
                <Spin size="large" />
            </div>
        );
    }

    const isOpcr = form.type === "opcr";
    const summary = form.summaries?.find((s) => s.rating_period_id === activePeriodId);
    const period = periods.find((p) => p.id === activePeriodId);
    const year = form.school_year?.start_date
        ? dayjs(form.school_year.start_date).format("YYYY")
        : form.school_year?.label;
    const periodLabel = isOpcr
        ? `January to December ${year ?? ""}`.trim()
        : period?.label ?? form.school_year?.label ?? "";
    const orgName = organization.name ?? form.org_unit?.name ?? "Opol Community College";
    const rateeName = isOpcr
        ? officeHead?.name ?? form.org_unit?.name
        : form.owner?.name ?? form.org_unit?.name;
    const colCount = isOpcr ? 10 : 8;

    const exportExcel = async () => {
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

    const toolbar = (
            <Space className="pms-no-print" style={{ padding: 16 }}>
                <Button type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
                    Print
                </Button>
                <Button icon={<FileExcelOutlined />} loading={excelBusy} onClick={exportExcel}>
                    Excel
                </Button>
                {!isOpcr && !formPeriodId && periods.length > 1 && (
                    <Select
                        style={{ minWidth: 180 }}
                        value={activePeriodId}
                        onChange={setPeriodId}
                        options={periods.map((p) => ({ value: p.id, label: p.label }))}
                    />
                )}
            </Space>
    );

    if (!isOpcr) {
        return (
            <div>
                {toolbar}
                <IpcrPrint
                    form={form}
                    orgName={orgName}
                    periodLabel={periodLabel}
                    activePeriodId={activePeriodId}
                    summary={summary}
                />
            </div>
        );
    }

    return (
        <div>
            {toolbar}

            <div className="pms-print-sheet pms-print-official">
                <div className="pms-print-letterhead">
                    <div className="pms-print-seal">
                        {sealUrl ? <img src={sealUrl} alt="LGU seal" /> : null}
                    </div>
                    <h1>
                        {isOpcr
                            ? "OFFICE PERFORMANCE COMMITMENT AND REVIEW (OPCR)"
                            : "INDIVIDUAL PERFORMANCE COMMITMENT AND REVIEW"}
                    </h1>
                    <div className="pms-print-seal" />
                </div>

                <p className="pms-print-lead">
                    I, <strong>{rateeName?.toUpperCase()}</strong>
                    {isOpcr ? `, Head of the ${orgName}` : `, of the ${orgName}`}, commit to
                    deliver and agree to be rated on the attainment of the following targets in
                    accordance with the indicated measures for the period{" "}
                    <strong>{periodLabel}</strong>.
                </p>

                {isOpcr ? (
                    <div className="pms-print-opcr-head">
                        <div />
                        <div className="pms-print-signblock">
                            <strong>{(officeHead?.name ?? rateeName ?? "").toUpperCase()}</strong>
                            <div>Office Head</div>
                            <div>Date: {periodLabel}</div>
                        </div>
                        <div className="pms-print-approve">
                            <div>
                                <div>Approved by:</div>
                                <div className="pms-print-signblock" style={{ marginTop: 20 }}>
                                    <strong>{form.vp_reviewer?.name ?? "\u00a0"}</strong>
                                    <div>
                                        {form.vp_reviewer?.position_title ?? "Municipal Administrator"}
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div>Date</div>
                                <div style={{ marginTop: 28 }}>
                                    {form.vp_reviewed_at
                                        ? dayjs(form.vp_reviewed_at).format("MMMM D, YYYY")
                                        : ""}
                                </div>
                            </div>
                            <div className="pms-print-scale">
                                {scale.map((band) => (
                                    <div key={band.value}>
                                        {band.value} - {band.label}
                                        {band.range ? ` (${band.range})` : ""}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="pms-print-signblock" style={{ marginLeft: "auto", width: 280 }}>
                            <strong>{(form.owner?.name ?? rateeName ?? "").toUpperCase()}</strong>
                            <div>Ratee</div>
                            <div>Date: {periodLabel}</div>
                        </div>
                        <table className="pms-print-review">
                            <thead>
                                <tr>
                                    <th>Reviewed by</th>
                                    <th>Date</th>
                                    <th>Approved by</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <strong>
                                            {form.head_reviewer?.name ??
                                                form.reviewed_by_name ??
                                                form.dean_reviewer?.name ??
                                                ""}
                                        </strong>
                                        <div>
                                            {form.head_reviewer?.position_title ??
                                                "Immediate Supervisor"}
                                        </div>
                                    </td>
                                    <td>
                                        {form.reviewed_at
                                            ? dayjs(form.reviewed_at).format("MMMM D, YYYY")
                                            : ""}
                                    </td>
                                    <td>
                                        <strong>
                                            {form.vp_reviewer?.name ?? form.vp_reviewed_by_name ?? ""}
                                        </strong>
                                        <div>
                                            {form.vp_reviewer?.position_title ?? "Head of Office"}
                                        </div>
                                    </td>
                                    <td>
                                        {form.vp_reviewed_at
                                            ? dayjs(form.vp_reviewed_at).format("MMMM D, YYYY")
                                            : ""}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </>
                )}

                <table>
                    <thead>
                        <tr>
                            <th rowSpan={2} style={{ width: isOpcr ? 150 : 180 }}>
                                MFO/PPA
                            </th>
                            <th rowSpan={2} style={{ width: isOpcr ? 220 : 260 }}>
                                Success Indicator
                                <div className="pms-print-sub">(Target + Measure)</div>
                            </th>
                            {isOpcr && <th rowSpan={2}>Alloted Budget</th>}
                            {isOpcr && <th rowSpan={2}>Individual / Accountable</th>}
                            <th rowSpan={2} style={{ width: 200 }}>
                                Actual Accomplishments
                            </th>
                            <th colSpan={4}>Rating</th>
                            <th rowSpan={2}>Remarks</th>
                        </tr>
                        <tr>
                            <th style={{ width: 34 }}>Q</th>
                            <th style={{ width: 34 }}>E</th>
                            <th style={{ width: 34 }}>T</th>
                            <th style={{ width: 42 }}>A</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => {
                            if (row.kind === "section") {
                                return (
                                    <tr key={`s-${index}`} className="pms-section-heading">
                                        <td colSpan={colCount}>
                                            <strong>{row.label.toUpperCase()}</strong>
                                        </td>
                                    </tr>
                                );
                            }

                            if (row.kind === "average") {
                                if (!isOpcr) return null;

                                return (
                                    <tr key={`avg-${index}`}>
                                        <td colSpan={colCount - 1}>
                                            <strong>AVERAGE RATING</strong>
                                        </td>
                                        <td style={{ textAlign: "center" }}>
                                            <strong>{score(summary?.[`${row.section}_average`])}</strong>
                                        </td>
                                    </tr>
                                );
                            }

                            const indicator = row.indicator;
                            const accomplishment = indicator?.accomplishments?.find(
                                (a) => a.rating_period_id === activePeriodId
                            );
                            const rating = indicator?.ratings?.find(
                                (r) => r.rating_period_id === activePeriodId
                            );

                            return (
                                <tr key={`i-${index}`}>
                                    {row.rowSpan > 0 && (
                                        <td
                                            rowSpan={row.rowSpan}
                                            style={{ paddingLeft: 6 + (row.indent ?? 0) }}
                                        >
                                            {row.outputTitle}
                                        </td>
                                    )}
                                    <td className="pms-indicator-cell">
                                        <RichTextView html={indicator?.description} />
                                    </td>
                                    {isOpcr && (
                                        <td style={{ textAlign: "right" }}>
                                            {indicator?.allotted_budget != null
                                                ? Number(indicator.allotted_budget).toLocaleString()
                                                : ""}
                                        </td>
                                    )}
                                    {isOpcr && <td>{accountableFor(indicator)}</td>}
                                    <td className="pms-indicator-cell">
                                        <RichTextView html={accomplishment?.actual_accomplishment} />
                                    </td>
                                    <td style={{ textAlign: "center" }}>{rating?.q ?? ""}</td>
                                    <td style={{ textAlign: "center" }}>{rating?.e ?? ""}</td>
                                    <td style={{ textAlign: "center" }}>{rating?.t ?? ""}</td>
                                    <td style={{ textAlign: "center" }}>{score(rating?.a)}</td>
                                    <td>
                                        <RichTextView html={rating?.remarks} />
                                    </td>
                                </tr>
                            );
                        })}

                        {isOpcr && (
                            <tr>
                                <td colSpan={colCount - 1}>
                                    <strong>Total Overall Rating</strong>
                                </td>
                                <td style={{ textAlign: "center" }}>
                                    <strong>{score(summary?.final_average)}</strong>
                                </td>
                            </tr>
                        )}
                        <tr>
                            <td colSpan={colCount - 1}>
                                <strong>Final Average Rating</strong>
                            </td>
                            <td style={{ textAlign: "center" }}>
                                <strong>{score(summary?.final_average)}</strong>
                            </td>
                        </tr>
                        <tr>
                            <td colSpan={colCount - 1}>
                                <strong>Adjectival Rating</strong>
                            </td>
                            <td style={{ textAlign: "center" }}>
                                <strong>{summary?.adjectival ?? ""}</strong>
                            </td>
                        </tr>
                        {!isOpcr && (
                            <tr>
                                <td colSpan={colCount}>
                                    <strong>Comments and Recommendations for Development Purposes:</strong>
                                    <div className="pms-print-comments">
                                        <RichTextView html={form.header_note} />
                                    </div>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {isOpcr ? (
                    <div className="pms-signature">
                        <div>
                            <div className="pms-print-siglabel">Assessed by</div>
                            <div className="pms-signature-line">
                                <strong>{form.rated_by_name || "\u00a0"}</strong>
                                <div>Mun. Planning &amp; Dev&apos;t Coordinator</div>
                            </div>
                        </div>
                        <div>
                            <div className="pms-print-siglabel">Final Rating by</div>
                            <div className="pms-signature-line">
                                <strong>{form.vp_reviewer?.name || "\u00a0"}</strong>
                                <div>Municipal Administrator — PMT Chairperson</div>
                            </div>
                        </div>
                        <div>
                            <div className="pms-print-siglabel">Head of Agency</div>
                            <div className="pms-signature-line">
                                <strong>{officeHead?.name || rateeName || "\u00a0"}</strong>
                                <div>{officeHead?.position_title || "Head of Agency"}</div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="pms-signature">
                        <div>
                            <div className="pms-print-siglabel">Discussed with</div>
                            <div className="pms-signature-line">
                                <strong>{form.owner?.name || "\u00a0"}</strong>
                                <div>Employee</div>
                            </div>
                        </div>
                        <div>
                            <div className="pms-print-siglabel">Assessed by</div>
                            <div className="pms-print-certify">
                                I certify that I discussed my assessment of the performance with the
                                employee
                            </div>
                            <div className="pms-signature-line">
                                <strong>
                                    {form.head_reviewer?.name || form.reviewed_by_name || "\u00a0"}
                                </strong>
                                <div>Supervisor</div>
                            </div>
                        </div>
                        <div>
                            <div className="pms-print-siglabel">Final Rating by</div>
                            <div className="pms-signature-line">
                                <strong>{officeHead?.name || "\u00a0"}</strong>
                                <div>Head of Office</div>
                            </div>
                        </div>
                    </div>
                )}

                <p className="pms-print-legend">
                    Legend: Q – Quality &nbsp; E – Efficiency &nbsp; T – Timeliness &nbsp; A – Average
                </p>
            </div>
        </div>
    );
}
