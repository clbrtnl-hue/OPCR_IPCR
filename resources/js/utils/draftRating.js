const SHIPPED_BANDS = [
    { min: 4.5, label: "Outstanding" },
    { min: 3.5, label: "Very Satisfactory" },
    { min: 2.5, label: "Satisfactory" },
    { min: 1.5, label: "Unsatisfactory" },
    { min: 0, label: "Poor" },
];

function round2(value) {
    return Math.round((value + Number.EPSILON) * 100) / 100;
}

function mean(values) {
    const numbers = values.filter((value) => value != null && value !== "");

    if (numbers.length === 0) return null;

    return round2(numbers.reduce((sum, value) => sum + Number(value), 0) / numbers.length);
}

/** Same rule as the saved line average: the mean of whichever of Q, E, T are filled. */
export function lineAverage(row) {
    return mean(["q", "e", "t"].map((key) => row?.[key]));
}

function countsTowardRating(line) {
    if (line?.closed_at || line?.replacement_status === "pending") return false;

    return !line?.needs_commitment_approval || Boolean(line?.line_approved_at);
}

function rowFor(line, scores, periodId) {
    const draft = scores?.[line.id] ?? scores?.[String(line.id)];

    if (draft) return draft;

    return line.ratings?.find((row) => row.rating_period_id === periodId) ?? {};
}

/**
 * The summary Finalize would store for the scores on screen.
 * Section averages, then the mean of those sections. Matches the server.
 */
export function draftSummary(form, scores, periodId) {
    const bySection = { strategic: [], core: [], support: [] };

    (form?.outputs ?? []).forEach((output) => {
        if (!bySection[output.section]) return;

        (output.indicators ?? []).forEach((line) => {
            if (!countsTowardRating(line)) return;

            bySection[output.section].push(lineAverage(rowFor(line, scores, periodId)));
        });
    });

    const sections = {
        strategic_average: mean(bySection.strategic),
        core_average: mean(bySection.core),
        support_average: mean(bySection.support),
    };

    return {
        ...sections,
        final_average: mean(Object.values(sections)),
    };
}

export function adjectivalOf(average, bands) {
    if (average == null) return null;

    const usable = (bands ?? []).filter((band) => band?.min != null && band.label);
    const list = usable.length ? usable : SHIPPED_BANDS;

    for (const band of list) {
        if (Number(average) >= Number(band.min)) return band.label;
    }

    return "Poor";
}
