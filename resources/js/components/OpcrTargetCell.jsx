import React, { useMemo } from "react";
import { Select, Tag, Tooltip } from "antd";
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

export default function OpcrTargetCell({ line, targets, picking = false, onClose, onPick, children }) {
    const options = useMemo(() => targetOptions(targets), [targets]);
    const picked = isPickedTarget(line);

    return (
        <div className="pms-target-cell">
            {children}

            {line.parent && !picking && (
                <Tooltip title={toPlainText(line.parent.description)}>
                    <Tag bordered={false} color="geekblue" icon={<LinkOutlined />} className="pms-target-tag">
                        Office target
                    </Tag>
                </Tooltip>
            )}

            {picking && (
                <Select
                    size="small"
                    autoFocus
                    defaultOpen
                    showSearch
                    optionFilterProp="label"
                    popupMatchSelectWidth={420}
                    placeholder="Search targets"
                    value={picked ? line.parent_indicator_id : undefined}
                    options={options}
                    className="pms-target-select"
                    onChange={(value) => {
                        onPick(value);
                        onClose();
                    }}
                    onBlur={onClose}
                    onDropdownVisibleChange={(open) => !open && onClose()}
                />
            )}
        </div>
    );
}
