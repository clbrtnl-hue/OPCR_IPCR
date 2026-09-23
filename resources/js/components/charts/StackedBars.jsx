import React from "react";
import { Empty, Tooltip } from "antd";

export default function StackedBars({ rows = [], series = [], emptyText = "Nothing to show yet." }) {
    if (!rows.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const ceiling = Math.max(1, ...rows.map((row) => series.reduce((sum, s) => sum + Number(row.values[s.key] ?? 0), 0)));

    return (
        <div className="viz-stack">
            <div className="viz-legend">
                {series.map((s) => (
                    <span key={s.key} className="viz-legend-item">
                        <span className="viz-swatch" style={{ background: s.color }} />
                        {s.label}
                    </span>
                ))}
            </div>

            {rows.map((row) => {
                const total = series.reduce((sum, s) => sum + Number(row.values[s.key] ?? 0), 0);

                return (
                    <div key={row.key} className="viz-bar-row">
                        <span className="viz-bar-label" title={row.label}>
                            {row.label}
                        </span>
                        <span className="viz-bar-track">
                            {series.map((s) => {
                                const value = Number(row.values[s.key] ?? 0);

                                if (value <= 0) return null;

                                return (
                                    <Tooltip key={s.key} title={`${row.label} — ${value} ${s.label.toLowerCase()}`}>
                                        <span
                                            className="viz-bar-fill viz-bar-segment"
                                            style={{
                                                width: `${(value / ceiling) * 100}%`,
                                                background: s.color,
                                            }}
                                        />
                                    </Tooltip>
                                );
                            })}
                        </span>
                        <span className="viz-bar-value">{total}</span>
                    </div>
                );
            })}
        </div>
    );
}
