import React, { useMemo, useRef } from "react";
import { Column } from "@ant-design/charts";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";
import { axisFor, colorScale } from "~/components/charts/plotTheme";
import { useTheme } from "~/hooks/useTheme";

export default function ColumnChart({
    data = [],
    height = 220,
    color = VIZ.series[0],
    max,
    maxWidth = 28,
    showValues = false,
    valueName = "Filed",
    format = (value) => value,
    emptyText = "Nothing to show yet.",
    onSelect,
}) {
    const { viz } = useTheme();
    const selectRef = useRef(onSelect);
    const picked = useRef(false);
    selectRef.current = onSelect;
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
            axis: axisFor(viz),
            scale: {
                color: colorScale(rows),
                y: { domainMin: 0, ...(max != null ? { domainMax: max } : { nice: true }) },
            },
            style: {
                radiusTopLeft: 4,
                radiusTopRight: 4,
                maxWidth,
                cursor: onSelect ? "pointer" : "default",
            },
            ...(showValues
                ? {
                      label: {
                          text: (datum) => `${format(datum.value)}`,
                          fill: viz.ink ?? "#1f2a37",
                          fontSize: 13,
                          fontWeight: 700,
                      },
                  }
                : {}),
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: valueName, value: format(datum.value) })],
            },
        }),
        [signature, height, max, maxWidth, showValues, valueName, format, Boolean(onSelect), viz]
    );

    if (!data.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

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
