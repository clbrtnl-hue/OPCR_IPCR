import React from "react";
import { Alert, Select, Space, Tag, Typography } from "antd";
import {
    CheckSquareOutlined,
    ClockCircleOutlined,
    FileTextOutlined,
    RiseOutlined,
    TeamOutlined,
    TrophyOutlined,
} from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { ADJECTIVAL_COLORS, VIZ } from "~/utils/constants";
import { formYearId } from "~/components/FormListFilters";
import { useAuth } from "~/hooks/useAuth";
import InsightsBoard from "~/components/InsightsBoard";
import PlayBoard, { paceTitle } from "~/components/PlayBoard";
import PresidentGlance from "~/components/PresidentGlance";
import TeamPace from "~/components/TeamPace";
import StatTile from "~/components/charts/StatTile";

function useTypedGreeting(text) {
    const [count, setCount] = React.useState(0);

    React.useEffect(() => {
        const desktop = window.matchMedia("(min-width: 992px)").matches;
        const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        if (!desktop || reduce || !text) {
            setCount(text.length);
            return undefined;
        }

        setCount(0);
        let index = 0;
        const timer = window.setInterval(() => {
            index += 1;
            setCount(index);

            if (index >= text.length) {
                window.clearInterval(timer);
            }
        }, 42);

        return () => window.clearInterval(timer);
    }, [text]);

    return {
        shown: text.slice(0, count),
        typing: count < text.length,
    };
}

function greeting() {
    const hour = new Date().getHours();

    if (hour < 12) {
        return "Good morning";
    }

    if (hour < 18) {
        return "Good afternoon";
    }

    return "Good evening";
}

