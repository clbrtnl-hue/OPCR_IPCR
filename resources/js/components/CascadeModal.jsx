import React from "react";
import { Button, Checkbox, Drawer, Empty, Input, Tag, Typography, message } from "antd";
import { CheckOutlined, SearchOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { toPlainText } from "~/components/RichTextView";
import { SECTION_LABELS } from "~/utils/constants";
import UserAvatar from "~/components/UserAvatar";

const SECTIONS = ["strategic", "core", "support"];

export default function CascadeModal({ form, periodId, open, onClose }) {
    const queryClient = useQueryClient();
    const [picked, setPicked] = React.useState([]);
    const [lines, setLines] = React.useState([]);
    const [personQuery, setPersonQuery] = React.useState("");
    const [lineQuery, setLineQuery] = React.useState("");

    const { data: people = [] } = useQuery({
        queryKey: ["assignable-users"],
        queryFn: () => api.get("assignable-users").then((r) => r.data),
        enabled: open,
        staleTime: 5 * 60 * 1000,
    });

    const groups = React.useMemo(
        () =>
            SECTIONS.map((section) => ({
                section,
                outputs: (form.outputs ?? [])
                    .filter((output) => output.section === section)
                    .map((output) => ({
                        ...output,
                        rows: (output.indicators ?? []).filter(
                            (line) => !line.rating_period_id || line.rating_period_id === periodId
                        ),
                    }))
                    .filter((output) => output.rows.length > 0),
            })).filter((group) => group.outputs.length > 0),
        [form.outputs, periodId]
    );

    const everyLine = React.useMemo(
        () => groups.flatMap((g) => g.outputs.flatMap((o) => o.rows.map((r) => r.id))),
        [groups]
    );

    React.useEffect(() => {
        if (!open) return;

        setLines([]);
        setPicked([]);
        setPersonQuery("");
        setLineQuery("");
    }, [open]);

    const cascade = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/cascade`, {
                user_ids: picked,
                indicator_ids: lines,
                rating_period_id: periodId ?? undefined,
            }),
        onSuccess: ({ data }) => {
            message.success(
                data.assigned === 0
                    ? "Everyone picked already holds those lines."
                    : `${data.assigned} commitment${data.assigned === 1 ? "" : "s"} handed out across ${
                          data.people
                      } ${data.people === 1 ? "person" : "people"}${
                          data.skipped ? `, ${data.skipped} already out` : ""
                      }.`
            );
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
    });

    const togglePerson = (id) => {
        setPicked((current) =>
            current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
        );
    };

    const toggleOutput = (rows, checked) => {
        const ids = rows.map((r) => r.id);

        setLines((current) =>
            checked
                ? Array.from(new Set([...current, ...ids]))
                : current.filter((id) => !ids.includes(id))
        );
    };

    const personNeedle = personQuery.trim().toLowerCase();
    const shownPeople = people.filter((person) => {
        const hay = `${person.name} ${person.position_title ?? ""}`.toLowerCase();

        return hay.includes(personNeedle);
    });

    const lineNeedle = lineQuery.trim().toLowerCase();
    const visibleGroups = groups
        .map((group) => ({
            ...group,
            outputs: group.outputs
                .map((output) => {
                    const title = (output.title ?? "").toLowerCase();
                    const rows = lineNeedle
                        ? output.rows.filter(
                              (row) =>
                                  title.includes(lineNeedle) ||
                                  toPlainText(row.description).toLowerCase().includes(lineNeedle)
                          )
                        : output.rows;

                    return { ...output, visible: rows };
                })
                .filter((output) => output.visible.length > 0),
        }))
        .filter((group) => group.outputs.length > 0);

    const ready = picked.length > 0 && lines.length > 0;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            placement="right"
            width={480}
            className="pms-cascade-drawer"
            title="Hand these out"
            footer={
                <div className="pms-cascade-foot">
                    <div>
                        <strong>
                            {lines.length === 0
                                ? "No targets selected"
                                : `${lines.length} target${lines.length === 1 ? "" : "s"}`}
                        </strong>
                        <span>
                            {picked.length === 0
                                ? "Choose who receives them"
                                : `for ${picked.length} ${picked.length === 1 ? "person" : "people"}`}
                        </span>
                    </div>
                    <div className="pms-cascade-foot-actions">
                        <Button onClick={onClose}>Cancel</Button>
                        <Button
                            type="primary"
                            disabled={!ready}
                            loading={cascade.isPending}
                            onClick={() => cascade.mutate()}
                        >
                            Hand out
                        </Button>
                    </div>
                </div>
            }
        >
            <p className="pms-cascade-lead">
                Tick the people and the targets. Each person writes their own wording on their IPCR.
                Anything they already hold is left alone.
            </p>

            <span className="pms-cascade-label">Who</span>
            <Input
                allowClear
                prefix={<SearchOutlined />}
                placeholder="Search people"
                value={personQuery}
                onChange={(event) => setPersonQuery(event.target.value)}
            />
            {people.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="No one on your team can receive a target yet."
                    style={{ margin: "16px 0" }}
                />
            ) : shownPeople.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="No one matches that search."
                    style={{ margin: "16px 0" }}
                />
            ) : (
                <div className="pms-cascade-people">
                    {shownPeople.map((person) => {
                        const on = picked.includes(person.id);

                        return (
                            <button
                                key={person.id}
                                type="button"
                                className={on ? "pms-cascade-person is-on" : "pms-cascade-person"}
                                onClick={() => togglePerson(person.id)}
                            >
                                <UserAvatar user={person} size={36} showTooltip={false} />
                                <span className="pms-cascade-person-text">
                                    <strong>{person.name}</strong>
                                    {person.position_title && <span>{person.position_title}</span>}
                                </span>
                                {on && <CheckOutlined className="pms-cascade-check" />}
                            </button>
                        );
                    })}
                </div>
            )}

            <span className="pms-cascade-label">
                Targets
                <Typography.Text type="secondary">
                    {lines.length} of {everyLine.length}
                </Typography.Text>
            </span>
            <Input
                allowClear
                prefix={<SearchOutlined />}
                placeholder="Search targets"
                value={lineQuery}
                onChange={(event) => setLineQuery(event.target.value)}
                style={{ marginBottom: 10 }}
            />

            {groups.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="This form has no success indicators to hand out yet."
                />
            ) : visibleGroups.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No target matches that search." />
            ) : (
                visibleGroups.map((group) => (
                    <div key={group.section} className="pms-cascade-group">
                        <Tag color="blue">{SECTION_LABELS[group.section] ?? group.section}</Tag>
                        {group.outputs.map((output) => {
                            const ids = output.visible.map((row) => row.id);
                            const on = ids.filter((id) => lines.includes(id));

                            return (
                                <div key={output.id} className="pms-cascade-output">
                                    <Checkbox
                                        checked={on.length === ids.length && ids.length > 0}
                                        indeterminate={on.length > 0 && on.length < ids.length}
                                        onChange={(event) => toggleOutput(output.visible, event.target.checked)}
                                    >
                                        <Typography.Text strong>
                                            {output.outline_number
                                                ? `${output.outline_number}. ${output.title}`
                                                : output.title}
                                        </Typography.Text>
                                    </Checkbox>
                                    <div className="pms-cascade-lines">
                                        {output.visible.map((row) => (
                                            <Checkbox
                                                key={row.id}
                                                checked={lines.includes(row.id)}
                                                onChange={(event) =>
                                                    setLines((current) =>
                                                        event.target.checked
                                                            ? [...current, row.id]
                                                            : current.filter((id) => id !== row.id)
                                                    )
                                                }
                                            >
                                                {toPlainText(row.description)}
                                            </Checkbox>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                ))
            )}
        </Drawer>
    );
}
