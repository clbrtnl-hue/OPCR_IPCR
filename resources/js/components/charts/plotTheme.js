import { VIZ } from "~/utils/constants";

export const plotAxis = {
    x: {
        title: false,
        labelFill: VIZ.muted,
        labelFontSize: 11,
        lineStroke: VIZ.axis,
        tickStroke: VIZ.axis,
    },
    y: {
        title: false,
        labelFill: VIZ.muted,
        labelFontSize: 11,
        gridStroke: VIZ.grid,
        gridLineWidth: 1,
    },
};

export function colorScale(rows, label = "label", color = "color") {
    return {
        domain: rows.map((row) => row[label]),
        range: rows.map((row, index) => row[color] ?? VIZ.series[index % VIZ.series.length]),
    };
}