export default function DashboardPage() {
    const { user, can } = useAuth();
    const navigate = useNavigate();
    const [yearId, setYearId] = React.useState(null);
    const [periodId, setPeriodId] = React.useState(null);
    const heroRef = React.useRef(null);

    React.useEffect(() => {
        const hero = heroRef.current;
        const scroller = hero?.closest(".pms-content");

        if (!hero || !scroller) return undefined;

        const onScroll = () => {
            const shrink = Math.min(1, Math.max(0, scroller.scrollTop / 80));
            hero.style.setProperty("--shrink", shrink.toFixed(3));
            hero.classList.toggle("is-compact", shrink > 0.65);
        };

        onScroll();
        scroller.addEventListener("scroll", onScroll, { passive: true });

        return () => scroller.removeEventListener("scroll", onScroll);
    }, []);

    const { data: years = [], isFetched: yearsFetched } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const isAdmin = user?.role === "admin";
    const isPresident = user?.role === "president";
    const showsOwnCycle = Boolean(user) && !isAdmin && !isPresident;

    const { data: forms = [] } = useQuery({
        queryKey: ["pcr-forms"],
        queryFn: () => api.get("pcr-forms").then((r) => r.data),
        enabled: showsOwnCycle,
    });
    const ownWorkPath = isAdmin ? "/my-forms" : "/my-ipcr";

    const { data: queue = [] } = useQuery({
        queryKey: ["review-queue", isAdmin ? "oversight" : "mine"],
        queryFn: () =>
            api
                .get(isAdmin ? "pcr-forms?oversight=review" : "pcr-forms?queue=1")
                .then((r) => r.data),
        enabled: !isAdmin && can("program_head", "vp", "qa"),
    });

    const activeYear = years.find((y) => y.is_active);
    const activePeriod = activeYear?.periods?.find((p) => p.is_active);
    const shownYear = years.find((y) => y.id === yearId) ?? activeYear;
    const leadsTheCollege = ["admin", "president", "qa"].includes(user?.role);
    const playsOwnGame = ["program_head", "vp", "employee"].includes(user?.role);
    const reviewsOwnQueue = ["program_head", "vp"].includes(user?.role);
    const roleSeesTeam = ["program_head", "vp"].includes(user?.role);

    const waitingCount = shownYear
        ? queue.filter((form) => formYearId(form) === Number(shownYear.id)).length
        : 0;

    const periods = [...(shownYear?.periods ?? [])].sort((a, b) => a.seq - b.seq);
    const shownPeriodId = periods.some((p) => p.id === periodId) ? periodId : null;
    const shownPeriod = periods.find((period) => period.id === shownPeriodId);
    const periodLocked = Boolean(shownPeriod?.is_locked);

    const { data: insights } = useQuery({
        queryKey: ["report-summary", shownYear?.id, shownPeriodId],
        queryFn: () =>
            api
                .get("reports/summary", {
                    params: {
                        school_year_id: shownYear.id,
                        ...(shownPeriodId ? { rating_period_id: shownPeriodId } : {}),
                    },
                })
                .then((r) => r.data),
        enabled: leadsTheCollege && Boolean(shownYear?.id),
    });

    const { data: mySummary } = useQuery({
        queryKey: ["my-summary", shownYear?.id, shownPeriodId],
        queryFn: () =>
            api
                .get("reports/my-summary", {
                    params: {
                        school_year_id: shownYear?.id,
                        ...(shownPeriodId ? { rating_period_id: shownPeriodId } : {}),
                    },
                })
                .then((r) => r.data),
        enabled: showsOwnCycle && Boolean(shownYear?.id),
    });

    const { data: ledUnits = [] } = useQuery({
        queryKey: ["led-units", user?.id],
        queryFn: () => api.get("org-units/leading").then((r) => r.data),
        enabled: Boolean(user) && !leadsTheCollege && !roleSeesTeam,
    });
    const seesTeamPace = !leadsTheCollege && (roleSeesTeam || ledUnits.length > 0);

    const { data: teamPace } = useQuery({
        queryKey: ["my-team-progress", shownYear?.id, shownPeriodId],
        queryFn: () =>
            api
                .get("my-team/progress", {
                    params: {
                        school_year_id: shownYear.id,
                        ...(shownPeriodId ? { rating_period_id: shownPeriodId } : {}),
                    },
                })
                .then((r) => r.data),
        enabled: seesTeamPace && Boolean(shownYear?.id),
    });

    const mine = forms.filter(
        (f) =>
            (f.user_id === user?.id || (f.type === "opcr" && f.org_unit_id === user?.org_unit_id)) &&
            (!shownPeriodId || !f.rating_period_id || f.rating_period_id === shownPeriodId)
    );
    const myForms = mySummary?.forms;
    const myWork = mySummary?.commitments;
    const reviews = can("program_head", "vp", "qa");

    const title = user?.prefix?.trim() ?? "";
    const givenName = user?.first_name?.trim() || "";
    const greetedAs = [title, givenName].filter(Boolean).join(" ");
    const cycleLine = !yearsFetched
        ? "Where your performance commitments stand right now."
        : !activeYear
          ? "An administrator still needs to open a school year."
          : activePeriod
            ? `${activeYear.label} · ${activePeriod.label} is open${
                  activePeriod.closes_at
                      ? ` until ${dayjs(activePeriod.closes_at).format("MMMM D, YYYY")}`
                      : ""
              }.`
            : `${activeYear.label} is the active cycle. No review period is open right now.`;
    const cycleLineDesktop =
        activePeriod && yearsFetched && activeYear
            ? `${activePeriod.label} is open${
                  activePeriod.closes_at
                      ? ` until ${dayjs(activePeriod.closes_at).format("MMMM D, YYYY")}`
                      : ""
              }.`
            : cycleLine;
    const cycleLineMobile =
        activePeriod && yearsFetched && activeYear
            ? `${years.length > 1 ? "" : `${activeYear.label} · `}${activePeriod.label} is open${
                  activePeriod.closes_at
                      ? ` until ${dayjs(activePeriod.closes_at).format("MMMM D")}`
                      : ""
              }.`
            : cycleLine;
    const showPeriodSwitch = periods.length > 1;
    const showHeroExtra = years.length > 1 || showPeriodSwitch;
    const hello = `${greeting()}${greetedAs ? `, ${greetedAs}` : ""}`;
    const typed = useTypedGreeting(hello);

    return (
        <div className="pms-dash">
            <div className="pms-hero-stick" ref={heroRef}>
            <section className="pms-hero">
                <div>
                    <div className="pms-hero-kicker">Performance commitment</div>
                    <h1 className={typed.typing ? "is-typing" : undefined}>
                        <span className="pms-greet-full">{hello}</span>
                        <span className="pms-greet-type" aria-hidden="true">
                            {typed.shown}
                            <span className="pms-caret" />
                        </span>
                    </h1>
                    <p className="pms-hero-wide">{cycleLineDesktop}</p>
                    <p className="pms-mobile-only">{cycleLineMobile}</p>
                </div>
                {showHeroExtra && (
                    <div className="pms-hero-extra">
                        <Space wrap className="pms-hero-filters">
                            {years.length > 1 && (
                                <Select
                                    className="pms-hero-year"
                                    value={shownYear?.id}
                                    onChange={(value) => {
                                        setYearId(value);
                                        setPeriodId(null);
                                    }}
                                    options={years.map((y) => ({
                                        value: y.id,
                                        label: y.is_active ? `${y.label} ★` : y.label,
                                    }))}
                                />
                            )}
                            {showPeriodSwitch && (
                                <Select
                                    className="pms-hero-review"
                                    value={shownPeriodId ?? "all"}
                                    onChange={(value) => setPeriodId(value === "all" ? null : value)}
                                    options={[
                                        { value: "all", label: "Whole year" },
                                        ...periods.map((p) => ({
                                            value: p.id,
                                            label: p.is_active ? `${p.label} ★` : p.label,
                                        })),
                                    ]}
                                />
                            )}
                        </Space>
                    </div>
                )}
            </section>
            </div>

            {yearsFetched && !activeYear && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="No active school year"
                    description="An administrator needs to set the active school year before forms can be filled in."
                />
            )}

            {isPresident && insights?.totals && (
                <>
                    <div className="pms-board-head">
                        <Typography.Title level={5} style={{ margin: 0 }}>
                            College progress — {shownYear?.label}
                        </Typography.Title>
                        <a onClick={() => navigate("/reports")}>Open the report tables</a>
                    </div>
                    <PresidentGlance data={insights} periodLocked={periodLocked} />
                </>
            )}

            {leadsTheCollege && !isPresident && insights?.totals && (
                <>
                    <div className="pms-board-head">
                        <Typography.Title level={5} style={{ margin: 0 }}>
                            The college at a glance
                            {shownYear?.label ? (
                                <span className="pms-glance-year"> — {shownYear.label}</span>
                            ) : null}
                        </Typography.Title>
                        <a onClick={() => navigate("/reports")}>Open the report tables</a>
                    </div>
                    <InsightsBoard data={insights} periodLocked={periodLocked} />
                </>
            )}

            {showsOwnCycle && (
            <div className="pms-rise">
            <div className={playsOwnGame && mySummary && teamPace?.rows?.length ? "pms-season-row" : undefined}>
            {playsOwnGame && mySummary && (
                <PlayBoard
                    kicker="Your season"
                    score={myWork?.progress_pct}
                    caption="done"
                    title={paceTitle(myWork?.progress_pct)}
                    badge={mySummary.latest?.adjectival || "Unranked"}
                    onOpen={navigate}
                    quests={[
                        {
                            key: "drafts",
                            title: "Drafts are cleared",
                            detail: myForms?.drafts
                                ? `${myForms.drafts} still in draft`
                                : myForms?.total
                                  ? "No drafts waiting"
                                  : "You have not opened a form yet",
                            done: (myForms?.total ?? 0) > 0 && (myForms?.drafts ?? 0) === 0,
                            pct: myForms?.total
                                ? Math.round(((myForms.total - myForms.drafts) / myForms.total) * 100)
                                : 0,
                            href: "/my-forms",
                        },
                        {
                            key: "late",
                            title: "Nothing of yours is late",
                            detail: myWork?.overdue
                                ? `${myWork.overdue} past the target date`
                                : myWork?.due_soon
                                  ? `${myWork.due_soon} due within seven days`
                                  : "On time",
                            done: (myWork?.overdue ?? 0) === 0 && (myWork?.total ?? 0) > 0,
                            pct: myWork?.total
                                ? Math.round(((myWork.total - (myWork.overdue ?? 0)) / myWork.total) * 100)
                                : 0,
                            href: "/my-ipcr",
                        },
                        reviewsOwnQueue
                            ? {
                                  key: "queue",
                                  title: "Your review queue is clear",
                                  detail: waitingCount
                                      ? `${waitingCount} form${waitingCount === 1 ? "" : "s"} waiting on you`
                                      : "Nothing is sitting at your step",
                                  done: waitingCount === 0,
                                  pct: waitingCount === 0 ? 100 : 0,
                                  href: "/review-queue",
                              }
                            : {
                                  key: "rate",
                                  title: "A rating is on record",
                                  detail: mySummary.latest
                                      ? `${Number(mySummary.latest.average).toFixed(2)} · ${mySummary.latest.adjectival}`
                                      : "Nothing of yours has been rated yet",
                                  done: Boolean(mySummary.latest),
                                  pct: mySummary.latest ? 100 : 0,
                                  href: "/my-forms",
                              },
                    ]}
                />
            )}
            {seesTeamPace && <TeamPace data={teamPace} onOpen={navigate} />}
            </div>
            <Typography.Title level={5} className="pms-section-title" style={{ margin: "4px 0 12px" }}>
                My cycle
            </Typography.Title>

            <div className="pms-cycle-grid">
                <StatTile
                    label="My forms"
                    icon={<FileTextOutlined />}
                    value={myForms?.total ?? mine.length}
                    hint={
                        myForms
                            ? `${myForms.drafts} draft · ${myForms.returned} returned · ${myForms.rated} rated`
                            : undefined
                    }
                    onClick={() => navigate("/my-forms")}
                />
                <StatTile
                    label="My commitments"
                    icon={<CheckSquareOutlined />}
                    accent="#2a78d6"
                    value={myWork?.total ?? 0}
                    hint={
                        myWork
                            ? `${myWork.completed} completed · ${myWork.in_flight} ongoing · ${myWork.not_started} not started`
                            : undefined
                    }
                    onClick={() => navigate(ownWorkPath)}
                />
                <StatTile
                    label="My progress"
                    icon={<RiseOutlined />}
                    accent="#1baf7a"
                    value={myWork?.progress_pct != null ? `${myWork.progress_pct}%` : "—"}
                    meter={{ pct: myWork?.progress_pct ?? 0, color: "#1baf7a" }}
                    hint="Average across every line you own"
                    onClick={() => navigate(ownWorkPath)}
                />
                <StatTile
                    label="Past their target date"
                    icon={<ClockCircleOutlined />}
                    accent={(myWork?.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    value={myWork?.overdue ?? 0}
                    tone={(myWork?.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    hint={`${myWork?.due_soon ?? 0} of mine fall due within seven days`}
                    onClick={() => navigate(ownWorkPath)}
                />
                {(reviews || isAdmin) && (
                    <StatTile
                        label={isAdmin ? "In review" : "Waiting for me to review"}
                        icon={<TeamOutlined />}
                        accent={waitingCount > 0 ? VIZ.warning : "#1e3a72"}
                        value={waitingCount}
                        tone={waitingCount > 0 ? VIZ.warning : undefined}
                        onClick={() =>
                            navigate(user?.role === "qa" ? "/review-queue?tab=rating" : "/review-queue")
                        }
                        hint={
                            isAdmin
                                ? "Forms waiting on a reviewer"
                                : "Forms sitting at your step of the chain"
                        }
                    />
                )}
                <StatTile
                    label="In the review chain"
                    icon={<TeamOutlined />}
                    accent="#3c62b0"
                    value={myForms ? myForms.in_review + myForms.with_qa : 0}
                    hint={
                        myForms
                            ? `${myForms.in_review} with a reviewer · ${myForms.with_qa} with QA`
                            : undefined
                    }
                    onClick={() => navigate("/my-forms")}
                />
                <StatTile
                    label="My latest rating"
                    icon={<TrophyOutlined />}
                    accent="#1e3a72"
                    value={mySummary?.latest ? Number(mySummary.latest.average).toFixed(2) : "—"}
                    suffix={
                        mySummary?.latest?.adjectival ? (
                            <Tag color={ADJECTIVAL_COLORS[mySummary.latest.adjectival]}>
                                {mySummary.latest.adjectival}
                            </Tag>
                        ) : null
                    }
                    hint={mySummary?.latest ? undefined : "Nothing of yours has been rated yet"}
                    onClick={() => navigate("/my-forms")}
                />
            </div>
            </div>
            )}
        </div>
    );
}
