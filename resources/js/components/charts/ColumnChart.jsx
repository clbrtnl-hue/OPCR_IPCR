import React, { useMemo } from "react";
import { Column } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { colorScale, plotAxis } from "~/components/charts/plotTheme";

export default function ColumnChart({
    data = [],
    height = 220,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
}) {
    const signature = data.map((row) => `${row.key ?? row.label}:${row.value}:${row.color}`).join("|");
    const rows = data.map((row) => ({
        ...row,
        value: Number(row.value ?? 0),
        color: row.color ?? color,
    }));

    const config = useMemo(
        () => ({
            data: rows,
            xField: "label",
            yField: "value",
            colorField: "label",
            height,
            autoFit: true,
            legend: false,
            axis: plotAxis,
            scale: {
                color: colorScale(rows),
                y: { domainMin: 0, nice: true },
            },
            style: {
                radiusTopLeft: 4,
                radiusTopRight: 4,
                maxWidth: 28,
            },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: "Filed", value: format(datum.value) })],
            },
        }),
        [signature, height, format]
    );

    if (!data.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return <Column {...config} />;
}
