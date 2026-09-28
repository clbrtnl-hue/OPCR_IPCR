import React, { useMemo, useRef } from "react";
import { Pie } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { colorScale } from "~/components/charts/plotTheme";

export default function Donut({
    data = [],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
}) {
    const formatRef = useRef(format);
    formatRef.current = format;
    const slices = data.filter((row) => Number(row.value) > 0);
    const signature = data.map((row) => `${row.key}:${row.value}:${row.color}`).join("|");

    const config = useMemo(() => {
        const total = slices.reduce((sum, row) => sum + Number(row.value), 0);

        return {
            data: slices.map((row) => ({
                ...row,
                value: Number(row.value),
            })),
            angleField: "value",
            colorField: "label",
            radius: 0.9,
            innerRadius: 0.68,
            height: 210,
            autoFit: true,
            legend: false,
            label: false,
            scale: { color: colorScale(slices) },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: "Count", value: formatRef.current(datum.value) })],
            },
            annotations: [
                {
                    type: "text",
                    style: {
                        text: String(formatRef.current(total)),
                        x: "50%",
                        y: "46%",
                        textAlign: "center",
                        fontSize: 22,
                        fontWeight: 600,
                        fill: VIZ.ink,
                    },
                },
                {
                    type: "text",
                    style: {
                        text: "total",
                        x: "50%",
                        y: "58%",
                        textAlign: "center",
                        fontSize: 11,
                        fill: VIZ.muted,
                    },
                },
            ],
        };
    }, [signature]);

    if (!slices.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return (
        <div className="viz-donut">
            <Pie {...config} />
            <div className="viz-legend viz-donut-legend">
                {data.map((row, index) => {
                    const value = Number(row.value ?? 0);

                    return (
                        <span key={row.key} className={value > 0 ? "viz-legend-item" : "viz-legend-item is-zero"}>
                            <span
                                className="viz-swatch"
                                style={{ background: row.color ?? VIZ.ordinal[index % VIZ.ordinal.length] }}
                            />
                            <span className="viz-legend-label">{row.label}</span>
                            <strong>{format(value)}</strong>
                        </span>
                    );
                })}
            </div>
        </div>
    );
}
