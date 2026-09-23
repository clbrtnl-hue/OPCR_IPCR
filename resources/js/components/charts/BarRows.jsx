import React from "react";
import { Empty, Tooltip } from "antd";
import { VIZ } from "~/utils/constants";

export default function BarRows({
    data = [],
    max,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
}) {
    if (!data.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const ceiling = max ?? Math.max(1, ...data.map((row) => Number(row.value ?? 0)));

    return (
        <div className="viz-bars">
            {data.map((row) => {
                const value = Number(row.value ?? 0);
                const width = ceiling > 0 ? (value / ceiling) * 100 : 0;

                return (
                    <Tooltip key={row.key} title={row.hint ?? `${row.label} — ${format(value)}`}>
                        <div className="viz-bar-row">
                            <span className="viz-bar-label" title={row.label}>
                                {row.label}
                            </span>
                            <span className="viz-bar-track">
                                <span
                                    className="viz-bar-fill"
                                    style={{
                                        width: `${value > 0 ? Math.max(width, 1.5) : 0}%`,
                                        background: row.color ?? color,
                                    }}
                                />
                            </span>
                            <span className="viz-bar-value">{format(value)}</span>
                        </div>
                    </Tooltip>
                );
            })}
        </div>
    );
}
