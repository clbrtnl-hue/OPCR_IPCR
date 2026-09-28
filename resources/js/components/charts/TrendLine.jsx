import React, { useMemo } from "react";
import { Area, Line } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { plotAxis } from "~/components/charts/plotTheme";

export default function TrendLine({
    points = [],
    height = 220,
    min = 0,
    max,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
    filled = false,
}) {
    const signature = points.map((point) => `${point.label}:${point.value}`).join("|");
    const data = points.map((point) => ({
        label: point.label,
        hint: point.hint ?? point.label,
        value: Number(point.value ?? 0),
    }));

    const config = useMemo(() => {
        const shared = {
            data,
            xField: "label",
            yField: "value",
            height,
            autoFit: true,
            legend: false,
            axis: plotAxis,
            scale: {
                y: {
                    domainMin: min,
                    ...(max != null ? { domainMax: max } : { nice: true }),
                },
            },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: "Value", value: format(datum.value) })],
            },
        };

        if (filled) {
            return {
                ...shared,
                style: { fill: color, fillOpacity: 0.22 },
                line: { style: { stroke: color, lineWidth: 2 } },
                point: { size: 3, style: { fill: color, stroke: "#ffffff", lineWidth: 1 } },
            };
        }

        return {
            ...shared,
            style: { stroke: color, lineWidth: 2 },
            point: { size: 3, style: { fill: color, stroke: "#ffffff", lineWidth: 1 } },
            area: { style: { fill: color, fillOpacity: 0.16 } },
        };
    }, [signature, height, min, max, color, filled, format]);

    if (!points.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return filled ? <Area {...config} /> : <Line {...config} />;
}
