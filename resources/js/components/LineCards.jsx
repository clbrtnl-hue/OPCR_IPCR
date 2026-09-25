import React from "react";
import { Progress, Tag } from "antd";
import { toPlainText } from "~/components/RichTextView";
import { PROGRESS_META, SECTION_LABELS, progressTone } from "~/utils/constants";

const SECTIONS = ["strategic", "core", "support"];

function averageOf(rating) {
    const given = [rating?.q, rating?.e, rating?.t].filter((value) => value != null);

    if (given.length === 0) return null;

    return (given.reduce((sum, value) => sum + Number(value), 0) / given.length).toFixed(2);
}

function Score({ label, value }) {
    return (
        <span className="pms-line-score">
            <span>{label}</span>
            <strong>{value ?? "—"}</strong>
        </span>
    );
}

export default function LineCards({ form, periodId, onOpen }) {
    const sections = SECTIONS.map((section) => ({
        section,
        lines: (form.outputs ?? [])
            .filter((output) => (output.section ?? "core") === section)
            .flatMap((output) =>
                (output.indicators ?? []).map((line) => ({
                    line,
                    outputId: output.id,
                    outputTitle: toPlainText(output.title || output.description),
                }))
            ),
    })).filter((group) => group.lines.length > 0);

    if (sections.length === 0) {
        return null;
    }

    return (
        <div className="pms-line-cards">
            {sections.map((group) => (
                <section key={group.section}>
                    <h2 className="pms-line-section">{SECTION_LABELS[group.section] ?? group.section}</h2>
                    {group.lines.map(({ line, outputId, outputTitle }) => {
                        const accomplishment = (line.accomplishments ?? []).find(
                            (row) => row.rating_period_id === periodId
                        );
                        const rating = (line.ratings ?? []).find((row) => row.rating_period_id === periodId);
                        const pct = Math.max(0, Math.min(100, Number(line.progress_pct ?? 0)));
                        const status = line.progress_status ?? "not_started";
                        const meta = PROGRESS_META[status] ?? PROGRESS_META.not_started;
                        const text = toPlainText(accomplishment?.actual_accomplishment);

                        return (
                            <button
                                key={line.id}
                                type="button"
                                className="pms-line-card"
                                data-output-id={outputId}
                                onClick={() => onOpen(line.id)}
                            >
                                {outputTitle && <span className="pms-line-output">{outputTitle}</span>}
                                <span className="pms-line-title">{toPlainText(line.description) || "Untitled line"}</span>
                                <span className="pms-line-progress">
                                    <Progress
                                        percent={pct}
                                        size="small"
                                        strokeColor={progressTone(status, pct)}
                                        format={() => meta.label}
                                    />
                                </span>
                                <span className="pms-line-body">
                                    {text || "No accomplishment yet"}
                                </span>
                                <span className="pms-line-scores">
                                    <Score label="Q" value={rating?.q} />
                                    <Score label="E" value={rating?.e} />
                                    <Score label="T" value={rating?.t} />
                                    <Score label="Avg" value={averageOf(rating)} />
                                </span>
                                {line.target_date && <Tag>{line.target_date}</Tag>}
                            </button>
                        );
                    })}
                </section>
            ))}
        </div>
    );
}
