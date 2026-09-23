import React from "react";
import { Select } from "antd";
import {
    CheckCircleFilled,
    MinusCircleOutlined,
    PauseCircleFilled,
    SyncOutlined,
} from "@ant-design/icons";
import { PROGRESS_META, PROGRESS_TONES } from "~/utils/constants";

const ICONS = {
    not_started: MinusCircleOutlined,
    ongoing: SyncOutlined,
    completed: CheckCircleFilled,
    deferred: PauseCircleFilled,
};

const TONES = {
    not_started: PROGRESS_TONES.not_started,
    ongoing: PROGRESS_TONES.nearly,
    completed: PROGRESS_TONES.completed,
    deferred: PROGRESS_TONES.deferred,
};

export function progressStatusTone(status) {
    return TONES[status] ?? TONES.not_started;
}

function Face({ status }) {
    const Icon = ICONS[status] ?? ICONS.not_started;

    return (
        <span className="pms-status-face">
            <Icon style={{ color: progressStatusTone(status) }} />
            <span>{PROGRESS_META[status]?.label ?? status}</span>
        </span>
    );
}

export default function ProgressStatusSelect({
    value = "not_started",
    onChange,
    size = "small",
    disabled = false,
    style,
}) {
    return (
        <Select
            size={size}
            disabled={disabled}
            value={value}
            onChange={onChange}
            className="pms-status-select"
            style={{ width: 160, ...style }}
            popupMatchSelectWidth={190}
            labelRender={({ value: current }) => <Face status={current} />}
            optionRender={(option) => <Face status={option.value} />}
            options={Object.entries(PROGRESS_META).map(([key, meta]) => ({
                value: key,
                label: meta.label,
            }))}
        />
    );
}
