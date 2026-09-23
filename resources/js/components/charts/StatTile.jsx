import React from "react";
import { Card } from "antd";
import { VIZ } from "~/utils/constants";

export function Meter({ pct, color = VIZ.series[0], label, value }) {
    const width = Math.max(0, Math.min(100, Number(pct ?? 0)));

    return (
        <div className="viz-meter">
            {(label || value) && (
                <div className="viz-meter-head">
                    <span>{label}</span>
                    <strong>{value}</strong>
                </div>
            )}
            <span className="viz-bar-track">
                <span className="viz-bar-fill" style={{ width: `${width}%`, background: color }} />
            </span>
        </div>
    );
}

export default function StatTile({ label, value, suffix, hint, tone, meter, onClick }) {
    return (
        <Card className={onClick ? "viz-tile viz-tile-link" : "viz-tile"} onClick={onClick}>
            <div className="viz-tile-label">{label}</div>
            <div className="viz-tile-value" style={tone ? { color: tone } : undefined}>
                {value}
                {suffix != null && <span className="viz-tile-suffix">{suffix}</span>}
            </div>
            {meter && <Meter pct={meter.pct} color={meter.color ?? tone ?? VIZ.series[0]} />}
            {hint && <div className="viz-tile-hint">{hint}</div>}
        </Card>
    );
}
