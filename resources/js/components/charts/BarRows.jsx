import React from "react";
import { Empty, Tooltip } from "antd";
import { VIZ } from "~/utils/constants";

export default function BarRows({
    data = [],
    max,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
    onSelect,
}) {
    if (!data.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const ceiling = max ?? Math.max(1, ...data.map((row) => Number(row.value ?? 0)));
    const ranked = data.some((row) => row.rank != null);

    return (
        <div className={ranked ? "viz-bars is-ranked" : "viz-bars"}>
            {data.map((row) => {
                const value = Number(row.value ?? 0);
                const width = ceiling > 0 ? (value / ceiling) * 100 : 0;

                const Item = onSelect ? "button" : "div";

                return (
                    <Tooltip key={row.key} title={row.hint ?? `${row.label} — ${format(value)}`}>
                        <Item
                            type={onSelect ? "button" : undefined}
                            className="viz-bar-row"
                            onClick={
                                onSelect
                                    ? (event) => {
                                          event.stopPropagation();
                                          onSelect(row);
                                      }
                                    : undefined
                            }
                        >
                            {row.rank != null && (
                                <span className={row.rank <= 3 ? `viz-rank is-${row.rank}` : "viz-rank"}>{row.rank}</span>
                            )}
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
                        </Item>
                    </Tooltip>
                );
            })}
        </div>
    );
}
