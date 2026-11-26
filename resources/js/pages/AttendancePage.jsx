import React, { useEffect, useMemo, useRef, useState } from "react";
import { Alert, Dropdown, Select, Spin, Tabs, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import PageHeader from "~/components/PageHeader";
import ScheduleCalendar from "~/components/ScheduleCalendar";

const CALENDAR_ONLY = ["admin", "president", "vp"];

const DAY_CHOICES = [
    ["present", "Present"],
    ["absent", "Absent"],
    ["leave", "Leave"],
];
const WORDS = {
    present: "Present",
    absent: "Absent",
    leave: "Leave",
};
const LETTERS = { present: "P", absent: "A", leave: "L" };

function errorText(error, fallback) {
    return error?.response?.data?.message ?? fallback;
}

function markOf(bag, id) {
    if (!bag) return null;
    return bag[id] ?? bag[String(id)] ?? null;
}

function statusMenu(current, onPick) {
    return {
        items: [
            ...DAY_CHOICES.map(([key, label]) => ({
                key,
                label: current === key ? `${label} ✓` : label,
            })),
            { type: "divider" },
            { key: "clear", label: "Clear", disabled: !current },
        ],
        onClick: ({ key }) => onPick(key === "clear" ? null : key),
    };
}

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function savedRate(bag, id) {
    if (!bag) return null;
    return bag[id] ?? bag[String(id)] ?? null;
}

function pctText(rate) {
    return rate?.pct == null ? "—" : `${rate.pct}%`;
}

function categoryRate(bag, key) {
    if (!bag) return null;
    return bag[key] ?? bag[String(key)] ?? null;
}

function eventColumns(categories, members, categoryRates) {
    return (categories ?? []).filter((item) => item.source === "event" && (members ?? []).some((member) => {
        const rate = categoryRate(savedRate(categoryRates, member.id), item.rate_key);
        return Number(rate?.present ?? 0) + Number(rate?.absent ?? 0) + Number(rate?.excused ?? 0) > 0;
    }));
}

function printAttendance({ year, period, office, members, dailyRates, categoryRates, categories }) {
    const columns = eventColumns(categories, members, categoryRates);
    const heads = ["<th class=\"name\">Name</th>", "<th>Daily</th>", ...columns.map((item) => `<th>${escapeHtml(item.name)}</th>`)].join("");
    const rows = (members ?? []).map((member) => {
        const events = savedRate(categoryRates, member.id);
        const cells = columns.map((item) => `<td>${pctText(categoryRate(events, item.rate_key))}</td>`).join("");
        return `<tr><td class="name">${escapeHtml(member.name)}</td><td>${pctText(savedRate(dailyRates, member.id))}</td>${cells}</tr>`;
    }).join("");
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attendance summary</title><style>
        @page { margin: 14mm; }
        body { margin: 0; color: #000; background: #fff; font-family: Arial, sans-serif; }
        h1 { margin: 0 0 4px; font-size: 16px; }
        p { margin: 0 0 12px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #000; padding: 6px 8px; text-align: center; vertical-align: middle; }
        th.name, td.name { text-align: left; white-space: nowrap; }
    </style></head><body>
        <h1>Attendance summary</h1>
        <p>${escapeHtml([office, year?.label, period?.label].filter(Boolean).join(" · ") || "Attendance")}</p>
        <table><thead><tr>${heads}</tr></thead><tbody>${rows}</tbody></table>
    </body></html>`;

    const frame = document.createElement("iframe");
    frame.setAttribute("style", "position:fixed;width:0;height:0;border:0;");
    document.body.appendChild(frame);
    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
    const run = () => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };
    frame.contentWindow.onafterprint = () => frame.remove();
    setTimeout(run, 50);
}

function EverydayAttendance({ year, chosenPeriodId }) {
    const queryClient = useQueryClient();
    const [monthId, setMonthId] = useState(null);
    const [officeId, setOfficeId] = useState(null);
    const [active, setActive] = useState(null);
    const sheetRef = useRef(null);

    const { data, isLoading, isPlaceholderData } = useQuery({
        queryKey: ["attendance", year?.id, chosenPeriodId, monthId, officeId],
        queryFn: () =>
            api
                .get("attendance", {
                    params: {
                        school_year_id: year.id,
                        ...(chosenPeriodId ? { rating_period_id: chosenPeriodId } : {}),
                        ...(monthId ? { month: monthId } : {}),
                        ...(officeId ? { org_unit_id: officeId } : {}),
                    },
                })
                .then((r) => r.data),
        enabled: Boolean(year?.id),
        placeholderData: (previous) => previous,
    });

    const units = data?.units ?? [];
    const watching = Boolean(data?.watching);
    const readonly = Boolean(data?.readonly);
    const selectedOfficeId = data?.selected_unit_id ?? officeId;
    const officeName = units.find((unit) => unit.id === selectedOfficeId)?.name;
    const members = data?.members ?? [];
    const days = data?.days ?? [];
    const months = data?.months ?? [];
    const locked = Boolean(data?.period?.locked);
    const frozen = locked || readonly;
    const today = dayjs().format("YYYY-MM-DD");
    const requestedMonth = months.includes(monthId) ? monthId : data?.month;
    const month = isPlaceholderData && data?.month ? data.month : requestedMonth;
    const monthIndex = Math.max(0, months.indexOf(month));

    const columns = useMemo(() => [
        ...days.map((day) => ({
            kind: "day",
            key: day,
            label: dayjs(day).format("D"),
            hint: dayjs(day).format("ddd"),
        })),
        { kind: "pct", key: "pct", label: "%", hint: "Days", title: "Days present for the whole period" },
    ], [days]);

    const activeColumn = active ? columns[active.col] : null;
    const activeMember = active ? members[active.row] : null;

    useEffect(() => {
        setMonthId(null);
        setActive(null);
    }, [year?.id, chosenPeriodId]);

    useEffect(() => {
        setActive(null);
    }, [month]);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["attendance"] });

    const saveDay = useMutation({
        mutationFn: (payload) => api.put("attendance/days", payload).then((r) => r.data),
        onSuccess: refresh,
        onError: (error) => message.error(errorText(error, "Could not save that day.")),
    });

    const statusAt = (member, column) => {
        if (!member || !column || column.kind !== "day") return null;
        return markOf(data?.daily?.[member.id] ?? data?.daily?.[String(member.id)], column.key);
    };

    const writeCell = (row, col, status) => {
        const column = columns[col];
        const member = members[row];
        if (!column || !member || frozen || column.kind !== "day") return;
        saveDay.mutate({
            rating_period_id: data.period.id,
            user_id: member.id,
            day: column.key,
            status,
        });
    };

    const selectCell = (row, col, focusSheet = true) => {
        setActive({ row, col });
        if (focusSheet) sheetRef.current?.focus();
    };

    const onGridKey = (event) => {
        if (!active) return;
        const maxRow = members.length - 1;
        const maxCol = columns.length - 1;
        const key = event.key.toLowerCase();
        if (event.key === "ArrowUp") {
            event.preventDefault();
            setActive((cell) => ({ ...cell, row: Math.max(0, cell.row - 1) }));
        } else if (event.key === "ArrowDown") {
            event.preventDefault();
            setActive((cell) => ({ ...cell, row: Math.min(maxRow, cell.row + 1) }));
        } else if (event.key === "ArrowLeft") {
            event.preventDefault();
            setActive((cell) => ({ ...cell, col: Math.max(0, cell.col - 1) }));
        } else if (event.key === "ArrowRight" || event.key === "Tab") {
            event.preventDefault();
            setActive((cell) => ({ ...cell, col: Math.min(maxCol, cell.col + 1) }));
        } else if (key === "p") {
            event.preventDefault();
            writeCell(active.row, active.col, "present");
        } else if (key === "a") {
            event.preventDefault();
            writeCell(active.row, active.col, "absent");
        } else if (key === "l") {
            event.preventDefault();
            writeCell(active.row, active.col, "leave");
        } else if (event.key === "Backspace" || event.key === "Delete") {
            event.preventDefault();
            writeCell(active.row, active.col, null);
        }
    };

    const formula = () => {
        if (!activeMember || !activeColumn) {
            if (locked) return "This period is locked. The sheet is read only.";
            return readonly
                ? "Read only. The head of this office records attendance."
                : "Open a cell and choose Present, Absent, or Leave. Tab moves to the next day.";
        }
        const word = WORDS[statusAt(activeMember, activeColumn)] ?? "Blank";
        if (activeColumn.kind === "pct") {
            const rate = data?.daily_rates?.[activeMember.id] ?? data?.daily_rates?.[String(activeMember.id)];
            return `${activeMember.name} · days present ${rate?.pct ?? "—"}%`;
        }
        return `${activeMember.name} · ${dayjs(activeColumn.key).format("dddd, MMMM D")} · ${word}`;
    };

    const officeFilter = watching && units.length > 1 ? (
        <div className="gs-period">
            <Select
                className="pms-filter-select is-wide"
                value={selectedOfficeId}
                onChange={(id) => {
                    setOfficeId(id);
                    setActive(null);
                }}
                options={units.map((unit) => ({ value: unit.id, label: unit.name }))}
                aria-label="Office"
                popupMatchSelectWidth={false}
            />
        </div>
    ) : null;

    return (
        <>
            {isLoading ? (
                <div className="gs-loading"><Spin /></div>
            ) : units.length === 0 && members.length === 0 ? (
                <Alert
                    type="info"
                    showIcon
                    message={watching ? "No offices to show yet." : "Everyday attendance opens for the head of an office. The Calendar tab is open to everyone."}
                />
            ) : (
                <div className="gs" ref={sheetRef} tabIndex={0} onKeyDown={onGridKey}>
                    <div className="gs-print-head">
                        <strong>Attendance record</strong>
                        <span>
                            {data?.period?.label ?? "Period"}
                            {month ? ` · ${dayjs(`${month}-01`).format("MMMM YYYY")}` : ""}
                        </span>
                    </div>
                    <div className="gs-toolbar">
                        {officeFilter}
                        <div className="gs-month">
                            <button type="button" aria-label="Previous month" disabled={monthIndex <= 0} onClick={() => setMonthId(months[monthIndex - 1])}>‹</button>
                            <strong>{month ? dayjs(`${month}-01`).format("MMMM YYYY") : "Month"}</strong>
                            <button type="button" aria-label="Next month" disabled={monthIndex >= months.length - 1} onClick={() => setMonthId(months[monthIndex + 1])}>›</button>
                        </div>
                        <div className="gs-legend">
                            <span><i className="is-present">P</i> Present</span>
                            <span><i className="is-absent">A</i> Absent</span>
                            <span><i className="is-leave">L</i> Leave</span>
                        </div>
                        <span className="gs-toolbar-gap" />
                        <button
                            type="button"
                            className="gs-print"
                            onClick={async () => {
                                const key = "attendance-print";
                                message.loading({ content: "Preparing the summary…", key, duration: 0 });
                                try {
                                    const params = {
                                        school_year_id: year.id,
                                        ...(chosenPeriodId ? { rating_period_id: chosenPeriodId } : {}),
                                        ...(selectedOfficeId ? { org_unit_id: selectedOfficeId } : {}),
                                    };
                                    const [sheet, categoryList] = await Promise.all([
                                        api.get("attendance", { params }).then((response) => response.data),
                                        api.get("attendance-categories").then((response) => response.data),
                                    ]);
                                    message.destroy(key);
                                    printAttendance({
                                        year: sheet.year,
                                        period: sheet.period,
                                        office: officeName,
                                        members: sheet.members,
                                        dailyRates: sheet.daily_rates,
                                        categoryRates: sheet.category_rates,
                                        categories: categoryList?.categories,
                                    });
                                } catch (error) {
                                    message.error({ content: errorText(error, "Could not prepare the attendance summary."), key });
                                }
                            }}
                        >
                            Print
                        </button>
                    </div>
                    <div className="gs-status">{formula()}</div>
                    <div className="gs-scroll">
                        <table className="gs-table">
                            <thead>
                                <tr className="gs-labels">
                                    <th className="gs-rownum" />
                                    <th className="gs-name">Name</th>
                                    {columns.map((column) => (
                                        <th
                                            key={column.key}
                                            title={column.title ?? (column.kind === "day" ? dayjs(column.key).format("dddd, MMMM D") : column.hint)}
                                            className={[
                                                column.kind === "pct" ? "gs-pct" : "gs-day",
                                                column.kind === "day" && column.key === today ? "is-today" : "",
                                                column.kind === "day" && dayjs(column.key).day() === 1 ? "is-week" : "",
                                                column.kind === "day" && [0, 6].includes(dayjs(column.key).day()) ? "is-weekend" : "",
                                            ].filter(Boolean).join(" ")}
                                        >
                                            <span>{column.hint}</span>
                                            <strong>{column.label}</strong>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {members.length === 0 ? (
                                    <tr>
                                        <td className="gs-rownum" />
                                        <td className="gs-empty" colSpan={columns.length + 1}>
                                            {watching ? "No people in this office." : "No people in the offices you lead."}
                                        </td>
                                    </tr>
                                ) : members.map((member, row) => {
                                    const rate = data?.daily_rates?.[member.id] ?? data?.daily_rates?.[String(member.id)];
                                    return (
                                        <tr key={member.id}>
                                            <td className="gs-rownum">{row + 1}</td>
                                            <th className="gs-name">
                                                <span className="gs-person">{member.name}</span>
                                            </th>
                                            {columns.map((column, col) => {
                                                const selected = active?.row === row && active?.col === col;
                                                const tone = [
                                                    column.kind === "pct" ? "gs-pct" : "gs-day",
                                                    column.kind === "day" && column.key === today ? "is-today" : "",
                                                    column.kind === "day" && dayjs(column.key).day() === 1 ? "is-week" : "",
                                                    column.kind === "day" && [0, 6].includes(dayjs(column.key).day()) ? "is-weekend" : "",
                                                    selected ? "is-active" : "",
                                                ].filter(Boolean).join(" ");
                                                if (column.kind === "pct") {
                                                    return (
                                                        <td key={column.key} className={tone} onClick={() => selectCell(row, col)}>
                                                            {pctText(rate)}
                                                        </td>
                                                    );
                                                }
                                                const status = statusAt(member, column);
                                                return (
                                                    <td key={column.key} className={tone}>
                                                        <Dropdown
                                                            menu={statusMenu(status, (next) => writeCell(row, col, next))}
                                                            trigger={["click"]}
                                                            disabled={frozen}
                                                        >
                                                            <button
                                                                type="button"
                                                                className={["gs-cell", !status && !frozen ? "is-empty" : ""].filter(Boolean).join(" ")}
                                                                disabled={frozen}
                                                                aria-label={status ? WORDS[status] : "Choose a status"}
                                                                onClick={() => selectCell(row, col, false)}
                                                            >
                                                                {status ? <span className={`gs-mark is-${status}`}>{LETTERS[status]}</span> : <span className="gs-caret">▾</span>}
                                                            </button>
                                                        </Dropdown>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}

export default function AttendancePage() {
    const { user } = useAuth();
    const calendarOnly = CALENDAR_ONLY.includes(user?.role);
    const [params, setParams] = useSearchParams();
    const tab = params.get("tab") === "calendar" ? "calendar" : "everyday";
    const [yearId, setYearId] = useState(null);
    const [periodId, setPeriodId] = useState(null);

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const activeYear = years.find((year) => year.is_active) ?? years[0];
    const year = years.find((item) => item.id === (yearId ?? activeYear?.id));
    const periods = [...(year?.periods ?? [])].sort((a, b) => a.seq - b.seq);
    const chosenPeriodId =
        (periods.some((period) => period.id === periodId) ? periodId : null) ??
        periods.find((period) => period.is_active)?.id ??
        periods[0]?.id;

    const chooseYear = (id) => {
        setYearId(id);
        setPeriodId(null);
    };

    const filters = tab === "everyday" ? (
        <>
            {years.length > 1 && (
                <Select
                    className="pms-filter-select"
                    value={year?.id}
                    onChange={chooseYear}
                    options={years.map((item) => ({
                        value: item.id,
                        label: item.is_active ? `${item.label} ★` : item.label,
                    }))}
                    aria-label="School year"
                />
            )}
            {periods.length > 0 && (
                <Select
                    className="pms-filter-select"
                    value={chosenPeriodId}
                    onChange={setPeriodId}
                    options={periods.map((period) => ({
                        value: period.id,
                        label: period.is_active ? `${period.label} ★` : period.label,
                    }))}
                    aria-label="Review period"
                />
            )}
        </>
    ) : null;

    if (calendarOnly) {
        return (
            <>
                <PageHeader
                    title="Calendar"
                    subtitle="Schedule an event and choose who can see it. They are notified, and it shows on their calendar."
                />
                <ScheduleCalendar />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title="Attendance"
                subtitle={
                    tab === "calendar"
                        ? "Schedule events with a start and end. Share them with yourself, selected people, or everyone."
                        : "Everyday attendance. Open a cell and choose Present, Absent, or Leave."
                }
                extra={filters}
            />
            <Tabs
                className="pms-attendance-tabs"
                activeKey={tab}
                onChange={(key) => {
                    const next = new URLSearchParams(params);
                    if (key === "calendar") next.set("tab", "calendar");
                    else next.delete("tab");
                    setParams(next, { replace: true });
                }}
                items={[
                    {
                        key: "everyday",
                        label: "Everyday",
                        children: <EverydayAttendance year={year} chosenPeriodId={chosenPeriodId} />,
                    },
                    { key: "calendar", label: "Calendar", children: <ScheduleCalendar /> },
                ]}
            />
        </>
    );
}
