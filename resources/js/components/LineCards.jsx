import React, { useState } from "react";
import { Button, Progress, Tag } from "antd";
import { DownOutlined, PlusOutlined } from "@ant-design/icons";
import { toPlainText } from "~/components/RichTextView";
import { useAuth } from "~/hooks/useAuth";
import { PROGRESS_META, SECTION_LABELS, progressTone } from "~/utils/constants";
import { averageOf, ratingRules } from "~/utils/ratingScale";

const SECTIONS = ["strategic", "core", "support"];

function showReplacedLine(line, output, form) {
    if (!line.closed_at || line.closed_reason !== "target_replaced") return true;
    if (form.type === "opcr") return false;

    const next = (output.indicators ?? []).find(
        (item) => item.replaces_indicator_id === line.id && !item.closed_at
    );

    if (!next) return true;
    if (next.line_approved_at) return false;
    if (next.needs_commitment_approval) return true;

    const formApproved = Boolean(form.commitment_approved_at)
        || !["draft", "returned", "commitment_review"].includes(form.status);

    return !formApproved;
}

function Score({ label, value }) {
    return (
        <span className="pms-line-score">
            <span>{label}</span>
            <strong>{value ?? "—"}</strong>
        </span>
    );
}

function SectionCards({
    group,
    periodId,
    showScores,
    showAccomplishments,
    dimensions,
    user,
    onOpen,
    onEnter,
    vpLinkByTarget,
    canAdd,
    onAdd,
}) {
    const [open, setOpen] = useState(false);

    return (
        <section className={open ? "is-open" : undefined}>
            <div className="pms-line-section-bar">
                <button
                    type="button"
                    className="pms-line-section"
                    aria-expanded={open}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span>{SECTION_LABELS[group.section] ?? group.section}</span>
                    <span className="pms-line-section-count">{group.lines.length + group.blanks.length}</span>
                    <DownOutlined />
                </button>
                {canAdd && (
                    <Button
                        type="text"
                        className="pms-line-add"
                        icon={<PlusOutlined />}
                        onClick={() => onAdd(group.section)}
                    >
                        Add
                    </Button>
                )}
            </div>
            {open &&
                group.blanks.map((output) => (
                    <button
                        key={`blank-${output.id}`}
                        type="button"
                        className="pms-line-card"
                        data-output-id={output.id}
                        onClick={() => onEnter?.()}
                    >
                        <span className="pms-line-output">{toPlainText(output.title) || "Untitled MFO"}</span>
                        <span className="pms-line-title">No success indicator yet</span>
                    </button>
                ))}
            {open &&
                group.lines.map(({ line, outputId, outputTitle }) => {
                    const accomplishment = (line.accomplishments ?? []).find(
                        (row) => row.rating_period_id === periodId
                    );
                    const rating = (line.ratings ?? []).find((row) => row.rating_period_id === periodId);
                    const unlinked = (line.assignments ?? []).length > 0 && (line.children ?? []).length === 0;
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
                            onClick={() => (onEnter ? onEnter() : onOpen(line.id))}
                        >
                            {outputTitle && <span className="pms-line-output">{outputTitle}</span>}
                            <span className="pms-line-title">{toPlainText(line.description) || "Untitled line"}</span>
                            {vpLinkByTarget?.[String(line.parent_indicator_id)] && (
                                <Tag color="purple">{vpLinkByTarget[String(line.parent_indicator_id)]}</Tag>
                            )}
                            <span className="pms-line-progress">
                                {unlinked ? (
                                    <span className="pms-progress-label" style={{ color: "#8c8c8c" }}>
                                        N/A · No Linked Commitment
                                    </span>
                                ) : (
                                    <Progress
                                        percent={pct}
                                        size="small"
                                        strokeColor={progressTone(status, pct)}
                                        format={() => meta.label}
                                    />
                                )}
                            </span>
                            {showAccomplishments && (
                                <span className="pms-line-body">{text || "No accomplishment yet"}</span>
                            )}
                            {showScores && (
                                <span className="pms-line-scores">
                                    {dimensions.map((dimension) => (
                                        <Score
                                            key={dimension.key}
                                            label={dimension.short}
                                            value={rating?.[dimension.key]}
                                        />
                                    ))}
                                    <Score
                                        label="Avg"
                                        value={
                                            rating?.a != null
                                                ? Number(rating.a).toFixed(2)
                                                : averageOf(rating, user)
                                        }
                                    />
                                </span>
                            )}
                            {line.target_date && <Tag>{line.target_date}</Tag>}
                        </button>
                    );
                })}
        </section>
    );
}

export default function LineCards({
    form,
    periodId,
    showScores = false,
    showAccomplishments = true,
    onOpen,
    onEnter,
    vpLinkByTarget = null,
    addSections = [],
    onAdd,
}) {
    const { user } = useAuth();
    const dimensions = ratingRules(user).dimensions;
    const addable = new Set(addSections);
    const sections = SECTIONS.map((section) => {
        const outputs = (form.outputs ?? []).filter((output) => (output.section ?? "core") === section);

        return {
            section,
            blanks: onEnter ? outputs.filter((output) => (output.indicators ?? []).length === 0) : [],
            lines: outputs.flatMap((output) =>
                (output.indicators ?? [])
                    .filter((line) => line.replacement_status !== "pending" && showReplacedLine(line, output, form))
                    .map((line) => ({
                        line,
                        outputId: output.id,
                        outputTitle: toPlainText(output.title || output.description),
                    }))
            ),
        };
    }).filter((group) => group.lines.length > 0 || group.blanks.length > 0 || addable.has(group.section));

    if (sections.length === 0) {
        return null;
    }

    return (
        <div className="pms-line-cards">
            {sections.map((group) => (
                <SectionCards
                    key={group.section}
                    group={group}
                    periodId={periodId}
                    showScores={showScores}
                    showAccomplishments={showAccomplishments}
                    dimensions={dimensions}
                    user={user}
                    onOpen={onOpen}
                    onEnter={onEnter}
                    vpLinkByTarget={vpLinkByTarget}
                    canAdd={addable.has(group.section)}
                    onAdd={onAdd}
                />
            ))}
        </div>
    );
}
