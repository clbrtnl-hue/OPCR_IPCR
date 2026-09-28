import React, { useMemo } from "react";
import { Radar } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { plotAxis } from "~/components/charts/plotTheme";

export default function RadarChart({
    data = [],
    height = 250,
    min = 0,
    max = 5,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
}) {
    const signature = data.map((row) => `${row.key}:${row.value}`).join("|");

    const config = useMemo(
        () => ({
            data: data.map((row) => ({
                label: row.label,
                value: Number(row.value ?? 0),
                hint: row.hint,
            })),
            xField: "label",
            yField: "value",
            height,
            autoFit: true,
            legend: false,
            style: { stroke: color, lineWidth: 2 },
            area: { style: { fill: color, fillOpacity: 0.16 } },
            point: { style: { fill: color, stroke: "#ffffff", lineWidth: 2 }, size: 3 },
            scale: {
                y: { domainMin: min, domainMax: max, tickCount: Math.min(max, 5) },
            },
            axis: {
                x: { ...plotAxis.x, gridStroke: VIZ.grid },
                y: { ...plotAxis.y, labelFill: VIZ.muted },
            },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: datum.label, value: format(datum.value) })],
            },
        }),
        [signature, height, min, max, color, format]
    );

    if (data.length < 3) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return (
        <div>
            <Radar {...config} />
            <div className="viz-radar-caption">
                {data.map((row) => (
                    <span key={row.key} title={row.hint ?? row.label}>
                        <strong>{format(row.value)}</strong>
                        {row.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
