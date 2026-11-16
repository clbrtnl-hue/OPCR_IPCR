import React, { useEffect, useState } from "react";

export function paceTitle(pct) {
    if (pct == null) return "Warming up";
    if (pct >= 100) return "Cycle cleared";
    if (pct >= 90) return "Closing in";
    if (pct >= 70) return "Strong pace";
    if (pct >= 40) return "In motion";

    return "Getting started";
}

export default function PlayBoard({
    kicker,
    score,
    caption,
    title,
    badge,
    quests = [],
    podium,
    onOpen,
}) {
    const pct = score == null ? 0 : Math.max(0, Math.min(100, Number(score)));
    const solo = !podium;
    const [drawn, setDrawn] = useState(0);

    useEffect(() => {
        const frame = requestAnimationFrame(() => setDrawn(pct));

        return () => cancelAnimationFrame(frame);
    }, [pct]);

    const radius = 46;
    const circumference = 2 * Math.PI * radius;

    return (
        <section className={solo ? "pms-play is-solo" : "pms-play"}>
            <div className="pms-play-score">
                <div className="pms-play-ring" aria-hidden="true">
                    <svg viewBox="0 0 120 120">
                        <circle className="pms-ring-track" cx="60" cy="60" r={radius} />
                        <circle
                            className="pms-ring-value"
                            cx="60"
                            cy="60"
                            r={radius}
                            strokeDasharray={circumference}
                            strokeDashoffset={circumference * (1 - drawn / 100)}
                        />
                    </svg>
                    <span>
                        <strong>{score == null ? "—" : `${Math.round(pct)}%`}</strong>
                        <em>{caption}</em>
                    </span>
                </div>
                <div>
                    <div className="pms-play-kicker">{kicker}</div>
                    <h2>{title}</h2>
                    {badge && <span className="pms-play-badge">{badge}</span>}
                </div>
            </div>

            <div className="pms-play-quests">
                {quests.map((quest, index) => (
                    <button key={quest.key} type="button" className={quest.done ? "is-done" : ""} onClick={() => onOpen(quest.href)}>
                        <span className="pms-play-mark" aria-hidden="true">
                            {quest.done ? "✓" : index + 1}
                        </span>
                        <span className="pms-play-quest-copy">
                            <strong>{quest.title}</strong>
                            <em>{quest.detail}</em>
                            <i>
                                <b
                                    style={{
                                        width: drawn === pct ? `${Math.max(0, Math.min(100, quest.pct ?? 0))}%` : 0,
                                    }}
                                />
                            </i>
                        </span>
                    </button>
                ))}
            </div>

            {podium && (
                <div className="pms-play-podium">
                    <div className="pms-play-kicker">Leading the cycle</div>
                    {podium.length === 0 ? (
                        <p>Nobody has recorded progress yet.</p>
                    ) : (
                        podium.map((person) => (
                            <button key={person.key} type="button" onClick={() => onOpen(person.href)}>
                                <span className={`pms-play-medal is-${person.rank}`}>{person.rank}</span>
                                <span className="pms-play-quest-copy">
                                    <strong>{person.name}</strong>
                                    <em>{person.meta}</em>
                                </span>
                            </button>
                        ))
                    )}
                </div>
            )}
        </section>
    );
}
