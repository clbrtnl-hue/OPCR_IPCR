export default function StatusRing({ slices, total, caption, onSelect }) {
    const active = slices.filter((slice) => slice.value > 0);
    const sum = active.reduce((count, slice) => count + slice.value, 0);
    const radius = 62;
    const circumference = 2 * Math.PI * radius;
    const gap = active.length > 1 ? 18 : 0;
    let cursor = 0;

    return (
        <div className="pms-status-ring">
            <svg viewBox="0 0 168 168" role="img" aria-label={`${total} ${caption}`}>
                <g transform="rotate(-90 84 84)">
                    {sum === 0 ? (
                        <circle cx="84" cy="84" r={radius} fill="none" stroke="#f0efec" strokeWidth="16" />
                    ) : (
                        active.map((slice) => {
                            const length = (slice.value / sum) * circumference;
                            const draw = Math.max(length - gap, 4);
                            const node = (
                                <circle
                                    key={slice.key}
                                    cx="84"
                                    cy="84"
                                    r={radius}
                                    fill="none"
                                    stroke={slice.color}
                                    strokeWidth="16"
                                    strokeLinecap={active.length > 1 ? "round" : "butt"}
                                    strokeDasharray={`${draw} ${circumference - draw}`}
                                    strokeDashoffset={-cursor}
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        onSelect(slice);
                                    }}
                                />
                            );
                            cursor += length;

                            return node;
                        })
                    )}
                </g>
            </svg>
            <div className="pms-status-ring-copy">
                <strong>{total}</strong>
                <span>{caption}</span>
            </div>
        </div>
    );
}
