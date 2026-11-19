// Where a commitment written from an assigned target is allowed to sit.
// Mirrors the server: a college target cannot hang off Support Functions, and a
// heading handed down from one college MFO only accepts that MFO's targets.
// An IPCR cannot grow its own Strategic Priority heading, so those land in Core.

export function commitmentSection(target) {
    if (target?.section === "strategic") return "core";
    if (target?.source === "opcr" && target?.section === "support") return "core";

    return target?.section || "core";
}

function accepts(target, output) {
    if (target?.source !== "opcr") return true;
    if (output.section === "support") return false;

    return !(
        output.parent_output_id &&
        output.assigned_by &&
        Number(output.parent_output_id) !== Number(target.output_id)
    );
}

export function commitmentHomes(target, outputs) {
    const rows = (outputs ?? []).filter((output) => accepts(target, output));
    const locked = rows.filter(
        (output) =>
            output.assigned_by && Number(output.parent_output_id) === Number(target?.output_id)
    );

    if (locked.length) return locked;

    const section = commitmentSection(target);

    return rows.filter((output) => output.section === section);
}
