const DIMENSIONS = [
    { key: "q", label: "Quality", short: "Q" },
    { key: "e", label: "Efficiency", short: "E" },
    { key: "t", label: "Timeliness", short: "T" },
];

export function ratingRules(user) {
    const rating = user?.workflow?.rating ?? {};
    const chosen = (rating.dimensions ?? ["q", "e", "t"]).filter((key) =>
        DIMENSIONS.some((dimension) => dimension.key === key)
    );
    const keys = chosen.length ? chosen : ["q", "e", "t"];

    return {
        dimensions: DIMENSIONS.filter((dimension) => keys.includes(dimension.key)),
        min: Number(rating.min ?? 1),
        max: Number(rating.max ?? 5),
    };
}

export function scoreOptions(user) {
    const { min, max } = ratingRules(user);
    const options = [];

    for (let value = min; value <= max; value += 1) {
        options.push({ value, label: String(value) });
    }

    return options;
}

export function averageOf(row, user) {
    const given = ratingRules(user)
        .dimensions.map((dimension) => row?.[dimension.key])
        .filter((value) => value != null && value !== "");

    if (given.length === 0) return null;

    return (given.reduce((sum, value) => sum + Number(value), 0) / given.length).toFixed(2);
}

export function dimensionPhrase(user) {
    return ratingRules(user)
        .dimensions.map((dimension) => dimension.label)
        .join(", ")
        .replace(/, ([^,]*)$/, " and $1");
}
