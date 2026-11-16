import { VIZ } from "~/utils/constants";

export function axisFor(viz = VIZ) {
    return {
        x: {
            title: false,
            labelFill: viz.muted,
            labelFontSize: 11,
            lineStroke: viz.axis,
            tickStroke: viz.axis,
        },
        y: {
            title: false,
            labelFill: viz.muted,
            labelFontSize: 11,
            gridStroke: viz.grid,
            gridLineWidth: 1,
        },
    };
}

export const plotAxis = axisFor();

export function colorScale(rows, label = "label", color = "color") {
    return {
        domain: rows.map((row) => row[label]),
        range: rows.map((row, index) => row[color] ?? VIZ.series[index % VIZ.series.length]),
    };
}
