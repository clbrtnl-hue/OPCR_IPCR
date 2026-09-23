export const ROLE_LABELS = {
    admin: "Administrator",
    president: "President",
    qa: "Quality Assurance",
    vp: "Vice President",
    program_head: "Head",
    employee: "Employee",
};

export const STATUS_META = {
    draft: { label: "Draft", color: "default", who: "With the writer" },
    head_review: { label: "With Head", color: "blue", who: "Waiting for the head" },
    vp_review: { label: "With VP", color: "geekblue", who: "Waiting for the VP" },
    qa_approval: { label: "For QA approval", color: "gold", who: "Waiting for QA to approve the targets" },
    approved: { label: "Approved", color: "lime", who: "Approved — waiting to be published" },
    published: { label: "Published", color: "green", who: "Visible to everyone" },
    qa_rating: { label: "With QA", color: "purple", who: "Waiting for QA to rate" },
    rated: { label: "Rated", color: "cyan", who: "Rated by QA" },
    final: { label: "Final", color: "green", who: "Closed" },
    returned: { label: "Returned", color: "orange", who: "Sent back for correction" },
};

export const SECTION_LABELS = {
    strategic: "Strategic Priority",
    core: "Core Functions",
    support: "Support Functions",
};

export const PROGRESS_META = {
    not_started: { label: "Not started", color: "default" },
    ongoing: { label: "Ongoing", color: "processing" },
    completed: { label: "Completed", color: "success" },
    deferred: { label: "Deferred", color: "warning" },
};

export const ADJECTIVAL_COLORS = {
    Outstanding: "green",
    "Very Satisfactory": "cyan",
    Satisfactory: "blue",
    Unsatisfactory: "orange",
    Poor: "red",
};

export const RATING_LEGEND = [
    { value: 5, label: "Outstanding", range: "100%" },
    { value: 4, label: "Very Satisfactory", range: "90-99%" },
    { value: 3, label: "Satisfactory", range: "70-89%" },
    { value: 2, label: "Unsatisfactory", range: "50-69%" },
    { value: 1, label: "Poor", range: "Below 50%" },
];

export const NARRATIVE_MAX = 500;

export const UPLOAD_MAX_MB = 5;

export const UPLOAD_ACCEPT = ".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx";

export const DELAY_META = {
    no_date: { label: "No target date", color: "default" },
    on_track: { label: "On track", color: "default" },
    due_soon: { label: "Due soon", color: "warning" },
    overdue: { label: "Overdue", color: "error" },
    on_time: { label: "On time", color: "success" },
    late: { label: "Late", color: "error" },
};

export const PROGRESS_TONES = {
    not_started: "#bfbfbf",
    early: "#f5222d",
    partial: "#fa8c16",
    nearly: "#1677ff",
    completed: "#52c41a",
    deferred: "#faad14",
};

export function progressTone(status, pct) {
    if (status === "deferred") return PROGRESS_TONES.deferred;
    if (status === "completed" || Number(pct) >= 100) return PROGRESS_TONES.completed;

    const value = Number(pct ?? 0);

    if (value <= 0) return PROGRESS_TONES.not_started;
    if (value < 40) return PROGRESS_TONES.early;
    if (value < 75) return PROGRESS_TONES.partial;

    return PROGRESS_TONES.nearly;
}

export const VIZ = {
    series: ["#2a78d6", "#eb6834", "#1baf7a"],
    ordinal: ["#86b6ef", "#5598e7", "#2a78d6", "#1c5cab", "#0d366b"],
    good: "#0ca30c",
    warning: "#fab219",
    serious: "#ec835a",
    critical: "#d03b3b",
    grid: "#e1e0d9",
    axis: "#c3c2b7",
    muted: "#898781",
    track: "#f0efec",
    ink: "#0b0b0b",
    inkSoft: "#52514e",
};
