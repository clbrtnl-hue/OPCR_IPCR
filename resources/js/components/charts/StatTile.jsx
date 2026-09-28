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

export default function StatTile({ label, value, suffix, hint, tone, meter, onClick, icon, accent }) {
    const mark = accent ?? "#1e3a72";

    return (
        <Card
            className={["viz-tile", onClick ? "viz-tile-link" : "", icon ? "viz-tile-rich" : ""]
                .filter(Boolean)
                .join(" ")}
            onClick={onClick}
        >
            <div className="viz-tile-top">
                <div className="viz-tile-label">{label}</div>
                {icon && (
                    <span className="viz-tile-icon" style={{ color: mark, background: `${mark}18` }}>
                        {icon}
                    </span>
                )}
            </div>
            <div className="viz-tile-value" style={tone ? { color: tone } : undefined}>
                {value}
                {suffix != null && <span className="viz-tile-suffix">{suffix}</span>}
            </div>
            {meter && <Meter pct={meter.pct} color={meter.color ?? tone ?? VIZ.series[0]} />}
            {hint && <div className="viz-tile-hint">{hint}</div>}
        </Card>
    );
}
