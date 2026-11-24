import React, { forwardRef, useEffect, useMemo, useState } from "react";
import { Button, Dropdown, Input, Tag, Tooltip } from "antd";
import { LinkOutlined } from "@ant-design/icons";
import { toPlainText } from "~/components/RichTextView";

function targetOptions(targets) {
    const assigned = targets.filter((t) => t.assigned);
    const rest = targets.filter((t) => !t.assigned);
    const groups = [];

    if (assigned.length) {
        groups.push({
            label: "Assigned to you",
            options: assigned.map((t) => ({
                value: t.id,
                label: `${t.output_title} — ${toPlainText(t.description)}`,
            })),
        });
    }

    if (rest.length) {
        const byOutput = rest.reduce((map, t) => {
            (map[t.output_title] = map[t.output_title] ?? []).push(t);
            return map;
        }, {});

        Object.entries(byOutput).forEach(([label, items]) => {
            groups.push({
                label: assigned.length ? label : label,
                options: items.map((t) => ({ value: t.id, label: toPlainText(t.description) })),
            });
        });
    }

    if (groups.length === 1 && !assigned.length) {
        return groups[0].options;
    }

    return groups.length ? groups : targets.map((t) => ({ value: t.id, label: toPlainText(t.description) }));
}

export function isPickedTarget(line) {
    return Boolean(line.parent_indicator_id) && !line.assigned_by;
}

function menuItems(options, onPick) {
    const item = (option) => ({
        key: String(option.value),
        label: option.label,
        onClick: () => onPick(option.value),
    });

    if (options[0]?.options) {
        return options.map((group, index) => ({
            type: "group",
            key: `group-${index}`,
            label: group.label,
            children: group.options.map(item),
        }));
    }

    return options.map(item);
}

function matches(option, query) {
    return option.label.toLowerCase().includes(query);
}

export function TargetPicker({ open, targets, onOpenChange, onPick, children }) {
    const [query, setQuery] = useState("");
    const options = useMemo(() => targetOptions(targets), [targets]);
    const shown = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) return options;

        if (options[0]?.options) {
            return options
                .map((group) => ({
                    ...group,
                    options: group.options.filter((option) => matches(option, term)),
                }))
                .filter((group) => group.options.length);
        }

        return options.filter((option) => matches(option, term));
    }, [options, query]);

    useEffect(() => {
        if (!open) setQuery("");
    }, [open]);

    return (
        <Dropdown
            open={open}
            trigger={["click"]}
            placement="bottomLeft"
            getPopupContainer={() => document.body}
            onOpenChange={onOpenChange}
            dropdownRender={() => (
                <div
                    className="pms-target-menu"
                    onMouseDown={(event) => {
                        if (event.target.closest("input, button, .ant-input-clear-icon, .ant-input-affix-wrapper")) {
                            return;
                        }
                        event.preventDefault();
                    }}
                >
                    <Input
                        autoFocus
                        allowClear
                        size="small"
                        placeholder="Search targets"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={(event) => event.stopPropagation()}
                    />
                    {shown.length === 0 ? (
                        <div className="pms-target-menu-empty">No matching targets</div>
                    ) : (
                        <ul className="pms-target-menu-list">
                            {menuItems(shown, onPick).map((entry) =>
                                entry.type === "group" ? (
                                    <li key={entry.key}>
                                        <div className="pms-target-menu-group">{entry.label}</div>
                                        <ul>
                                            {entry.children.map((choice) => (
                                                <li key={choice.key}>
                                                    <button
                                                        type="button"
                                                        onMouseDown={(event) => {
                                                            event.preventDefault();
                                                            choice.onClick();
                                                        }}
                                                    >
                                                        {choice.label}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    </li>
                                ) : (
                                    <li key={entry.key}>
                                        <button
                                            type="button"
                                            onMouseDown={(event) => {
                                                event.preventDefault();
                                                entry.onClick();
                                            }}
                                        >
                                            {entry.label}
                                        </button>
                                    </li>
                                )
                            )}
                        </ul>
                    )}
                </div>
            )}
        >
            {children}
        </Dropdown>
    );
}

export const TargetLink = forwardRef(function TargetLink({ title, ...props }, ref) {
    return (
        <Tooltip title={title}>
            <Button ref={ref} size="small" type="text" {...props} />
        </Tooltip>
    );
});

export default function OpcrTargetCell({ line, linkLabel, children }) {
    return (
        <div className="pms-target-cell">
            {children}

            {(linkLabel || line.parent) && (
                <Tooltip title={line.parent ? toPlainText(line.parent.description) : undefined}>
                    <Tag
                        bordered={false}
                        color={linkLabel ? "purple" : "geekblue"}
                        icon={<LinkOutlined />}
                        className="pms-target-tag"
                    >
                        {linkLabel || "Office target"}
                    </Tag>
                </Tooltip>
            )}
        </div>
    );
}
