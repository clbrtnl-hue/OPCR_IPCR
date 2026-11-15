import { toPlainText } from "~/components/RichTextView";
import { SECTION_LABELS } from "~/utils/constants";

const SECTION_ORDER = ["strategic", "core", "support"];

export const RETURN_FIELD_LABELS = {
    title: "MFO/PPA",
    description: "Success indicator",
    target_date: "Target date",
    allotted_budget: "Budget",
    accountable: "Accountable",
    actual_accomplishment: "Actual accomplishment",
    evidence: "Evidence",
};

const LINE_FIELDS = {
    ipcr: ["description", "target_date"],
    opcr: ["description", "target_date", "allotted_budget", "accountable"],
};

const WRITEUP_FIELDS = ["actual_accomplishment", "evidence"];

/** Cells a return may point at. A write-up return marks the accomplishment and the evidence. */
export function returnPlaces(form, kind = "approval") {
    const writeup = kind === "writeup";
    const fields = writeup ? WRITEUP_FIELDS : (LINE_FIELDS[form?.type] ?? LINE_FIELDS.ipcr);
    const places = [];
    const outputs = [...(form?.outputs ?? [])].sort((left, right) => {
        const leftAt = SECTION_ORDER.indexOf(left.section);
        const rightAt = SECTION_ORDER.indexOf(right.section);

        return (leftAt === -1 ? SECTION_ORDER.length : leftAt) - (rightAt === -1 ? SECTION_ORDER.length : rightAt);
    });

    outputs.forEach((output, outputIndex) => {
        const number = output.outline_number ?? String(outputIndex + 1);
        const title = toPlainText(output.title) || "Untitled MFO/PPA";
        const section = output.section ?? "other";
        const sectionLabel = SECTION_LABELS[section] ?? "Other";

        if (!writeup) {
            places.push({
                key: `output-${output.id}`,
                output_id: output.id,
                field: "title",
                section,
                sectionLabel,
                label: `${number}. ${title}`,
                fieldLabel: RETURN_FIELD_LABELS.title,
            });
        }

        (output.indicators ?? []).forEach((line, lineIndex) => {
            const name = toPlainText(line.description) || "Untitled success indicator";

            fields.forEach((field) => {
                places.push({
                    key: `line-${line.id}-${field}`,
                    output_id: output.id,
                    indicator_id: line.id,
                    field,
                    section,
                    sectionLabel,
                    label: `${number}.${lineIndex + 1} ${name}`,
                    fieldLabel: RETURN_FIELD_LABELS[field],
                });
            });
        });
    });

    return places;
}

/** The note left on this cell by the latest return, if it is still open. */
export function returnMark(marks, { outputId, indicatorId, field }) {
    const found = (marks ?? []).find((mark) => {
        if (mark.field !== field) return false;

        return field === "title"
            ? Number(mark.output_id) === Number(outputId)
            : Number(mark.indicator_id) === Number(indicatorId);
    });

    return found?.note ?? null;
}
