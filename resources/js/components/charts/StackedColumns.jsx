import React, { useMemo } from "react";
import { Column } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { plotAxis } from "~/components/charts/plotTheme";

export default function StackedColumns({ rows = [], series = [], height = 260, emptyText = "Nothing to show yet." }) {
    const flat = rows.flatMap((row) =>
        series.map((item) => ({
            unit: row.label,
            stage: item.label,
            value: Number(row.values?.[item.key] ?? 0),
        }))
    );
    const signature = flat.map((row) => `${row.unit}:${row.stage}:${row.value}`).join("|");

    const config = useMemo(
        () => ({
            data: flat,
            xField: "unit",
            yField: "value",
            colorField: "stage",
            stack: true,
            height,
            autoFit: true,
            axis: plotAxis,
            legend: {
                color: {
                    position: "top",
                    itemMarker: "square",
                    itemLabelFill: VIZ.inkSoft,
                    layout: { justifyContent: "flex-start" },
                },
            },
            scale: {
                color: {
                    domain: series.map((item) => item.label),
                    range: series.map((item) => item.color),
                },
                y: { domainMin: 0, nice: true },
            },
            style: {
                radiusTopLeft: 3,
                radiusTopRight: 3,
                maxWidth: 28,
            },
            tooltip: {
                title: (datum) => datum.unit,
                items: [(datum) => ({ name: datum.stage, value: datum.value })],
            },
        }),
        [signature, height]
    );

    if (!rows.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return <Column {...config} />;
}
