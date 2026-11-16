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
    format = (value) => value,
    emptyText = "Nothing to show yet.",
    onSelect,
}) {
    const { viz } = useTheme();
    const selectRef = useRef(onSelect);
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
                y: { domainMin: 0, nice: true },
            },
            style: {
                radiusTopLeft: 4,
                radiusTopRight: 4,
                maxWidth: 28,
                cursor: onSelect ? "pointer" : "default",
            },
            tooltip: {
                title: (datum) => datum.hint ?? datum.label,
                items: [(datum) => ({ name: "Filed", value: format(datum.value) })],
            },
        }),
        [signature, height, format, Boolean(onSelect), viz]
    );

    if (!data.length) {
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
