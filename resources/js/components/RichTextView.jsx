import React from "react";
import { usePerson } from "~/hooks/usePerson";

/**
 * The single place stored markup is rendered. Everything reaching here has been
 * through App\Support\Html on the way in, so there is exactly one thing to audit
 * rather than a dangerouslySetInnerHTML scattered across pages.
 *
 * A mention is a link to a person; clicking one opens their details in place
 * instead of navigating away from the form being written.
 */
/** The words alone, for captions and other places markup would be noise. */
export function toPlainText(html) {
    if (!html) return "";

    const el = document.createElement("div");
    el.innerHTML = html;

    return (el.textContent || "").replace(/\s+/g, " ").trim();
}

export default function RichTextView({ html, onPerson, className = "" }) {
    const { openPerson } = usePerson();

    if (!html) return null;

    const handleClick = (event) => {
        const link = event.target.closest("a[href^='/people/']");

        if (!link) return;

        event.preventDefault();

        const id = Number(link.getAttribute("href").split("/").pop());

        if (!id) return;

        (onPerson ?? openPerson)(id);
    };

    return (
        <div
            className={`pms-richtext-view ${className}`.trim()}
            onClick={handleClick}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
