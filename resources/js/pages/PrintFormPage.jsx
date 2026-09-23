import React, { useMemo, useState } from "react";
import { Button, Segmented, Space, Spin, message } from "antd";
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

    return (
        <div>
            <Space className="pms-no-print" style={{ padding: 16 }}>
                <Button type="primary" icon={<PrinterOutlined />} onClick={() => window.print()}>
                    Print
                </Button>
                <Button icon={<FileExcelOutlined />} loading={excelBusy} onClick={exportExcel}>
                    Excel
                </Button>
                {!isOpcr && !formPeriodId && periods.length > 1 && (
                    <Segmented
                        value={activePeriodId}
                        onChange={setPeriodId}
                        options={periods.map((p) => ({ value: p.id, label: p.label }))}
                    />
                )}
            </Space>

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
                                            {form.head_reviewer?.name ?? form.reviewed_by_name ?? ""}
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
                                <strong>
                                    {form.rated_by_name || form.vp_reviewer?.name || "\u00a0"}
                                </strong>
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
