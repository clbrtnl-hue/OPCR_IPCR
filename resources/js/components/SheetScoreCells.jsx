import React from "react";
import { Input, Select } from "antd";
import { useAuth } from "~/hooks/useAuth";
import { averageOf, ratingRules } from "~/utils/ratingScale";
import { toPlainText } from "~/components/RichTextView";

function draftOf(line, saved, scoreEdit) {
    const draft = scoreEdit?.scores?.[line.id] ?? scoreEdit?.scores?.[String(line.id)];

    if (scoreEdit && draft) return draft;

    return saved ?? {};
}

// Saved remarks are HTML. Typed remarks are plain text, and a trailing space
// has to survive until the next word.
function remarkDraft(value) {
    if (!value) return "";
    if (!/<\/?[a-z][^>]*>/i.test(value)) return value;

    return toPlainText(value);
}

export function ScoreCells({ line, saved, scoreEdit }) {
    const { user } = useAuth();
    const dimensions = ratingRules(user).dimensions;
    const row = draftOf(line, saved, scoreEdit);
    const editing = Boolean(scoreEdit?.canEdit);
    const average = editing
        ? averageOf(row, user)
        : saved?.a != null
          ? Number(saved.a).toFixed(2)
          : "";

    return (
        <>
            {dimensions.map((dimension) => (
                <td key={dimension.key} className="pms-sheet-score">
                    {editing ? (
                        <Select
                            size="small"
                            style={{ width: 64 }}
                            allowClear
                            value={row?.[dimension.key] ?? undefined}
                            onChange={(value) => scoreEdit.onChange(line.id, dimension.key, value ?? null)}
                            options={scoreEdit.choices}
                        />
                    ) : (
                        row?.[dimension.key] ?? ""
                    )}
                </td>
            ))}
            <td className="pms-sheet-score">{average || ""}</td>
            {scoreEdit && (
                <td style={{ minWidth: 160 }}>
                    {editing ? (
                        <Input.TextArea
                            rows={2}
                            value={remarkDraft(row?.remarks ?? "")}
                            onChange={(event) => scoreEdit.onChange(line.id, "remarks", event.target.value)}
                        />
                    ) : (
                        toPlainText(row?.remarks ?? "") || ""
                    )}
                </td>
            )}
        </>
    );
}

export function ScoreHeaders({ scoreEdit }) {
    const { user } = useAuth();
    const dimensions = ratingRules(user).dimensions;
    const wide = Boolean(scoreEdit?.canEdit);

    return (
        <>
            {dimensions.map((dimension) => (
                <th key={dimension.key} style={{ width: wide ? 78 : 38 }} title={dimension.label}>
                    {dimension.short}
                </th>
            ))}
            <th style={{ width: 46 }} title="Average">A</th>
            {scoreEdit && <th style={{ width: 180 }}>Remarks</th>}
        </>
    );
}

export function ScoreFields({ line, saved, scoreEdit }) {
    const { user } = useAuth();
    const dimensions = ratingRules(user).dimensions;

    if (!scoreEdit) return null;

    const row = draftOf(line, saved, scoreEdit);
    const editing = Boolean(scoreEdit.canEdit);
    const average = editing ? averageOf(row, user) : saved?.a != null ? Number(saved.a).toFixed(2) : null;
    const remarks = toPlainText(row?.remarks ?? "");
    const label = { fontSize: 12, fontWeight: 600 };

    return (
        <div style={{ display: "grid", gap: 12, marginBottom: 16 }}>
            <div style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "flex-end" }}>
                {dimensions.map((dimension) => (
                    <label key={dimension.key} style={{ display: "grid", gap: 4 }}>
                        <span style={label}>{dimension.short}</span>
                        {editing ? (
                            <Select
                                size="small"
                                style={{ width: 64 }}
                                allowClear
                                value={row?.[dimension.key] ?? undefined}
                                onChange={(value) => scoreEdit.onChange(line.id, dimension.key, value ?? null)}
                                options={scoreEdit.choices}
                            />
                        ) : (
                            <strong>{row?.[dimension.key] ?? "—"}</strong>
                        )}
                    </label>
                ))}
                <label style={{ display: "grid", gap: 4 }}>
                    <span style={label}>A</span>
                    <strong>{average || "—"}</strong>
                </label>
            </div>
            <label style={{ display: "grid", gap: 4 }}>
                <span style={label}>Remarks</span>
                {editing ? (
                    <Input.TextArea
                        rows={2}
                        value={remarkDraft(row?.remarks ?? "")}
                        onChange={(event) => scoreEdit.onChange(line.id, "remarks", event.target.value)}
                    />
                ) : (
                    <span>{remarks || "—"}</span>
                )}
            </label>
        </div>
    );
}
