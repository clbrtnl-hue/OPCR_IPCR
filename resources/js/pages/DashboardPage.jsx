import React from "react";
import { Alert, Card, Col, Empty, List, Row, Segmented, Select, Space, Tag, Typography } from "antd";
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
import { ADJECTIVAL_COLORS, STATUS_META, VIZ } from "~/utils/constants";
import { useAuth } from "~/hooks/useAuth";
import InsightsBoard from "~/components/InsightsBoard";
import StatTile from "~/components/charts/StatTile";

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

    const { data: years = [], isFetched: yearsFetched } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const { data: forms = [] } = useQuery({
        queryKey: ["pcr-forms"],
        queryFn: () => api.get("pcr-forms").then((r) => r.data),
    });

    const isAdmin = user?.role === "admin";

    const { data: queue = [] } = useQuery({
        queryKey: ["review-queue", isAdmin ? "oversight" : "mine"],
        queryFn: () =>
            api
                .get(isAdmin ? "pcr-forms?oversight=review" : "pcr-forms?queue=1")
                .then((r) => r.data),
        enabled: isAdmin || can("program_head", "vp", "qa"),
    });

    const activeYear = years.find((y) => y.is_active);
    const activePeriod = activeYear?.periods?.find((p) => p.is_active);
    const shownYear = years.find((y) => y.id === yearId) ?? activeYear;
    const leadsTheCollege = ["admin", "president", "qa"].includes(user?.role);

    const periods = [...(shownYear?.periods ?? [])].sort((a, b) => a.seq - b.seq);
    const shownPeriodId = periods.some((p) => p.id === periodId) ? periodId : null;

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
        enabled: Boolean(shownYear?.id),
    });

    const mine = forms.filter(
        (f) =>
            (f.user_id === user?.id || (f.type === "opcr" && f.org_unit_id === user?.org_unit_id)) &&
            (!shownPeriodId || !f.rating_period_id || f.rating_period_id === shownPeriodId)
    );
    const myForms = mySummary?.forms;
    const myWork = mySummary?.commitments;
    const reviews = can("program_head", "vp", "qa");

    const firstName = user?.name?.split(" ")[0] ?? "";
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
    const showPeriodSwitch = periods.length > 1;
    const showHeroExtra = years.length > 1 || showPeriodSwitch;

    return (
        <>
            <section className="pms-hero">
                <div>
                    <div className="pms-hero-kicker">Performance commitment</div>
                    <h1>
                        {greeting()}
                        {firstName ? `, ${firstName}` : ""}
                    </h1>
                    <p>{cycleLine}</p>
                </div>
                {showHeroExtra && (
                    <div className="pms-hero-extra">
                        {years.length > 1 && (
                            <Space>
                                <Typography.Text type="secondary">Cycle</Typography.Text>
                                <Select
                                    style={{ minWidth: 160 }}
                                    value={shownYear?.id}
                                    onChange={(value) => {
                                        setYearId(value);
                                        setPeriodId(null);
                                    }}
                                    options={years.map((y) => ({
                                        value: y.id,
                                        label: y.is_active ? `${y.label} (active)` : y.label,
                                    }))}
                                />
                            </Space>
                        )}
                        {showPeriodSwitch && (
                            <Segmented
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
                    </div>
                )}
            </section>

            {yearsFetched && !activeYear && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="No active school year"
                    description="An administrator needs to set the active school year before forms can be filled in."
                />
            )}

            {leadsTheCollege && insights?.totals && (
                <>
                    <div className="pms-board-head">
                        <Typography.Title level={5} style={{ margin: 0 }}>
                            The college at a glance — {shownYear?.label}
                        </Typography.Title>
                        <a onClick={() => navigate("/reports")}>Open the report tables</a>
                    </div>
                    <InsightsBoard data={insights} />
                </>
            )}

            <Typography.Title level={5} className="pms-section-title" style={{ margin: "4px 0 12px" }}>
                My cycle{shownYear ? ` — ${shownYear.label}` : ""}
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
                />
                <StatTile
                    label="My progress"
                    icon={<RiseOutlined />}
                    accent="#1baf7a"
                    value={myWork?.progress_pct != null ? `${myWork.progress_pct}%` : "—"}
                    meter={{ pct: myWork?.progress_pct ?? 0, color: "#1baf7a" }}
                    hint="Average across every line you own"
                />
                <StatTile
                    label="Past their target date"
                    icon={<ClockCircleOutlined />}
                    accent={(myWork?.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    value={myWork?.overdue ?? 0}
                    tone={(myWork?.overdue ?? 0) > 0 ? VIZ.critical : VIZ.good}
                    hint={`${myWork?.due_soon ?? 0} of mine fall due within seven days`}
                />
                {(reviews || isAdmin) && (
                    <StatTile
                        label={isAdmin ? "In review" : "Waiting for me to review"}
                        icon={<TeamOutlined />}
                        accent={queue.length > 0 ? VIZ.warning : "#1e3a72"}
                        value={queue.length}
                        tone={queue.length > 0 ? VIZ.warning : undefined}
                        onClick={() => navigate(isAdmin || !can("qa") ? "/review-queue" : "/rating")}
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
                />
            </div>

            {(mySummary?.at_risk ?? []).length > 0 && (
                <Card
                    title="My commitments past their target date"
                    style={{ marginBottom: 16 }}
                    extra={<a onClick={() => navigate("/my-ipcr")}>Open my IPCR</a>}
                >
                    <List
                        size="small"
                        dataSource={mySummary.at_risk.slice(0, 5)}
                        renderItem={(line) => (
                            <List.Item
                                actions={[
                                    <Tag key="late" color="error">
                                        {line.days_late} day{line.days_late === 1 ? "" : "s"} late
                                    </Tag>,
                                ]}
                            >
                                <List.Item.Meta
                                    title={
                                        <Typography.Text ellipsis={{ tooltip: line.description }}>
                                            {line.description || "Untitled commitment"}
                                        </Typography.Text>
                                    }
                                    description={`Due ${dayjs(line.target_date).format("MMM D, YYYY")} · ${
                                        line.progress_pct
                                    }% done`}
                                />
                            </List.Item>
                        )}
                    />
                </Card>
            )}

            <Row gutter={16}>
                {!can("president") && (
                <Col xs={24} lg={12}>
                    <Card title="My forms" extra={<a onClick={() => navigate("/my-forms")}>See all</a>}>
                        {mine.length === 0 ? (
                            <Empty description="You have not created a form yet." />
                        ) : (
                            <List
                                dataSource={mine.slice(0, 6)}
                                renderItem={(form) => (
                                    <List.Item
                                        style={{ cursor: "pointer" }}
                                        onClick={() => navigate(`/forms/${form.id}`)}
                                        actions={[
                                            <Tag key="s" color={STATUS_META[form.status].color}>
                                                {STATUS_META[form.status].label}
                                            </Tag>,
                                        ]}
                                    >
                                        <List.Item.Meta
                                            title={`${form.type.toUpperCase()} — ${form.school_year?.label}`}
                                            description={form.org_unit?.name}
                                        />
                                    </List.Item>
                                )}
                            />
                        )}
                    </Card>
                </Col>
                )}

                {reviews && (
                    <Col xs={24} lg={12}>
                        <Card
                            title="Waiting for me"
                            extra={
                                <a onClick={() => navigate(can("qa") ? "/rating" : "/review-queue")}>
                                    Open queue
                                </a>
                            }
                        >
                            {queue.length === 0 ? (
                                <Empty description="Nothing is waiting for you." />
                            ) : (
                                <List
                                    dataSource={queue.slice(0, 6)}
                                    renderItem={(form) => (
                                        <List.Item
                                            style={{ cursor: "pointer" }}
                                            onClick={() => navigate(`/forms/${form.id}`)}
                                        >
                                            <List.Item.Meta
                                                title={`${form.type.toUpperCase()} — ${
                                                    form.owner?.name ?? form.org_unit?.name
                                                }`}
                                                description={
                                                    form.submitted_at
                                                        ? `Submitted ${dayjs(form.submitted_at).format("MMM D, YYYY")}`
                                                        : form.org_unit?.name
                                                }
                                            />
                                        </List.Item>
                                    )}
                                />
                            )}
                        </Card>
                    </Col>
                )}
            </Row>
        </>
    );
}
