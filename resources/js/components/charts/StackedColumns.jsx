import React, { useMemo, useRef } from "react";
import { Column } from "@ant-design/charts";
import { Empty } from "antd";
import { axisFor } from "~/components/charts/plotTheme";
import { useTheme } from "~/hooks/useTheme";

export default function StackedColumns({
    rows = [],
    series = [],
    height = 260,
    emptyText = "Nothing to show yet.",
    onSelect,
}) {
    const { viz } = useTheme();
    const selectRef = useRef(onSelect);
    selectRef.current = onSelect;
    const flat = rows.flatMap((row) =>
        series.map((item) => ({
            unit: row.label,
            stage: item.label,
            value: Number(row.values?.[item.key] ?? 0),
            href: row.href,
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
            axis: axisFor(viz),
            legend: {
                color: {
                    position: "top",
                    itemMarker: "square",
                    itemLabelFill: viz.inkSoft,
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
                cursor: onSelect ? "pointer" : "default",
            },
            tooltip: {
                title: (datum) => datum.unit,
                items: [(datum) => ({ name: datum.stage, value: datum.value })],
            },
        }),
        [signature, height, Boolean(onSelect), viz]
    );

    if (!rows.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const picked = useRef(false);

    const onEvent = (chart, event) => {
        if (event?.type !== "element:click" || !selectRef.current) return;

        const datum = event.data?.data ?? event.data;

        if (!datum) return;

        picked.current = true;
        selectRef.current(datum);
    };

    return (
        <div
            className={onSelect ? "viz-plot-click" : undefined}
            onClick={
                onSelect
                    ? (event) => {
                          event.stopPropagation();
                          if (picked.current) {
                              picked.current = false;
                              return;
                          }
                          onSelect();
                      }
                    : undefined
            }
        >
            <Column {...config} {...(onSelect ? { onEvent } : {})} />
        </div>
    );
}
