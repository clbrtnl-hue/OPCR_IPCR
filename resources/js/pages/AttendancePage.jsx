import React, { useEffect, useMemo, useRef, useState } from "react";
import { Alert, Button, DatePicker, Drawer, Form, Input, Select, Spin, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";

const DAY_CYCLE = [null, "present", "absent", "leave"];
const MEETING_CYCLE = [null, "present", "absent", "excused"];
const WORDS = {
    present: "Present",
    absent: "Absent",
    leave: "Leave",
    excused: "Excused",
};
const LETTERS = { present: "P", absent: "A", leave: "L", excused: "E" };
const CATEGORY_HINTS = {
    meeting: "Meetings",
    school: "School",
    lgu: "LGU",
    convocation: "Convocation",
    training: "Trainings",
};

function errorText(error, fallback) {
    return error?.response?.data?.message ?? fallback;
}

function markOf(bag, id) {
    if (!bag) return null;
    return bag[id] ?? bag[String(id)] ?? null;
}

function nextStatus(kind, status) {
    const cycle = kind === "meeting" ? MEETING_CYCLE : DAY_CYCLE;
    return cycle[(cycle.indexOf(status ?? null) + 1) % cycle.length];
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

function categoryRate(bag, id, key) {
    const row = savedRate(bag, id);
    return row?.[key] ?? null;
}

function pctText(rate) {
    return rate?.pct == null ? "—" : `${rate.pct}%`;
}

function usedCategories(categories, meetings) {
    const used = new Set((meetings ?? []).map((meeting) => meeting.category));

    return (categories ?? []).filter((category) => used.has(category.key));
}

function monthBox(sheet) {
    const month = sheet.month;
    const days = sheet.days ?? [];
    const members = sheet.members ?? [];
    const periodMeetings = sheet.meetings ?? [];
    const meetings = periodMeetings.filter((meeting) => String(meeting.held_on).slice(0, 7) === month);
    const dayHeads = days
        .map((day) => `<th>${dayjs(day).format("dd")}<br>${dayjs(day).format("D")}</th>`)
        .join("");
    const activityHeads = meetings
        .map((meeting) => {
            const pct = meeting.rate?.pct == null ? "—" : `${meeting.rate.pct}%`;
            return `<th>${escapeHtml(meeting.title)}<br>${dayjs(meeting.held_on).format("D")}<br>${pct}</th>`;
        })
        .join("");
    const categories = usedCategories(sheet.categories, periodMeetings);
    const categoryHeads = categories
        .map((category) => `<th>${escapeHtml(CATEGORY_HINTS[category.key] ?? category.label)}</th>`)
        .join("");
    const rows = members.map((member) => {
        const dayCells = days.map((day) => {
            const status = markOf(sheet.daily?.[member.id] ?? sheet.daily?.[String(member.id)], day);
            return `<td>${LETTERS[status] ?? ""}</td>`;
        }).join("");
        const activityCells = meetings.map((meeting) => {
            const inside = meeting.people?.map(Number).includes(Number(member.id));
            const status = inside ? markOf(meeting.marks, member.id) : null;
            return `<td>${LETTERS[status] ?? ""}</td>`;
        }).join("");
        const categoryCells = categories
            .map((category) => `<td>${pctText(categoryRate(sheet.category_rates, member.id, category.key))}</td>`)
            .join("");
        const daysRate = savedRate(sheet.daily_rates, member.id);
        return `<tr><td class="name">${escapeHtml(member.name)}</td>${dayCells}${activityCells}${categoryCells}<td>${pctText(daysRate)}</td></tr>`;
    }).join("");
    const label = month ? dayjs(`${month}-01`).format("MMMM YYYY") : "Month";

    return `<section class="box"><h2>${escapeHtml(label)}</h2><table><thead><tr><th class="name">Name</th>${dayHeads}${activityHeads}${categoryHeads}<th>Days</th></tr></thead><tbody>${rows}</tbody></table></section>`;
}

function printAttendance({ period, office, sheets }) {
    const boxes = (sheets ?? []).map(monthBox).join("");
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attendance record</title><style>
        @page { size: landscape; margin: 10mm; }
        body { margin: 0; color: #000; background: #fff; font-family: Arial, sans-serif; }
        h1 { margin: 0 0 4px; font-size: 16px; }
        p { margin: 0 0 12px; font-size: 12px; }
        .box { border: 1px solid #000; margin: 0 0 16px; }
        .box + .box { break-before: page; }
        .box h2 { margin: 0; padding: 6px 8px; border-bottom: 1px solid #000; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 9px; }
        th, td { border: 1px solid #000; padding: 3px 2px; text-align: center; vertical-align: middle; }
        td.name { text-align: left; white-space: nowrap; }
    </style></head><body>
        <h1>Attendance record</h1>
        <p>${escapeHtml([office, period?.label].filter(Boolean).join(" · ") || "Attendance")}</p>
        ${boxes}
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

export default function AttendancePage() {
    const queryClient = useQueryClient();
    const [yearId, setYearId] = useState(null);
    const [periodId, setPeriodId] = useState(null);
    const [monthId, setMonthId] = useState(null);
    const [officeId, setOfficeId] = useState(null);
    const [setting, setSetting] = useState(false);
    const [active, setActive] = useState(null);
    const sheetRef = useRef(null);

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

    const { data, isLoading } = useQuery({
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
    });

    const units = data?.units ?? [];
    const recordUnits = data?.record_units ?? units;
    const watching = Boolean(data?.watching);
    const readonly = Boolean(data?.readonly);
    const selectedOfficeId = data?.selected_unit_id ?? officeId;
    const officeName = units.find((unit) => unit.id === selectedOfficeId)?.name;
    const members = data?.members ?? [];
    const meetings = data?.meetings ?? [];
    const days = data?.days ?? [];
    const months = data?.months ?? [];
    const locked = Boolean(data?.period?.locked);
    const frozen = locked || readonly;
    const today = dayjs().format("YYYY-MM-DD");
    const month = months.includes(monthId) ? monthId : data?.month;
    const monthIndex = Math.max(0, months.indexOf(month));
    const monthMeetings = meetings.filter((meeting) => String(meeting.held_on).slice(0, 7) === month);
    const categories = usedCategories(data?.categories, meetings);

    const columns = useMemo(() => {
        const dayColumns = days.map((day) => ({
            kind: "day",
            key: day,
            label: dayjs(day).format("D"),
            hint: dayjs(day).format("ddd"),
        }));
        const meetingColumns = monthMeetings.map((meeting) => ({
            kind: "meeting",
            key: `m-${meeting.id}`,
            label: dayjs(meeting.held_on).format("D"),
            hint: meeting.title,
            meeting,
        }));
        const categoryColumns = categories.map((category) => ({
            kind: "category",
            key: category.key,
            label: "%",
            hint: CATEGORY_HINTS[category.key] ?? category.label,
            title: `${category.label} for the whole period`,
        }));
        return [
            ...dayColumns,
            ...meetingColumns,
            ...categoryColumns,
            { kind: "pct", key: "pct", label: "%", hint: "Days", title: "Days present for the whole period" },
        ];
    }, [days, monthMeetings, categories]);

    const activeColumn = active ? columns[active.col] : null;
    const activeMember = active ? members[active.row] : null;

    useEffect(() => {
        setActive(null);
    }, [month, chosenPeriodId]);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["attendance"] });

    const saveDay = useMutation({
        mutationFn: (payload) => api.put("attendance/days", payload).then((r) => r.data),
        onSuccess: refresh,
        onError: (error) => message.error(errorText(error, "Could not save that day.")),
    });

    const saveMeetingMark = useMutation({
        mutationFn: ({ meetingId, userId, status }) =>
            api.put(`attendance/meetings/${meetingId}/marks`, { user_id: userId, status }).then((r) => r.data),
        onSuccess: refresh,
        onError: (error) => message.error(errorText(error, "Could not save that mark.")),
    });

    const createMeeting = useMutation({
        mutationFn: (payload) => api.post("attendance/meetings", payload).then((r) => r.data),
        onSuccess: (_result, variables) => {
            message.success("Activity column added.");
            setSetting(false);
            if (variables.held_on) setMonthId(String(variables.held_on).slice(0, 7));
            refresh();
        },
        onError: (error) => message.error(errorText(error, "Could not set that activity.")),
    });

    const removeMeeting = useMutation({
        mutationFn: (meetingId) => api.delete(`attendance/meetings/${meetingId}`),
        onSuccess: () => {
            message.success("Activity column removed.");
            setActive(null);
            refresh();
        },
        onError: (error) => message.error(errorText(error, "Could not remove that activity.")),
    });

    const chooseYear = (id) => {
        setYearId(id);
        setPeriodId(null);
        setMonthId(null);
    };

    const choosePeriod = (id) => {
        setPeriodId(id);
        setMonthId(null);
    };

    const statusAt = (member, column) => {
        if (!member || !column) return null;
        if (column.kind === "day") {
            return markOf(data?.daily?.[member.id] ?? data?.daily?.[String(member.id)], column.key);
        }
        if (column.kind === "meeting") {
            return markOf(column.meeting.marks, member.id);
        }
        return null;
    };

    const writeCell = (row, col, status) => {
        const column = columns[col];
        const member = members[row];
        if (!column || !member || frozen || column.kind === "pct" || column.kind === "category") return;
        if (column.kind === "day") {
            saveDay.mutate({
                rating_period_id: data.period.id,
                user_id: member.id,
                day: column.key,
                status,
            });
            return;
        }
        if (!column.meeting.people.map(Number).includes(Number(member.id))) return;
        saveMeetingMark.mutate({ meetingId: column.meeting.id, userId: member.id, status });
    };

    const selectCell = (row, col) => {
        setActive({ row, col });
        sheetRef.current?.focus();
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
        } else if (event.key === "Enter") {
            event.preventDefault();
            const column = columns[active.col];
            if (!column || column.kind === "pct" || column.kind === "category") return;
            writeCell(active.row, active.col, nextStatus(column.kind, statusAt(members[active.row], column)));
        } else if (key === "p") {
            event.preventDefault();
            writeCell(active.row, active.col, "present");
        } else if (key === "a") {
            event.preventDefault();
            writeCell(active.row, active.col, "absent");
        } else if (key === "l" && columns[active.col]?.kind === "day") {
            event.preventDefault();
            writeCell(active.row, active.col, "leave");
        } else if (key === "e" && columns[active.col]?.kind === "meeting") {
            event.preventDefault();
            writeCell(active.row, active.col, "excused");
        } else if (event.key === "Backspace" || event.key === "Delete") {
            event.preventDefault();
            writeCell(active.row, active.col, null);
        }
    };

    const formula = () => {
        if (!activeMember || !activeColumn) {
            return readonly
                ? "Read only. The head of this office records attendance."
                : "Click a cell to mark it. P present, A absent, L leave. Delete clears the cell.";
        }
        const word = WORDS[statusAt(activeMember, activeColumn)] ?? "Blank";
        if (activeColumn.kind === "pct") {
            const rate = data?.daily_rates?.[activeMember.id] ?? data?.daily_rates?.[String(activeMember.id)];
            return `${activeMember.name} · days present ${rate?.pct ?? "—"}%`;
        }
        if (activeColumn.kind === "category") {
            const rate = categoryRate(data?.category_rates, activeMember.id, activeColumn.key);
            return `${activeMember.name} · ${activeColumn.hint} ${pctText(rate)} · ${rate?.present ?? 0} present, ${rate?.absent ?? 0} absent, ${rate?.excused ?? 0} excused`;
        }
        if (activeColumn.kind === "meeting") {
            const rate = activeColumn.meeting.rate;
            const summary = rate?.counted
                ? `${rate.pct}% · ${rate.present} present, ${rate.absent} absent, ${rate.excused} excused`
                : `${rate?.excused ?? 0} excused`;
            return `${activeMember.name} · ${activeColumn.meeting.title} · ${dayjs(activeColumn.meeting.held_on).format("MMM D")} · ${word} · ${summary}`;
        }
        return `${activeMember.name} · ${dayjs(activeColumn.key).format("dddd, MMMM D")} · ${word}`;
    };

    return (
        <>
            <PageHeader
                title="Attendance"
                subtitle={
                    locked
                        ? "This period is locked. The sheet is read only."
                        : readonly
                          ? "Read only. The head of this office records attendance."
                          : "Every day in the period. An activity becomes another column."
                }
                extra={
                    <div className="gs-period">
                        {watching && units.length > 1 && (
                            <Select
                                value={selectedOfficeId}
                                onChange={(id) => {
                                    setOfficeId(id);
                                    setActive(null);
                                }}
                                options={units.map((unit) => ({ value: unit.id, label: unit.name }))}
                                aria-label="Office"
                                popupMatchSelectWidth={false}
                            />
                        )}
                        {periods.length > 0 && (
                            <Select
                                value={chosenPeriodId}
                                onChange={choosePeriod}
                                options={periods.map((period) => ({
                                    value: period.id,
                                    label: period.is_active ? `${period.label} ★` : period.label,
                                }))}
                            />
                        )}
                        {years.length > 1 && (
                            <Select
                                value={year?.id}
                                onChange={chooseYear}
                                options={years.map((item) => ({
                                    value: item.id,
                                    label: item.is_active ? `${item.label} (active)` : item.label,
                                }))}
                            />
                        )}
                    </div>
                }
            />

            {isLoading ? (
                <div className="gs-loading"><Spin /></div>
            ) : units.length === 0 ? (
                <Alert
                    type="info"
                    showIcon
                    message={watching ? "No offices to show yet." : "Attendance opens for the head, dean, director, or VP of an office."}
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
                        <div className="gs-month">
                            <button type="button" aria-label="Previous month" disabled={monthIndex <= 0} onClick={() => setMonthId(months[monthIndex - 1])}>‹</button>
                            <strong>{month ? dayjs(`${month}-01`).format("MMMM YYYY") : "Month"}</strong>
                            <button type="button" aria-label="Next month" disabled={monthIndex >= months.length - 1} onClick={() => setMonthId(months[monthIndex + 1])}>›</button>
                        </div>
                        <div className="gs-legend">
                            <span><i className="is-present">P</i> Present</span>
                            <span><i className="is-absent">A</i> Absent</span>
                            <span><i className="is-leave">L</i> Leave</span>
                            <span><i className="is-leave">E</i> Excused</span>
                        </div>
                        <span className="gs-toolbar-gap" />
                        <button type="button" className="gs-add" disabled={frozen || !data?.period} hidden={readonly} onClick={() => setSetting(true)}>
                            + Activity
                        </button>
                        <button
                            type="button"
                            className="gs-print"
                            onClick={async () => {
                                const list = months.length ? months : (month ? [month] : []);
                                const key = "attendance-print";
                                message.loading({ content: "Preparing the record…", key, duration: 0 });
                                try {
                                    const sheets = await Promise.all(list.map((item) =>
                                        api.get("attendance", {
                                            params: {
                                                school_year_id: year.id,
                                                ...(chosenPeriodId ? { rating_period_id: chosenPeriodId } : {}),
                                                ...(selectedOfficeId ? { org_unit_id: selectedOfficeId } : {}),
                                                month: item,
                                            },
                                        }).then((response) => response.data)
                                    ));
                                    message.destroy(key);
                                    printAttendance({ period: data?.period, office: officeName, sheets });
                                } catch (error) {
                                    message.error({ content: errorText(error, "Could not prepare the attendance record."), key });
                                }
                            }}
                        >
                            Print
                        </button>
                        {activeColumn?.kind === "meeting" && !frozen && (
                            <button type="button" className="gs-remove" onClick={() => removeMeeting.mutate(activeColumn.meeting.id)}>
                                Remove
                            </button>
                        )}
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
                                            title={column.title
                                                ?? (column.kind === "day"
                                                    ? dayjs(column.key).format("dddd, MMMM D")
                                                    : column.kind === "meeting"
                                                        ? `${column.hint} · ${dayjs(column.meeting.held_on).format("MMM D")} · ${column.meeting.rate?.present ?? 0} present, ${column.meeting.rate?.absent ?? 0} absent, ${column.meeting.rate?.excused ?? 0} excused`
                                                        : column.hint)}
                                            className={[
                                                column.kind === "meeting" ? "gs-meet" : column.kind === "pct" ? "gs-pct" : column.kind === "category" ? "gs-act" : "gs-day",
                                                column.kind === "day" && column.key === today ? "is-today" : "",
                                                column.kind === "day" && dayjs(column.key).day() === 1 ? "is-week" : "",
                                                column.kind === "day" && [0, 6].includes(dayjs(column.key).day()) ? "is-weekend" : "",
                                            ].filter(Boolean).join(" ")}
                                        >
                                            <span>{column.hint}</span>
                                            <strong>{column.label}</strong>
                                            {column.kind === "meeting" && (
                                                <em>{column.meeting.rate?.pct == null ? "—" : `${column.meeting.rate.pct}%`}</em>
                                            )}
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
                                            <th className="gs-name" title={member.position_title || member.name}>
                                                <span className="gs-person">{member.name}</span>
                                                {member.position_title && <span className="gs-role">{member.position_title}</span>}
                                            </th>
                                            {columns.map((column, col) => {
                                                const selected = active?.row === row && active?.col === col;
                                                const tone = [
                                                    column.kind === "meeting" ? "gs-meet" : column.kind === "pct" ? "gs-pct" : column.kind === "category" ? "gs-act" : "gs-day",
                                                    column.kind === "day" && column.key === today ? "is-today" : "",
                                                    column.kind === "day" && dayjs(column.key).day() === 1 ? "is-week" : "",
                                                    column.kind === "day" && [0, 6].includes(dayjs(column.key).day()) ? "is-weekend" : "",
                                                    selected ? "is-active" : "",
                                                ].filter(Boolean).join(" ");
                                                if (column.kind === "pct" || column.kind === "category") {
                                                    const shown = column.kind === "pct"
                                                        ? rate
                                                        : categoryRate(data?.category_rates, member.id, column.key);
                                                    return (
                                                        <td key={column.key} className={tone} onClick={() => selectCell(row, col)}>
                                                            {pctText(shown)}
                                                        </td>
                                                    );
                                                }
                                                const status = statusAt(member, column);
                                                const outside = column.kind === "meeting"
                                                    && !column.meeting.people.map(Number).includes(Number(member.id));
                                                return (
                                                    <td key={column.key} className={tone}>
                                                        <button
                                                            type="button"
                                                            className={["gs-cell", outside ? "is-outside" : ""].filter(Boolean).join(" ")}
                                                            disabled={frozen || outside}
                                                            onClick={() => {
                                                                selectCell(row, col);
                                                                if (!frozen && !outside) {
                                                                    writeCell(row, col, nextStatus(column.kind, status));
                                                                }
                                                            }}
                                                        >
                                                            {status ? <span className={`gs-mark is-${status}`}>{LETTERS[status]}</span> : null}
                                                        </button>
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

            <SetMeeting
                open={setting}
                units={recordUnits}
                preferredOffice={selectedOfficeId}
                categories={data?.categories ?? []}
                period={data?.period}
                saving={createMeeting.isPending}
                onCancel={() => setSetting(false)}
                onSubmit={(values) =>
                    createMeeting.mutate({
                        ...values,
                        held_on: values.held_on?.format("YYYY-MM-DD"),
                        rating_period_id: data.period.id,
                    })
                }
            />
        </>
    );
}

function SetMeeting({ open, units, preferredOffice, categories, period, saving, onCancel, onSubmit }) {
    const [form] = Form.useForm();

    useEffect(() => {
        if (!open) return;
        const preferred = units.some((unit) => unit.id === preferredOffice) ? preferredOffice : undefined;
        form.resetFields();
        form.setFieldsValue({ org_unit_id: preferred ?? (units.length === 1 ? units[0].id : undefined) });
    }, [open, units, preferredOffice, form]);

    const submit = async () => {
        try {
            onSubmit(await form.validateFields());
        } catch {
            // The drawer stays open so the missing fields can be filled.
        }
    };

    return (
        <Drawer
            title="Add an activity"
            open={open}
            onClose={onCancel}
            width={420}
            destroyOnClose
            footer={
                <div className="gs-drawer-actions">
                    <Button onClick={onCancel}>Cancel</Button>
                    <Button type="primary" loading={saving} onClick={submit}>Add column</Button>
                </div>
            }
        >
            <Form form={form} layout="vertical" requiredMark={false}>
                {units.length > 1 && (
                    <Form.Item name="org_unit_id" label="Office" rules={[{ required: true, message: "Choose the office." }]}>
                        <Select options={units.map((unit) => ({ value: unit.id, label: unit.name }))} />
                    </Form.Item>
                )}
                {units.length === 1 && <Form.Item name="org_unit_id" hidden><Input /></Form.Item>}
                <Form.Item name="title" label="Activity" rules={[{ required: true, message: "Name the activity." }]}>
                    <Input placeholder="Monday convocation" maxLength={200} />
                </Form.Item>
                <Form.Item name="category" label="Counts toward" rules={[{ required: true, message: "Choose what this activity counts toward." }]}>
                    <Select options={categories.map((category) => ({ value: category.key, label: category.label }))} />
                </Form.Item>
                <Form.Item name="held_on" label="Date" rules={[{ required: true, message: "Pick the date." }]}>
                    <DatePicker
                        style={{ width: "100%" }}
                        disabledDate={(date) => {
                            if (!period?.opens_at || !period?.closes_at) return false;
                            return date.isBefore(dayjs(period.opens_at), "day") || date.isAfter(dayjs(period.closes_at), "day");
                        }}
                    />
                </Form.Item>
            </Form>
        </Drawer>
    );
}
