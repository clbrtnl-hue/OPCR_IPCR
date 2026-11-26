import React from "react";
import { Button, Popover } from "antd";
import { DownOutlined, FilterOutlined } from "@ant-design/icons";

export function FilterField({ label, children }) {
    return (
        <label className="pms-report-kind-row">
            <span>{label}</span>
            {children}
        </label>
    );
}

export default function FilterMenu({ active = 0, onClear, children }) {
    return (
        <Popover
            trigger="click"
            placement="bottomRight"
            content={
                <div className="pms-report-kind-panel">
                    {children}
                    {active > 0 && onClear ? (
                        <Button type="link" size="small" className="pms-report-kind-clear" onClick={onClear}>
                            Clear filters
                        </Button>
                    ) : null}
                </div>
            }
        >
            <Button icon={<FilterOutlined />}>
                Filters
                {active > 0 ? <em className="pms-report-kind-count">{active}</em> : null}
                <DownOutlined />
            </Button>
        </Popover>
    );
}
