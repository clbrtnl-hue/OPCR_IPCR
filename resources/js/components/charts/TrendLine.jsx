import React, { useMemo, useRef } from "react";
import { Area, Line } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { axisFor } from "~/components/charts/plotTheme";
import { useTheme } from "~/hooks/useTheme";

export default function TrendLine({
    points = [],
    height = 220,
    min = 0,
    max,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
    filled = false,
    smooth = false,
    valueName = "Value",
    onSelect,
}) {
    const { viz } = useTheme();
    const selectRef = useRef(onSelect);
    const picked = useRef(false);
    selectRef.current = onSelect;
    const signature = points.map((point) => `${point.label}:${point.value}`).join("|");
    const data = points.map((point) => ({
        label: point.label,
        hint: point.hint ?? point.label,
        value: Number(point.value ?? 0),
    }));

    const config = useMemo(() => {
        const curve = smooth ? { shape: "smooth" } : {};
        const shared = {
            data,
            xField: "label",
            yField: "value",
            height,
            autoFit: true,
            legend: false,
            axis: axisFor(viz),
            scale: {
                y: {
                    domainMin: min,
                    ...(max != null ? { domainMax: max } : { nice: true }),
                },
            },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: valueName, value: format(datum.value) })],
            },
        };

        if (filled) {
            return {
                ...shared,
                ...curve,
                style: { fill: color, fillOpacity: 0.22 },
                line: { ...curve, style: { stroke: color, lineWidth: 2 } },
                point: { size: 4, style: { fill: color, stroke: "#ffffff", lineWidth: 1 } },
            };
        }

        return {
            ...shared,
            ...curve,
            style: { stroke: color, lineWidth: 2 },
            point: { size: 3, style: { fill: color, stroke: "#ffffff", lineWidth: 1 } },
            area: { ...curve, style: { fill: color, fillOpacity: 0.16 } },
        };
    }, [signature, height, min, max, color, filled, smooth, valueName, format, Boolean(onSelect), viz]);

    if (!points.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const onEvent = (chart, event) => {
        if (event?.type !== "element:click" || !selectRef.current) return;

        const datum = event.data?.data ?? event.data;

        if (!datum) return;

        picked.current = true;
        selectRef.current(datum);
    };

    const plot = filled ? (
        <Area {...config} {...(onSelect ? { onEvent } : {})} />
    ) : (
        <Line {...config} {...(onSelect ? { onEvent } : {})} />
    );

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
            {plot}
        </div>
    );
}
