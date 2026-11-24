import { Typography } from "antd";

function recorded(form, output, periodId) {
    const category = output?.attendance_category;

    if (!category || !periodId || !form?.attendance) {
        return null;
    }

    const period = form.attendance[periodId] ?? form.attendance[String(periodId)];

    return period?.[category] ?? null;
}

export default function AttendancePct({ form, output, periodId }) {
    const rate = recorded(form, output, periodId);

    if (rate?.pct == null) {
        return null;
    }

    const counted = Number(rate.counted ?? 0);
    const present = Number(rate.present ?? 0);

    return (
        <Typography.Text className="pms-attendance-evidence" title="Recorded attendance for this support line. The rater can still change the score.">
            {present}/{counted} — {rate.pct}%
            {rate.suggested != null && <span className="pms-attendance-suggested">suggested {rate.suggested}</span>}
        </Typography.Text>
    );
}
