import React, { useEffect, useMemo, useRef, useState } from "react";
import { Button, DatePicker, Drawer, Dropdown, Form, Input, Modal, Popconfirm, Radio, Select, Spin, Switch, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import UserAvatar from "~/components/UserAvatar";

const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const WEEK_LETTERS = ["M", "T", "W", "T", "F", "S", "S"];
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
const HOUR_HEIGHT = 48;
const VIEWS = [
    { key: "day", label: "Day" },
    { key: "week", label: "Week" },
    { key: "month", label: "Month" },
    { key: "year", label: "Year" },
    { key: "schedule", label: "Schedule" },
    { key: "4days", label: "4 days" },
];
const VISIBILITY = [
    { value: "only_me", label: "Only me" },
    { value: "selected", label: "Selected users" },
    { value: "everyone", label: "All subordinates" },
];
const COLLEGE_VISIBILITY = [
    { value: "only_me", label: "Only me" },
    { value: "selected", label: "Selected users" },
    { value: "everyone", label: "Everyone" },
    { value: "role", label: "By role" },
];
const AUDIENCE_ROLES = [
    { value: "program_head", label: "Head" },
    { value: "employee", label: "Employee" },
    { value: "vp", label: "Vice President" },
    { value: "qa", label: "Quality Assurance" },
];
const MARKS = [
    ["present", "Present", "P"],
    ["absent", "Absent", "A"],
    ["excused", "Excused", "E"],
];

function errorText(error, fallback) {
    return error?.response?.data?.message ?? fallback;
}

function weekStart(cursor) {
    const weekday = cursor.day();
    const offset = weekday === 0 ? 6 : weekday - 1;

    return cursor.startOf("day").subtract(offset, "day");
}

function gridStart(cursor) {
    return weekStart(cursor.startOf("month"));
}

function monthCells(month) {
    const start = gridStart(month);
    const cells = [];

    for (let index = 0; index < 42; index += 1) {
        const day = start.add(index, "day");
        cells.push(day);
        if (index % 7 === 6 && day.isAfter(month.endOf("month"), "day") && index >= 27) break;
    }

    return cells;
}

function orderedYears(years) {
    return [...years]
        .filter((year) => year.start_date && year.end_date)
        .sort((left, right) => String(left.start_date).localeCompare(String(right.start_date)));
}

function schoolYearFor(years, cursor) {
    const ordered = orderedYears(years);
    const match = ordered.find((year) => {
        const start = dayjs(year.start_date);
        const end = dayjs(year.end_date);

        return !cursor.isBefore(start, "day") && !cursor.isAfter(end, "day");
    });
    if (match) return match;

    const earlier = [...ordered].reverse().find((year) => !cursor.isBefore(dayjs(year.start_date), "day"));

    return earlier ?? ordered[0] ?? null;
}

function schoolYearOn(years, day) {
    return orderedYears(years).find((year) => {
        const start = dayjs(year.start_date);
        const end = dayjs(year.end_date);

        return !day.isBefore(start, "day") && !day.isAfter(end, "day");
    }) ?? null;
}

function schoolYearTouching(years, month) {
    const start = month.startOf("month");
    const end = month.endOf("month");

    return orderedYears(years).find((year) => {
        const yearStart = dayjs(year.start_date);
        const yearEnd = dayjs(year.end_date);

        return !end.isBefore(yearStart, "day") && !start.isAfter(yearEnd, "day");
    }) ?? null;
}

function clampToYear(day, year) {
    if (!year?.start_date || !year?.end_date) return day;
    const start = dayjs(year.start_date);
    const end = dayjs(year.end_date);
    if (day.isBefore(start, "day")) return start;
    if (day.isAfter(end, "day")) return end;

    return day;
}

function periodSpan(period, year) {
    let start;
    let end;

    if (period?.opens_at && period?.closes_at) {
        start = dayjs(period.opens_at).startOf("day");
        end = dayjs(period.closes_at).startOf("day");
    } else if (year?.start_date && year?.end_date) {
        start = dayjs(year.start_date).startOf("day");
        end = dayjs(year.end_date).startOf("day");
        const mid = start.add(Math.floor(Math.max(end.diff(start, "second"), 0) / 2), "second").startOf("day");
        if (Number(period?.seq) > 1) start = mid.add(1, "day");
        else end = mid;
    } else {
        return null;
    }

    return end.isBefore(start, "day") ? null : [start, end];
}

function spanForPeriod(years, periodId) {
    const match = years
        .map((year) => ({ year, period: (year.periods ?? []).find((item) => item.id === periodId) }))
        .find((item) => item.period);

    return match ? periodSpan(match.period, match.year) : null;
}

function applyFieldErrors(form, error) {
    const errors = error?.response?.data?.errors;
    if (!errors || typeof errors !== "object") return false;

    const fields = Object.entries(errors)
        .filter(([, messages]) => Array.isArray(messages) && messages.length)
        .map(([name, messages]) => ({ name, errors: messages }));

    if (!fields.length) return false;
    form.setFields(fields);

    return true;
}

function covers(event, day) {
    const start = dayjs(event.starts_at).startOf("day");
    const end = dayjs(event.ends_at).startOf("day");

    return !day.isBefore(start, "day") && !day.isAfter(end, "day");
}

function eventsOn(events, day) {
    return events
        .filter((event) => covers(event, day))
        .sort((left, right) => {
            if (left.all_day !== right.all_day) return left.all_day ? -1 : 1;
            return String(left.starts_at).localeCompare(String(right.starts_at));
        });
}

function presentOn(event, day) {
    const key = day.format("YYYY-MM-DD");

    return (event.present ?? []).filter((person) => person.day === key);
}

function chipLabel(event) {
    if (event.all_day) return event.title;
    return `${dayjs(event.starts_at).format("h:mm A")} ${event.title}`;
}

function whenText(event) {
    const start = dayjs(event.starts_at);
    const end = dayjs(event.ends_at);

    if (event.all_day) {
        if (start.isSame(end, "day")) return start.format("dddd, MMMM D, YYYY");
        return `${start.format("MMM D, YYYY")} – ${end.format("MMM D, YYYY")}`;
    }

    if (start.isSame(end, "day")) {
        return `${start.format("dddd, MMMM D, YYYY")} · ${start.format("h:mm A")} – ${end.format("h:mm A")}`;
    }

    return `${start.format("MMM D, YYYY h:mm A")} – ${end.format("MMM D, YYYY h:mm A")}`;
}

function spanTitle(start, end) {
    if (start.isSame(end, "day")) return start.format("MMMM D, YYYY");
    if (start.isSame(end, "year")) return `${start.format("MMM D")} – ${end.format("MMM D, YYYY")}`;

    return `${start.format("MMM D, YYYY")} – ${end.format("MMM D, YYYY")}`;
}

function visibleSpan(view, cursor, year) {
    if (view === "day") {
        const day = cursor.startOf("day");
        return { from: day, to: day, days: [day] };
    }

    if (view === "week") {
        const start = weekStart(cursor);
        const days = Array.from({ length: 7 }, (_, index) => start.add(index, "day"));
        return { from: days[0], to: days[6], days };
    }

    if (view === "4days") {
        const start = cursor.startOf("day");
        const days = Array.from({ length: 4 }, (_, index) => start.add(index, "day"));
        return { from: days[0], to: days[3], days };
    }

    if (view === "month") {
        const start = gridStart(cursor);
        const days = Array.from({ length: 42 }, (_, index) => start.add(index, "day"));
        return { from: days[0], to: days[41], days };
    }

    const from = year?.start_date ? dayjs(year.start_date).startOf("day") : cursor.startOf("year");
    const to = year?.end_date ? dayjs(year.end_date).startOf("day") : cursor.endOf("year");

    return { from, to, days: [] };
}

function canStep(view, cursor, year, years, direction) {
    if (view === "year" || view === "schedule") {
        const ordered = orderedYears(years);
        const index = ordered.findIndex((item) => item.id === year?.id);

        return Boolean(ordered[index + direction]);
    }

    if (!year?.start_date || !year?.end_date) return true;

    const start = dayjs(year.start_date);
    const end = dayjs(year.end_date);

    if (view === "month") {
        const next = cursor.add(direction, "month");
        return !next.endOf("month").isBefore(start, "day") && !next.startOf("month").isAfter(end, "day");
    }

    if (view === "week") {
        const week = weekStart(cursor.add(direction, "week"));
        return !week.add(6, "day").isBefore(start, "day") && !week.isAfter(end, "day");
    }

    if (view === "4days") {
        const next = cursor.add(direction * 4, "day");
        return !next.add(3, "day").isBefore(start, "day") && !next.isAfter(end, "day");
    }

    const next = cursor.add(direction, "day");

    return !next.isBefore(start, "day") && !next.isAfter(end, "day");
}

function stepCursor(view, cursor, year, years, direction) {
    if (view === "year" || view === "schedule") {
        const ordered = orderedYears(years);
        const index = ordered.findIndex((item) => item.id === year?.id);
        const next = ordered[index + direction];

        return next?.start_date ? dayjs(next.start_date) : cursor;
    }

    if (view === "week") return cursor.add(direction, "week");
    if (view === "month") return cursor.add(direction, "month");
    if (view === "4days") return cursor.add(direction * 4, "day");

    return cursor.add(direction, "day");
}

function timedBlocks(events, day) {
    const items = eventsOn(events, day)
        .filter((event) => !event.all_day)
        .map((event) => {
            const start = dayjs(event.starts_at);
            const end = dayjs(event.ends_at);
            const startMin = start.isSame(day, "day") ? start.hour() * 60 + start.minute() : 0;
            let endMin = end.isSame(day, "day") ? end.hour() * 60 + end.minute() : 24 * 60;
            if (endMin <= startMin) endMin = startMin + 30;

            return { event, startMin, endMin };
        })
        .sort((left, right) => left.startMin - right.startMin || left.endMin - right.endMin);

    const columnEnds = [];
    const placed = items.map((item) => {
        let column = columnEnds.findIndex((end) => end <= item.startMin);
        if (column === -1) {
            column = columnEnds.length;
            columnEnds.push(item.endMin);
        } else {
            columnEnds[column] = item.endMin;
        }

        return { ...item, column };
    });

    return placed.map((item) => {
        const overlap = placed.filter((other) => other.startMin < item.endMin && other.endMin > item.startMin);
        const width = Math.max(...overlap.map((other) => other.column), 0) + 1;

        return { ...item, width };
    });
}

function PresentFaces({ people }) {
    if (!people.length) return null;

    const shown = people.slice(0, 3);
    const extra = people.length - shown.length;

    return (
        <span className="cal-faces">
            {shown.map((person) => (
                <UserAvatar key={person.user_id} name={person.name} image={person.image} size={16} />
            ))}
            {extra > 0 && <span className="cal-faces-more">+{extra}</span>}
        </span>
    );
}

function EventChip({ event, day, onOpen }) {
    return (
        <button
            type="button"
            className={`cal-chip is-${event.visibility.replace("_", "-")}`}
            title={event.title}
            onClick={(click) => {
                click.stopPropagation();
                onOpen(event);
            }}
        >
            <span className="cal-chip-title">{chipLabel(event)}</span>
            <PresentFaces people={presentOn(event, day)} />
        </button>
    );
}

function MonthGrid({ cursor, days, events, onCreate, onOpen }) {
    return (
        <>
            <div className="cal-week">
                {WEEKDAYS.map((label) => <div key={label}>{label}</div>)}
            </div>
            <div className="cal-grid">
                {days.map((day) => {
                    const listed = eventsOn(events, day);
                    const shown = listed.slice(0, 3);
                    const extra = listed.slice(3);
                    const outside = day.month() !== cursor.month();

                    return (
                        <div
                            key={day.format("YYYY-MM-DD")}
                            className={["cal-day", outside ? "is-outside" : "", day.isSame(dayjs(), "day") ? "is-today" : ""].filter(Boolean).join(" ")}
                            onClick={() => onCreate(day)}
                        >
                            <span className="cal-num">{day.format("D")}</span>
                            {shown.map((event) => (
                                <EventChip key={event.id} event={event} day={day} onOpen={onOpen} />
                            ))}
                            {extra.length > 0 && (
                                <button
                                    type="button"
                                    className="cal-more"
                                    onClick={(click) => {
                                        click.stopPropagation();
                                        onOpen(extra[0], extra);
                                    }}
                                >
                                    +{extra.length} more
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </>
    );
}

function TimeGrid({ days, events, onCreate, onOpen }) {
    const scroller = useRef(null);
    const columns = `64px repeat(${days.length}, minmax(0, 1fr))`;

    useEffect(() => {
        if (scroller.current) scroller.current.scrollTop = 7 * HOUR_HEIGHT;
    }, [days]);

    return (
        <div className="cal-time">
            <div className="cal-time-head" style={{ gridTemplateColumns: columns }}>
                <div />
                {days.map((day) => (
                    <div key={day.format("YYYY-MM-DD")}>
                        <span>{day.format("ddd")}</span>
                        <strong className={day.isSame(dayjs(), "day") ? "is-today" : ""}>{day.format("D")}</strong>
                    </div>
                ))}
            </div>
            <div className="cal-allday" style={{ gridTemplateColumns: columns }}>
                <div className="cal-time-gutter">All day</div>
                {days.map((day) => (
                    <div key={day.format("YYYY-MM-DD")} className="cal-allday-col" onClick={() => onCreate(day)}>
                        {eventsOn(events, day).filter((event) => event.all_day).map((event) => (
                            <EventChip key={event.id} event={event} day={day} onOpen={onOpen} />
                        ))}
                    </div>
                ))}
            </div>
            <div className="cal-time-scroll" ref={scroller}>
                <div className="cal-time-body" style={{ gridTemplateColumns: columns }}>
                    <div>
                        {HOURS.map((hour) => (
                            <div key={hour} className="cal-hour-label">{dayjs().hour(hour).minute(0).format("h A")}</div>
                        ))}
                    </div>
                    {days.map((day) => (
                        <div key={day.format("YYYY-MM-DD")} className="cal-time-col" onClick={() => onCreate(day)}>
                            {HOURS.map((hour) => <div key={hour} className="cal-hour" />)}
                            {timedBlocks(events, day).map(({ event, startMin, endMin, column, width }) => (
                                <button
                                    key={event.id}
                                    type="button"
                                    className={`cal-block is-${event.visibility.replace("_", "-")}`}
                                    style={{
                                        top: (startMin / 60) * HOUR_HEIGHT,
                                        height: Math.max(((endMin - startMin) / 60) * HOUR_HEIGHT, 22),
                                        left: `calc(${column} * (100% / ${width}) + 2px)`,
                                        width: `calc(100% / ${width} - 4px)`,
                                    }}
                                    title={event.title}
                                    onClick={(click) => {
                                        click.stopPropagation();
                                        onOpen(event);
                                    }}
                                >
                                    <span className="cal-chip-title">{chipLabel(event)}</span>
                                    <PresentFaces people={presentOn(event, day)} />
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function YearGrid({ year, events, onPickDay }) {
    const start = year?.start_date ? dayjs(year.start_date).startOf("month") : dayjs().startOf("year");
    const end = year?.end_date ? dayjs(year.end_date).startOf("month") : start.add(11, "month");
    const months = [];

    for (let cursor = start; !cursor.isAfter(end, "month") && months.length < 16; cursor = cursor.add(1, "month")) {
        months.push(cursor);
    }

    return (
        <div className="cal-year">
            {months.map((month) => (
                <section key={month.format("YYYY-MM")} className="cal-year-month">
                    <strong>{month.format("MMMM")}</strong>
                    <div className="cal-year-week">
                        {WEEK_LETTERS.map((letter, index) => <span key={`${month.format("YYYY-MM")}-${index}`}>{letter}</span>)}
                    </div>
                    <div className="cal-year-days">
                        {monthCells(month).map((day) => {
                            const outside = day.month() !== month.month();
                            const hasEvent = !outside && eventsOn(events, day).length > 0;
                            const today = day.isSame(dayjs(), "day");

                            return (
                                <button
                                    key={day.format("YYYY-MM-DD")}
                                    type="button"
                                    className={[outside ? "is-outside" : "", today ? "is-today" : "", hasEvent ? "has-event" : ""].filter(Boolean).join(" ")}
                                    onClick={() => onPickDay(day)}
                                >
                                    {day.format("D")}
                                    {hasEvent && <i />}
                                </button>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}

function ScheduleList({ events, from, to, onOpen }) {
    const groups = new Map();

    [...events]
        .sort((left, right) => String(left.starts_at).localeCompare(String(right.starts_at)))
        .forEach((event) => {
            const start = dayjs(event.starts_at).startOf("day");
            const keyDay = start.isBefore(from, "day") ? from : start;
            const key = keyDay.format("YYYY-MM-DD");
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(event);
        });

    const rows = [...groups.entries()];

    if (!rows.length) {
        return <div className="cal-empty">No events in this school year.</div>;
    }

    return (
        <div className="cal-schedule">
            {rows.map(([key, listed]) => {
                const day = dayjs(key);

                return (
                    <div key={key} className="cal-schedule-day">
                        <div className={`cal-schedule-date${day.isSame(dayjs(), "day") ? " is-today" : ""}`}>
                            <strong>{day.format("D")}</strong>
                            <span>{day.format("MMM, ddd")}</span>
                        </div>
                        <div>
                            {listed.map((event) => (
                                <button key={event.id} type="button" className="cal-schedule-row" onClick={() => onOpen(event)}>
                                    <i className={`is-${event.visibility.replace("_", "-")}`} />
                                    <span>{event.all_day ? "All day" : dayjs(event.starts_at).format("h:mm A")}</span>
                                    <strong>{event.title}</strong>
                                    <PresentFaces people={presentOn(event, day)} />
                                </button>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export default function ScheduleCalendar() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [cursor, setCursor] = useState(() => dayjs());
    const [view, setView] = useState("month");
    const [draft, setDraft] = useState(null);
    const [categoriesOpen, setCategoriesOpen] = useState(false);

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((response) => response.data),
    });

    const activeYear = years.find((item) => item.is_active) ?? null;

    useEffect(() => {
        if (!activeYear?.start_date || !activeYear?.end_date) return;
        const start = dayjs(activeYear.start_date);
        const end = dayjs(activeYear.end_date);
        const today = dayjs();
        if (today.isBefore(start, "day") || today.isAfter(end, "day")) {
            setCursor(start);
        }
    }, [activeYear?.id, activeYear?.start_date, activeYear?.end_date]);

    const year = schoolYearFor(years, cursor);
    const canPickMonth = orderedYears(years).length > 0;
    const span = useMemo(() => visibleSpan(view, cursor, year), [view, cursor, year]);
    const rangeFrom = span.from.format("YYYY-MM-DD");
    const rangeTo = span.to.format("YYYY-MM-DD");

    const { data: events = [], isLoading } = useQuery({
        queryKey: ["calendar-events", rangeFrom, rangeTo],
        queryFn: () =>
            api.get("calendar-events", { params: { from: rangeFrom, to: rangeTo } }).then((response) => response.data),
        placeholderData: (previous) => previous,
    });

    const { data: ledUnits = [] } = useQuery({
        queryKey: ["led-units", user?.id],
        queryFn: () => api.get("org-units/leading").then((response) => response.data),
        enabled: Boolean(user),
    });

    const collegeWide = ["admin", "president"].includes(user?.role);
    const scheduleOnly = ["admin", "president", "vp"].includes(user?.role);
    const onlyMe = user?.role === "employee";
    const { data: people = [] } = useQuery({
        queryKey: ["users-options", collegeWide ? "college" : "led"],
        queryFn: () => api.get("users/options", { params: collegeWide ? {} : { scope: "led" } }).then((response) => response.data),
    });

    const { data: categoryList } = useQuery({
        queryKey: ["attendance-categories"],
        queryFn: () => api.get("attendance-categories").then((response) => response.data),
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["calendar-events"] });
    const openEvent = (event, pile = null) => setDraft(pile ? { event, pile } : { event });
    const openCreate = (day) => {
        setDraft({
            event: null,
            starts_at: day.startOf("day"),
            ends_at: day.startOf("day"),
        });
    };
    const title = view === "month"
        ? cursor.format("MMMM YYYY")
        : view === "year" || view === "schedule"
            ? (year?.label ?? spanTitle(span.from, span.to))
            : spanTitle(span.from, span.to);
    const currentView = VIEWS.find((item) => item.key === view) ?? VIEWS[2];

    const save = useMutation({
        mutationFn: ({ id, ...payload }) => {
            const request = id
                ? api.patch(`calendar-events/${id}`, payload)
                : api.post("calendar-events", payload);
            return request.then((response) => response.data);
        },
        onSuccess: (result, variables) => {
            message.success(variables.id ? "Event updated." : "Event added to the calendar.");
            if (variables.starts_at) setCursor(dayjs(variables.starts_at));
            refresh();
            if (result?.event?.category_id) {
                setDraft({ event: result.event });
                return;
            }
            setDraft(null);
        },
    });

    const remove = useMutation({
        mutationFn: (id) => api.delete(`calendar-events/${id}`),
        onSuccess: () => {
            message.success("Event deleted.");
            setDraft(null);
            refresh();
        },
        onError: (error) => message.error(errorText(error, "Could not delete that event.")),
    });

    return (
        <div className="cal">
            <div className="gs-toolbar">
                <div className="gs-month">
                    <button
                        type="button"
                        aria-label="Previous"
                        disabled={!canStep(view, cursor, year, years, -1)}
                        onClick={() => setCursor(stepCursor(view, cursor, year, years, -1))}
                    >
                        ‹
                    </button>
                    {canPickMonth ? (
                        <DatePicker
                            className="gs-month-picker"
                            picker="month"
                            value={cursor}
                            format={() => title}
                            allowClear={false}
                            inputReadOnly
                            suffixIcon={null}
                            variant="borderless"
                            aria-label="Choose a month"
                            style={{ width: `calc(${Math.max(title.length, 11)}ch + 16px)` }}
                            disabledDate={(date) => !schoolYearTouching(years, date)}
                            onChange={(value) => {
                                if (!value) return;
                                const match = schoolYearTouching(years, value);
                                setCursor(match ? clampToYear(value.startOf("month"), match) : value.startOf("month"));
                                if (view === "year" || view === "schedule") setView("month");
                            }}
                        />
                    ) : (
                        <strong>{title}</strong>
                    )}
                    <button
                        type="button"
                        aria-label="Next"
                        disabled={!canStep(view, cursor, year, years, 1)}
                        onClick={() => setCursor(stepCursor(view, cursor, year, years, 1))}
                    >
                        ›
                    </button>
                </div>
                <button type="button" className="gs-print" onClick={() => setCursor(dayjs())}>Today</button>
                <div className="gs-legend">
                    <span><i className="is-only-me" /> Only me</span>
                    <span><i className="is-selected" /> Selected users</span>
                    <span><i className="is-everyone" /> {collegeWide ? "Everyone" : "All subordinates"}</span>
                    {collegeWide && <span><i className="is-role" /> By role</span>}
                </div>
                <span className="gs-toolbar-gap" />
                <Dropdown
                    trigger={["click"]}
                    menu={{
                        items: VIEWS.map((item) => ({
                            key: item.key,
                            label: item.key === view ? `${item.label} ✓` : item.label,
                        })),
                        onClick: ({ key }) => setView(key),
                    }}
                >
                    <button type="button" className="gs-print cal-view-btn">{currentView.label} ▾</button>
                </Dropdown>
                {!scheduleOnly && !onlyMe && (
                    <button type="button" className="gs-print" onClick={() => setCategoriesOpen(true)}>Categories</button>
                )}
                <button type="button" className="gs-add" onClick={() => openCreate(cursor)}>+ Event</button>
            </div>
            <div className={["day", "week", "4days"].includes(view) ? "cal-scroll is-time" : "cal-scroll"}>
                {isLoading ? (
                    <div className="gs-loading"><Spin /></div>
                ) : view === "year" ? (
                    <YearGrid
                        year={year}
                        events={events}
                        onPickDay={(day) => {
                            const start = year?.start_date ? dayjs(year.start_date) : null;
                            const end = year?.end_date ? dayjs(year.end_date) : null;
                            const next = start && day.isBefore(start, "day")
                                ? start
                                : end && day.isAfter(end, "day")
                                    ? end
                                    : day;
                            setCursor(next);
                            setView("day");
                        }}
                    />
                ) : view === "schedule" ? (
                    <ScheduleList events={events} from={span.from} to={span.to} onOpen={openEvent} />
                ) : view === "month" ? (
                    <MonthGrid cursor={cursor} days={span.days} events={events} onCreate={openCreate} onOpen={openEvent} />
                ) : (
                    <TimeGrid days={span.days} events={events} onCreate={openCreate} onOpen={openEvent} />
                )}
            </div>
            <EventDrawer
                draft={draft}
                people={people.filter((person) => person.id !== user?.id && (collegeWide || (person.role !== "vp" && person.role !== "president")))}
                leads={ledUnits.length > 0}
                collegeWide={collegeWide}
                scheduleOnly={scheduleOnly}
                onlyMe={onlyMe}
                viewerId={user?.id}
                categories={(categoryList?.categories ?? []).filter((item) => item.source === "event")}
                canLink={Boolean(categoryList?.can_link)}
                years={years}
                saving={save.isPending}
                removing={remove.isPending}
                onCancel={() => setDraft(null)}
                onSubmit={(values) => save.mutateAsync(values)}
                onDelete={(id) => remove.mutate(id)}
                onOpen={(event) => setDraft({ event })}
            />
            <CategoryDrawer
                open={categoriesOpen}
                list={categoryList}
                onClose={() => setCategoriesOpen(false)}
            />
        </div>
    );
}

function eventDays(event) {
    if (!event?.starts_at || !event?.ends_at) return [];
    const start = dayjs(event.starts_at).startOf("day");
    const end = dayjs(event.ends_at).startOf("day");
    const days = [];

    for (let cursor = start; !cursor.isAfter(end, "day") && days.length < 45; cursor = cursor.add(1, "day")) {
        days.push(cursor.format("YYYY-MM-DD"));
    }

    return days;
}

function markMenu(current, onPick) {
    return {
        items: [
            ...MARKS.map(([key, label]) => ({
                key,
                label: current === key ? `${label} ✓` : label,
            })),
            { type: "divider" },
            { key: "clear", label: "Clear", disabled: !current },
        ],
        onClick: ({ key }) => onPick(key === "clear" ? null : key),
    };
}

function EventDrawer({ draft, people, leads, collegeWide, scheduleOnly, onlyMe, viewerId, categories, canLink, years, saving, removing, onCancel, onSubmit, onDelete, onOpen }) {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const open = Boolean(draft);
    const event = draft?.event ?? null;
    const pile = draft?.pile ?? null;
    const locked = Boolean(event && !event.mine);

    const { data: detail } = useQuery({
        queryKey: ["calendar-event", event?.id],
        queryFn: () => api.get(`calendar-events/${event.id}`).then((response) => response.data.event),
        enabled: Boolean(open && event?.id),
    });

    const shown = detail ?? event;
    const periodLocked = years.some((year) =>
        (year.periods ?? []).some((period) => period.id === shown?.rating_period_id && period.is_locked)
    );
    const activePeriodId = useMemo(() => {
        const activeYear = years.find((item) => item.is_active) ?? years[0];
        const list = activeYear?.periods ?? [];

        return list.find((period) => period.is_active)?.id ?? list[0]?.id ?? null;
    }, [years]);
    const periods = years.flatMap((item) =>
        (item.periods ?? []).map((period) => ({
            value: period.id,
            label: `${item.label} · ${period.is_active ? `${period.label} ★` : period.label}`,
        }))
    );

    const mark = useMutation({
        mutationFn: (payload) => api.put(`calendar-events/${shown.id}/marks`, payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.setQueryData(["calendar-event", shown.id], result.event);
            queryClient.invalidateQueries({ queryKey: ["calendar-events"] });
            queryClient.invalidateQueries({ queryKey: ["attendance"] });
        },
        onError: (error) => message.error(errorText(error, "Could not save that mark.")),
    });

    useEffect(() => {
        if (!open) return;
        form.resetFields();
        if (event) {
            form.setFieldsValue({
                title: event.title,
                notes: event.notes,
                all_day: event.all_day,
                starts_at: dayjs(event.starts_at),
                ends_at: dayjs(event.ends_at),
                visibility: event.visibility,
                user_ids: (event.viewers ?? []).map((person) => person.id),
                audience_role: event.audience_role ?? null,
                category_id: event.category_id ?? null,
                rating_period_id: event.rating_period_id ?? activePeriodId,
            });
            return;
        }
        form.setFieldsValue({
            all_day: true,
            starts_at: draft.starts_at,
            ends_at: draft.ends_at,
            visibility: "only_me",
            user_ids: [],
            audience_role: null,
            category_id: null,
            rating_period_id: activePeriodId,
        });
    }, [open, event, draft, form, activePeriodId]);

    const submit = async () => {
        try {
            const values = await form.validateFields();
            if (values.ends_at.isBefore(values.starts_at)) {
                form.setFields([{ name: "ends_at", errors: ["The end has to be on or after the start."] }]);
                return;
            }
            if (orderedYears(years).length > 0) {
                const outside = [
                    !schoolYearOn(years, values.starts_at) && { name: "starts_at", errors: ["Pick a date inside a school year."] },
                    !schoolYearOn(years, values.ends_at) && { name: "ends_at", errors: ["Pick a date inside a school year."] },
                ].filter(Boolean);
                if (outside.length) {
                    form.setFields(outside);
                    return;
                }
            }
            const counts = !scheduleOnly && values.visibility !== "only_me" && values.category_id;
            if (counts && values.rating_period_id) {
                const span = spanForPeriod(years, values.rating_period_id);
                const first = values.starts_at.startOf("day");
                const last = values.ends_at.startOf("day");
                if (!span || first.isBefore(span[0], "day") || last.isAfter(span[1], "day")) {
                    form.setFields([{ name: "starts_at", errors: ["The event has to fall inside the review period."] }]);
                    return;
                }
            }
            const keptMarks = (shown?.marks ?? []).filter((mark) => mark.status);
            const losesCategory = Boolean(shown?.category_id) && !counts;
            const losesDays = keptMarks.some((mark) => {
                const day = dayjs(mark.day);
                return day.isBefore(values.starts_at, "day") || day.isAfter(values.ends_at, "day");
            });
            if (keptMarks.length && (losesCategory || losesDays)) {
                const proceed = await new Promise((resolve) => {
                    Modal.confirm({
                        title: "Attendance marks will be removed",
                        content: losesCategory
                            ? "Removing the category clears the marks already saved on this event."
                            : "Marks on days outside the new dates will be removed.",
                        okText: "Save anyway",
                        okButtonProps: { danger: true },
                        onOk: () => resolve(true),
                        onCancel: () => resolve(false),
                    });
                });
                if (!proceed) return;
            }
            await onSubmit({
                ...(event ? { id: event.id } : {}),
                title: values.title,
                notes: values.notes || null,
                all_day: values.all_day,
                starts_at: values.all_day ? values.starts_at.format("YYYY-MM-DD") : values.starts_at.format("YYYY-MM-DD HH:mm:ss"),
                ends_at: values.all_day ? values.ends_at.format("YYYY-MM-DD") : values.ends_at.format("YYYY-MM-DD HH:mm:ss"),
                visibility: onlyMe ? "only_me" : values.visibility,
                user_ids: !onlyMe && values.visibility === "selected" ? values.user_ids : [],
                audience_role: !onlyMe && values.visibility === "role" ? values.audience_role : null,
                category_id: counts ? values.category_id : null,
                rating_period_id: counts ? values.rating_period_id : null,
            });
        } catch (error) {
            if (error?.errorFields) return;
            if (!applyFieldErrors(form, error)) {
                message.error(errorText(error, "Could not save that event."));
            }
        }
    };

    const withAttendance = Boolean(shown?.category_id);
    const reading = Boolean(locked && shown);

    return (
        <Drawer
            title={pile ? "More events" : event ? (locked ? "Event" : "Edit event") : "New event"}
            open={open}
            onClose={onCancel}
            width={withAttendance && !reading ? "min(920px, 100vw)" : 420}
            destroyOnClose
            footer={
                pile ? null : reading ? (
                    <div className="gs-drawer-actions cal-drawer-actions">
                        <span className="gs-toolbar-gap" />
                        <Button onClick={onCancel}>Close</Button>
                    </div>
                ) : (
                    <div className="gs-drawer-actions cal-drawer-actions">
                        {event?.mine && (
                            <Popconfirm
                                title="Delete this event?"
                                okText="Delete"
                                okButtonProps={{ danger: true }}
                                onConfirm={() => onDelete(event.id)}
                            >
                                <Button danger loading={removing}>Delete</Button>
                            </Popconfirm>
                        )}
                        <span className="gs-toolbar-gap" />
                        <Button onClick={onCancel}>Cancel</Button>
                        {!locked && (
                            <Button type="primary" loading={saving} onClick={submit}>
                                {event ? "Save" : "Create"}
                            </Button>
                        )}
                    </div>
                )
            }
        >
            {pile ? (
                <div className="cal-pile">
                    {pile.map((item) => (
                        <button key={item.id} type="button" className="cal-pile-item" onClick={() => onOpen(item)}>
                            <strong>{item.title}</strong>
                            <span>{whenText(item)}</span>
                        </button>
                    ))}
                </div>
            ) : reading ? (
                <EventRead event={shown} viewerId={viewerId} />
            ) : (
                <div className={withAttendance ? "cal-event-split" : "cal-event-single"}>
                    <Form form={form} layout="vertical" requiredMark={false}>
                        {onlyMe && <p className="cal-private">Only you can see this.</p>}
                        <Form.Item name="title" label="Title" rules={[{ required: true, whitespace: true, message: "Name the event." }]}>
                            <Input placeholder="Department meeting" maxLength={200} />
                        </Form.Item>
                        <Form.Item name="all_day" label="All day" valuePropName="checked">
                            <Switch />
                        </Form.Item>
                        <Form.Item noStyle shouldUpdate={(prev, next) => prev.all_day !== next.all_day}>
                            {({ getFieldValue }) => {
                                const allDay = getFieldValue("all_day") !== false;
                                return (
                                    <>
                                        <Form.Item name="starts_at" label="Starts" rules={[{ required: true, message: "Pick a start." }]}>
                                            <DatePicker
                                                style={{ width: "100%" }}
                                                showTime={allDay ? false : { format: "h:mm A" }}
                                                format={allDay ? "MMMM D, YYYY" : "MMMM D, YYYY h:mm A"}
                                                disabledDate={(date) => orderedYears(years).length > 0 && !schoolYearOn(years, date)}
                                            />
                                        </Form.Item>
                                        <Form.Item name="ends_at" label="Ends" rules={[{ required: true, message: "Pick an end." }]}>
                                            <DatePicker
                                                style={{ width: "100%" }}
                                                showTime={allDay ? false : { format: "h:mm A" }}
                                                format={allDay ? "MMMM D, YYYY" : "MMMM D, YYYY h:mm A"}
                                                disabledDate={(date) => orderedYears(years).length > 0 && !schoolYearOn(years, date)}
                                            />
                                        </Form.Item>
                                    </>
                                );
                            }}
                        </Form.Item>
                        {!onlyMe && (
                        <>
                        <Form.Item name="visibility" label="Who can see this" rules={[{ required: true, message: "Choose who can see this." }]}>
                            <Radio.Group className="cal-visibility">
                                {(collegeWide ? COLLEGE_VISIBILITY : VISIBILITY)
                                    .filter((option) => option.value !== "everyone" || collegeWide || leads || event?.visibility === "everyone")
                                    .map((option) => (
                                        <Radio key={option.value} value={option.value}>{option.label}</Radio>
                                    ))}
                            </Radio.Group>
                        </Form.Item>
                        <Form.Item noStyle shouldUpdate={(prev, next) => prev.visibility !== next.visibility}>
                            {({ getFieldValue }) => getFieldValue("visibility") === "selected" && (
                                <Form.Item name="user_ids" label="People" rules={[{ required: true, message: "Choose at least one person." }]}>
                                    <Select
                                        mode="multiple"
                                        showSearch
                                        optionFilterProp="label"
                                        placeholder="Choose people"
                                        options={people.map((person) => ({
                                            value: person.id,
                                            label: person.position_title ? `${person.name} · ${person.position_title}` : person.name,
                                        }))}
                                    />
                                </Form.Item>
                            )}
                        </Form.Item>
                        <Form.Item noStyle shouldUpdate={(prev, next) => prev.visibility !== next.visibility}>
                            {({ getFieldValue }) => getFieldValue("visibility") === "role" && (
                                <Form.Item name="audience_role" label="Role" rules={[{ required: true, message: "Pick a role." }]}>
                                    <Select placeholder="Who this event is for" options={AUDIENCE_ROLES} />
                                </Form.Item>
                            )}
                        </Form.Item>
                        {!scheduleOnly && canLink && (
                        <Form.Item noStyle shouldUpdate={(prev, next) => prev.visibility !== next.visibility || prev.category_id !== next.category_id}>
                            {({ getFieldValue }) => getFieldValue("visibility") !== "only_me" && (
                                <>
                                    <Form.Item name="category_id" label="Attendance category">
                                        <Select
                                            allowClear
                                            placeholder="Schedule only, unless this counts toward a support percentage"
                                            options={categories.map((item) => ({ value: item.id, label: item.name }))}
                                        />
                                    </Form.Item>
                                    {getFieldValue("category_id") && (
                                        <Form.Item name="rating_period_id" label="Review period" rules={[{ required: true, message: "Pick the review period." }]}>
                                            <Select
                                                placeholder="Which review this activity belongs to"
                                                options={periods}
                                            />
                                        </Form.Item>
                                    )}
                                </>
                            )}
                        </Form.Item>
                        )}
                        </>
                        )}
                        <Form.Item name="notes" label="Notes">
                            <Input.TextArea rows={3} maxLength={2000} placeholder="Optional" />
                        </Form.Item>
                    </Form>
                    {withAttendance && (
                        <MarkGrid
                            event={shown}
                            locked={!shown.mine || periodLocked}
                            lockedReason={periodLocked ? "This review period is locked." : ""}
                            saving={mark.isPending}
                            onMark={(payload) => mark.mutate(payload)}
                        />
                    )}
                </div>
            )}
        </Drawer>
    );
}

function EventRead({ event, viewerId }) {
    const mine = (event.marks ?? []).filter((mark) => Number(mark.user_id) === Number(viewerId));
    const several = mine.length > 1;

    return (
        <div className="cal-read">
            <h3>{event.title}</h3>
            <p className="cal-when">{whenText(event)}</p>
            {event.owner && <p className="cal-owner">Scheduled by {event.owner.name}</p>}
            {event.notes && <p className="cal-read-notes">{event.notes}</p>}
            {mine.length > 0 && (
                <ul className="cal-read-marks">
                    {mine.map((mark) => {
                        const named = MARKS.find(([key]) => key === mark.status);

                        return (
                            <li key={mark.day}>
                                <span className={`gs-mark is-${mark.status}`}>{named?.[2]}</span>
                                <span>{named?.[1] ?? mark.status}</span>
                                {several && <span className="cal-read-day">{dayjs(mark.day).format("MMM D")}</span>}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function MarkGrid({ event, locked, lockedReason, saving, onMark }) {
    const days = eventDays(event);
    const roster = event.roster ?? [];
    const marks = event.marks ?? [];

    if (!days.length) return null;

    if (!roster.length) {
        return (
            <div className="cal-marks">
                <strong>Attendance</strong>
                <p>Choose Selected users or All subordinates, then save. The marks show up here.</p>
            </div>
        );
    }

    const statusAt = (userId, day) => marks.find((mark) => mark.user_id === userId && mark.day === day)?.status ?? "";

    return (
        <div className="cal-marks">
            <strong>Attendance</strong>
            <p>{lockedReason || "Present and absent count. Excused stays out of the percentage. A day already marked Leave on the everyday sheet stays Leave."}</p>
            <div className="cal-marks-scroll">
                <table>
                    <thead>
                        <tr>
                            <th className="name">Name</th>
                            {days.map((day) => (
                                <th key={day}>{dayjs(day).format("MMM D")}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {roster.map((person) => (
                            <tr key={person.id}>
                                <td className="name">{person.name}</td>
                                {days.map((day) => {
                                    const status = statusAt(person.id, day);
                                    const letter = MARKS.find(([key]) => key === status)?.[2];

                                    return (
                                        <td key={day}>
                                            <Dropdown
                                                menu={markMenu(status, (next) => onMark({
                                                    user_id: person.id,
                                                    day,
                                                    status: next,
                                                }))}
                                                trigger={["click"]}
                                                disabled={locked || saving}
                                            >
                                                <button
                                                    type="button"
                                                    className="cal-mark-cell"
                                                    aria-label={`${person.name} ${day}`}
                                                    disabled={locked || saving}
                                                >
                                                    {letter
                                                        ? <span className={`gs-mark is-${status}`}>{letter}</span>
                                                        : <span className="gs-caret">▾</span>}
                                                </button>
                                            </Dropdown>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function categoryTaken(categories, label, ignoreId) {
    const needle = label.trim().toLowerCase();

    return categories.some((item) => item.id !== ignoreId && item.name.trim().toLowerCase() === needle);
}

function CategoryDrawer({ open, list, onClose }) {
    const queryClient = useQueryClient();
    const [name, setName] = useState("");
    const [nameError, setNameError] = useState("");
    const [renameError, setRenameError] = useState(null);
    const categories = list?.categories ?? [];
    const canManage = Boolean(list?.can_manage);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["attendance-categories"] });

    const add = useMutation({
        mutationFn: (label) => api.post("attendance-categories", { name: label }).then((response) => response.data),
        onSuccess: () => {
            setName("");
            setNameError("");
            refresh();
        },
        onError: (error) => setNameError(errorText(error, "Could not add that category.")),
    });

    const rename = useMutation({
        mutationFn: ({ id, label }) => api.patch(`attendance-categories/${id}`, { name: label }),
        onSuccess: () => {
            setRenameError(null);
            refresh();
        },
        onError: (error, variables) => setRenameError({
            id: variables.id,
            message: errorText(error, "Could not rename that category."),
        }),
    });

    const remove = useMutation({
        mutationFn: (id) => api.delete(`attendance-categories/${id}`),
        onSuccess: refresh,
        onError: (error) => message.error(errorText(error, "Could not remove that category.")),
    });

    return (
        <Drawer title="Attendance categories" open={open} onClose={onClose} width={420} destroyOnClose>
            <p className="cal-owner">Everyday attendance is built in. Add a category when a support line does not fit the starters, then choose it on the event and on the IPCR.</p>
            <div className="cal-categories">
                {categories.map((item) => (
                    <div key={item.id} className="cal-category">
                        {canManage ? (
                            <Input
                                key={`${item.id}-${item.name}`}
                                defaultValue={item.name}
                                maxLength={120}
                                status={renameError?.id === item.id ? "error" : undefined}
                                onBlur={(blur) => {
                                    const next = blur.target.value.trim();
                                    if (!next) {
                                        blur.target.value = item.name;
                                        setRenameError({ id: item.id, message: "Name the category." });
                                        return;
                                    }
                                    if (categoryTaken(categories, next, item.id)) {
                                        setRenameError({ id: item.id, message: "That category already exists." });
                                        return;
                                    }
                                    setRenameError(null);
                                    if (next !== item.name) rename.mutate({ id: item.id, label: next });
                                }}
                            />
                        ) : (
                            <span>{item.name}</span>
                        )}
                        {item.source === "daily" && <span className="cal-category-note">Daily sheet</span>}
                        {canManage && !item.system && (
                            <Popconfirm
                                title="Remove this category?"
                                okText="Remove"
                                okButtonProps={{ danger: true }}
                                onConfirm={() => remove.mutate(item.id)}
                            >
                                <button type="button">Remove</button>
                            </Popconfirm>
                        )}
                        {renameError?.id === item.id && <span className="cal-field-error">{renameError.message}</span>}
                    </div>
                ))}
            </div>
            {canManage && (
                <div className="cal-category-add">
                    <Input
                        value={name}
                        maxLength={120}
                        status={nameError ? "error" : undefined}
                        placeholder="Accreditation, research forum…"
                        onChange={(change) => {
                            setName(change.target.value);
                            if (nameError) setNameError("");
                        }}
                        onPressEnter={() => {
                            const label = name.trim();
                            if (!label) return;
                            if (categoryTaken(categories, label)) {
                                setNameError("That category already exists.");
                                return;
                            }
                            add.mutate(label);
                        }}
                    />
                    <Button
                        type="primary"
                        loading={add.isPending}
                        disabled={!name.trim()}
                        onClick={() => {
                            const label = name.trim();
                            if (categoryTaken(categories, label)) {
                                setNameError("That category already exists.");
                                return;
                            }
                            add.mutate(label);
                        }}
                    >
                        Add
                    </Button>
                    {nameError && <span className="cal-field-error">{nameError}</span>}
                </div>
            )}
        </Drawer>
    );
}
