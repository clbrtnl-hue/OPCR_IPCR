import React, { useEffect, useRef, useState } from "react";
import { DatePicker, InputNumber, Input } from "antd";
import { LoadingOutlined } from "@ant-design/icons";
import dayjs from "dayjs";

/**
 * A cell you click into, like a spreadsheet. It shows plain text until you
 * click it, then becomes an input, and commits when you leave or press Enter —
 * no modal, no save button, because filling a form in should feel like filling
 * a form in.
 *
 * Escape abandons the edit, which is what people expect when they change their
 * mind mid-cell.
 */
export default function SheetCell({
    value,
    onCommit,
    placeholder = "—",
    type = "text",
    disabled = false,
    align = "left",
    maxLength,
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(value ?? "");
    // Between commit and the server's copy coming back, the cell keeps showing
    // what was typed — reverting to the old text for a beat reads as data loss.
    const [pending, setPending] = useState(false);
    const ref = useRef(null);
    const container = useRef(null);

    useEffect(() => {
        setDraft(value ?? "");
        setPending(false);
    }, [value]);

    useEffect(() => {
        if (!pending) return;

        // If the server refused the change, its copy never arrives to clear
        // this — give up after a beat and show the truth again.
        const bail = setTimeout(() => {
            setPending(false);
            setDraft(value ?? "");
        }, 8000);

        return () => clearTimeout(bail);
    }, [pending, value]);

    useEffect(() => {
        if (editing) ref.current?.focus();
    }, [editing]);

    const commit = () => {
        setEditing(false);

        if ((draft ?? "") !== (value ?? "")) {
            setPending(true);
            onCommit(draft === "" ? null : draft);
        }
    };

    const abandon = () => {
        setDraft(value ?? "");
        setEditing(false);
    };

    /** Tab leaves this cell and opens the next one, as a spreadsheet would. */
    const stepTo = (offset) => {
        const cells = Array.from(document.querySelectorAll(".pms-sheet .pms-cell"));
        const here = cells.indexOf(container.current);
        const next = cells[here + offset];

        if (next) {
            next.focus();
            next.scrollIntoView({ block: "nearest" });
        }
    };

    if (type === "date") {
        return editing ? (
            <DatePicker
                open
                autoFocus
                size="small"
                variant="borderless"
                style={{ width: "100%", padding: 0 }}
                format="MMM D, YYYY"
                value={draft ? dayjs(draft) : null}
                onChange={(d) => {
                    setDraft(d ? d.format("YYYY-MM-DD") : "");
                    setPending(true);
                    onCommit(d ? d.format("YYYY-MM-DD") : null);
                    setEditing(false);
                }}
                onOpenChange={(o) => !o && setEditing(false)}
            />
        ) : (
            <div
                ref={container}
                className={`pms-cell${disabled ? " is-readonly" : ""}${pending ? " is-saving" : ""}`}
                tabIndex={disabled ? -1 : 0}
                onClick={() => !disabled && setEditing(true)}
            >
                {draft ? (
                    dayjs(draft).format("MMM D, YYYY")
                ) : (
                    <span className="pms-cell-empty">{placeholder}</span>
                )}
                {pending && <LoadingOutlined className="pms-cell-spinner" />}
            </div>
        );
    }

    if (disabled) {
        return (
            <div ref={container} className="pms-cell is-readonly">
                {value || <span className="pms-cell-empty">{placeholder}</span>}
            </div>
        );
    }

    if (!editing) {
        return (
            <div
                ref={container}
                className={pending ? "pms-cell is-saving" : "pms-cell"}
                tabIndex={0}
                role="button"
                onClick={() => setEditing(true)}
                onFocus={() => setEditing(true)}
            >
                {draft || <span className="pms-cell-empty">{placeholder}</span>}
                {pending && <LoadingOutlined className="pms-cell-spinner" />}
            </div>
        );
    }

    if (type === "number") {
        return (
            <InputNumber
                ref={ref}
                size="small"
                variant="borderless"
                style={{ width: "100%" }}
                value={draft === "" ? null : Number(draft)}
                min={0}
                formatter={(v) => (v == null ? "" : `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ","))}
                parser={(v) => v.replace(/,/g, "")}
                onChange={(v) => setDraft(v ?? "")}
                onBlur={commit}
                onPressEnter={commit}
                onKeyDown={(e) => e.key === "Escape" && abandon()}
            />
        );
    }

    return (
        <Input.TextArea
            ref={ref}
            size="small"
            variant="borderless"
            autoSize={{ minRows: 1, maxRows: 8 }}
            style={{ padding: 0, textAlign: align }}
            value={draft}
            maxLength={maxLength}
            showCount={Boolean(maxLength)}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => {
                if (e.key === "Escape") abandon();

                // Enter commits; Shift+Enter is a new line, as in a spreadsheet.
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    commit();
                }

                if (e.key === "Tab") {
                    e.preventDefault();
                    commit();
                    setTimeout(() => stepTo(e.shiftKey ? -1 : 1), 0);
                }
            }}
        />
    );
}
