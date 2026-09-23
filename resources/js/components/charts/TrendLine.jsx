import React, { useEffect, useRef, useState } from "react";
import { Empty } from "antd";
import { VIZ } from "~/utils/constants";

export default function TrendLine({
    points = [],
    height = 210,
    min = 0,
    max,
    color = VIZ.series[0],
    format = (value) => value,
    emptyText = "Nothing to show yet.",
}) {
    const wrap = useRef(null);
    const [width, setWidth] = useState(640);
    const [hover, setHover] = useState(null);

    useEffect(() => {
        if (!wrap.current || typeof ResizeObserver === "undefined") return undefined;

        const observer = new ResizeObserver(([entry]) => {
            setWidth(Math.max(280, Math.round(entry.contentRect.width)));
        });

        observer.observe(wrap.current);

        return () => observer.disconnect();
    }, []);

    if (!points.length) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    const pad = { left: 42, right: 20, top: 18, bottom: 28 };
    const innerW = Math.max(40, width - pad.left - pad.right);
    const innerH = Math.max(40, height - pad.top - pad.bottom);
    const values = points.map((p) => Number(p.value ?? 0));
    const ceiling = max ?? Math.max(1, ...values) * 1.15;
    const span = ceiling - min || 1;

    const x = (index) =>
        points.length === 1 ? pad.left + innerW / 2 : pad.left + (index / (points.length - 1)) * innerW;
    const y = (value) => pad.top + innerH - ((Number(value ?? 0) - min) / span) * innerH;

    const line = points.map((p, i) => `${i === 0 ? "M" : "L"} ${x(i)} ${y(p.value)}`).join(" ");
    const area = `${line} L ${x(points.length - 1)} ${pad.top + innerH} L ${x(0)} ${pad.top + innerH} Z`;

    const seen = new Set();
    const ticks = [0, 0.25, 0.5, 0.75, 1]
        .map((step) => min + step * span)
        .filter((tick) => {
            const label = String(format(Number(tick.toFixed(2))));

            if (seen.has(label)) return false;

            seen.add(label);

            return true;
        });
    const labelEvery = points.length > 8 ? 2 : 1;
    const last = points.length - 1;

    const track = (event) => {
        const box = event.currentTarget.getBoundingClientRect();
        const offset = event.clientX - box.left;
        const step = points.length === 1 ? innerW : innerW / (points.length - 1);
        const index = Math.min(last, Math.max(0, Math.round((offset - pad.left) / step)));

        setHover(index);
    };

    return (
        <div className="viz-plot" ref={wrap} style={{ height }}>
            <svg width={width} height={height} role="img">
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line
                            x1={pad.left}
                            x2={width - pad.right}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke={VIZ.grid}
                            strokeWidth={1}
                        />
                        <text x={pad.left - 8} y={y(tick) + 4} textAnchor="end" className="viz-tick">
                            {format(Number(tick.toFixed(2)))}
                        </text>
                    </g>
                ))}

                <path d={area} fill={color} opacity={0.1} />
                <path d={line} fill="none" stroke={color} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />

                {points.map((point, index) => (
                    <circle
                        key={point.label}
                        cx={x(index)}
                        cy={y(point.value)}
                        r={hover === index ? 6 : 4}
                        fill={color}
                        stroke="#ffffff"
                        strokeWidth={2}
                    />
                ))}

                {points.map((point, index) =>
                    index % labelEvery === 0 || index === last ? (
                        <text
                            key={`label-${point.label}`}
                            x={x(index)}
                            y={height - 8}
                            textAnchor="middle"
                            className="viz-tick"
                        >
                            {point.label}
                        </text>
                    ) : null
                )}

                <text x={x(last)} y={y(points[last].value) - 12} textAnchor="end" className="viz-point-label">
                    {format(points[last].value)}
                </text>

                {hover != null && (
                    <line
                        x1={x(hover)}
                        x2={x(hover)}
                        y1={pad.top}
                        y2={pad.top + innerH}
                        stroke={VIZ.axis}
                        strokeWidth={1}
                    />
                )}

                <rect
                    x={0}
                    y={0}
                    width={width}
                    height={height}
                    fill="transparent"
                    onMouseMove={track}
                    onMouseLeave={() => setHover(null)}
                />
            </svg>

            {hover != null && (
                <div
                    className="viz-tooltip"
                    style={{ left: x(hover), top: Math.max(y(points[hover].value) - 14, 4) }}
                >
                    <strong>{points[hover].hint ?? points[hover].label}</strong>
                    <span>{format(points[hover].value)}</span>
                </div>
            )}
        </div>
    );
}
