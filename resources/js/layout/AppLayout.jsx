import React, { useEffect, useMemo, useState } from "react";
import { Badge, Button, Drawer, Layout, Menu, Modal, Space, Tag, Tooltip } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useLocation, useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";
import { APPROVAL_QUEUE_STATUSES, ROLE_LABELS } from "~/utils/constants";
import { formIsWithReviewer, qaIpcrWithPresident } from "~/utils/formReviewer";
import { formYearId } from "~/components/FormListFilters";
import UserAvatar from "~/components/UserAvatar";
import NotificationBell from "~/components/NotificationBell";
import NotificationToasts from "~/components/NotificationToasts";
import NavIcon from "~/components/NavIcon";
import ThemeToggle from "~/components/ThemeToggle";
import { useTheme } from "~/hooks/useTheme";
import { PersonProvider } from "~/hooks/usePerson";
import { PageTitleProvider, usePageTitle } from "~/hooks/usePageTitle";

const { Sider, Header, Content } = Layout;

const ITEMS = {
    dashboard: { key: "/", icon: <NavIcon name="dashboard" />, label: "Dashboard" },
    myIpcr: { key: "/my-ipcr", icon: <NavIcon name="ipcr" />, label: "My IPCR" },
    myForms: { key: "/my-forms", icon: <NavIcon name="forms" />, label: "My Forms" },
    forms: { key: "/my-forms", icon: <NavIcon name="forms" />, label: "Forms" },
    myTeam: { key: "/my-team", icon: <NavIcon name="team" />, label: "My Team" },
    attendance: { key: "/attendance", icon: <NavIcon name="attendance" />, label: "Attendance" },
    calendar: { key: "/attendance", icon: <NavIcon name="attendance" />, label: "Calendar" },
    collegeOpcr: { key: "/college-opcr", icon: <NavIcon name="college" />, label: "College OPCR" },
    reviewQueue: { key: "/review-queue", icon: <NavIcon name="review" />, label: "Review Queue" },
    reports: { key: "/reports", icon: <NavIcon name="reports" />, label: "Reports" },
    users: { key: "/admin/users", icon: <NavIcon name="accounts" />, label: "Accounts" },
    orgUnits: { key: "/admin/org-units", icon: <NavIcon name="hierarchy" />, label: "Hierarchy" },
    schoolYears: { key: "/admin/school-years", icon: <NavIcon name="calendar" />, label: "School Years" },
    workflow: { key: "/admin/workflow", icon: <NavIcon name="workflow" />, label: "Workflow" },
    audit: { key: "/admin/audit", icon: <NavIcon name="audit" />, label: "Audit Trail" },
    notifications: { key: "/notifications", icon: <NavIcon name="bell" />, label: "Notifications" },
};

const withPersonal = (menu) => [...menu, ITEMS.notifications];

const samePerson = (left, right) => Number(left) === Number(right);

function menuFor(role, showCollegeOpcr, showTeam, showRating, showAttendance, showCalendar) {
    const menu = [...(MENUS[role] ?? MENUS.employee)];

    if (showRating && !menu.some((item) => item.key === "/review-queue")) {
        const formsAt = menu.findIndex((item) => item.key === "/my-forms");
        menu.splice(formsAt >= 0 ? formsAt + 1 : menu.length, 0, ITEMS.reviewQueue);
    }

    if (showCollegeOpcr && !menu.some((item) => item.key === "/college-opcr")) {
        const dashboardAt = menu.findIndex((item) => item.key === "/");
        menu.splice(Math.max(dashboardAt, 0) + 1, 0, ITEMS.collegeOpcr);
    }

    if (showTeam && !menu.some((item) => item.key === "/my-team")) {
        const ipcrAt = menu.findIndex((item) => item.key === "/my-ipcr");
        const formsAt = menu.findIndex((item) => item.key === "/my-forms");
        const insertAt = ipcrAt >= 0 ? ipcrAt + 1 : formsAt >= 0 ? formsAt + 1 : 1;
        menu.splice(insertAt, 0, ITEMS.myTeam);
    }

    if ((showAttendance || showCalendar) && !menu.some((item) => item.key === "/attendance")) {
        const teamAt = menu.findIndex((item) => item.key === "/my-team");
        const ipcrAt = menu.findIndex((item) => item.key === "/my-ipcr");
        const insertAt = teamAt >= 0 ? teamAt + 1 : ipcrAt >= 0 ? ipcrAt + 1 : 1;
        menu.splice(insertAt, 0, showCalendar ? ITEMS.calendar : ITEMS.attendance);
    }

    return withPersonal(menu);
}

const MENUS = {
    admin: [
        ITEMS.dashboard,
        ITEMS.collegeOpcr,
        ITEMS.forms,
        ITEMS.reviewQueue,
        ITEMS.reports,
        {
            key: "setup",
            icon: <NavIcon name="setup" />,
            label: "Setup",
            children: [
                ITEMS.users,
                ITEMS.orgUnits,
                ITEMS.schoolYears,
                ITEMS.workflow,
                ITEMS.audit,
            ],
        },
    ],
    president: [ITEMS.dashboard, ITEMS.collegeOpcr, ITEMS.forms, ITEMS.reviewQueue, ITEMS.reports],
    qa: [ITEMS.dashboard, ITEMS.collegeOpcr, ITEMS.myIpcr, ITEMS.forms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.reports],
    vp: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.reports],
    program_head: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.reports],
    employee: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms],
};

function AppShell({ children }) {
    const { user, logout } = useAuth();
    const organization = useOrganization();
    const navigate = useNavigate();
    const location = useLocation();
    const { mode } = useTheme();
    const { title: pageTitle } = usePageTitle();
    const showBack = location.pathname !== "/";
    const menuTheme = mode === "dark" ? "dark" : "light";
    const [collapsed, setCollapsed] = useState(false);
    const [navOpen, setNavOpen] = useState(false);
    const [tabAway, setTabAway] = useState(false);

    const confirmLogout = () => {
        Modal.confirm({
            title: "Sign out?",
            content: "You will need to sign in again to continue.",
            okText: "Sign out",
            cancelText: "Cancel",
            okButtonProps: { danger: true },
            onOk: async () => {
                await logout();
                navigate("/login");
            },
        });
    };

    const { data: notifications } = useQuery({
        queryKey: ["notifications", "bell", user?.id],
        queryFn: () => api.get("notifications?limit=6").then((r) => r.data),
        enabled: Boolean(user),
        refetchInterval: 15_000,
        refetchIntervalInBackground: false,
    });

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
        enabled: Boolean(user),
    });

    const unread = notifications?.unread ?? 0;
    const activeYear = years.find((y) => y.is_active);
    const activePeriod = activeYear?.periods?.find((p) => p.is_active);
    const isRater = (user?.workflow?.rater_roles ?? (user?.role === "qa" ? ["qa"] : [])).includes(user?.role);
    const seesQueueBadge =
        Boolean(user) &&
        user.role !== "admin" &&
        (["president", "qa", "vp", "program_head"].includes(user.role) || isRater);
    const { data: waiting = [] } = useQuery({
        queryKey: ["review-queue", "mine", user?.id],
        queryFn: () => api.get("pcr-forms?queue=1").then((r) => r.data),
        enabled: seesQueueBadge,
    });
    const queueCount = Array.isArray(waiting) && activeYear
        ? waiting.filter((form) => {
              if (formYearId(form) !== Number(activeYear.id)) return false;

              return user?.role === "president"
                  ? APPROVAL_QUEUE_STATUSES.includes(form.status) ||
                        qaIpcrWithPresident(form) ||
                        (isRater && form.type === "ipcr" && form.status === "qa_rating")
                  : true;
          }).length
        : 0;
    const seesCollegeOpcr = Boolean(user);
    const leadsATeam = ["program_head", "vp", "qa"].includes(user?.role);
    const canFollowUp = Boolean(user?.capabilities?.follow_up);
    const { data: watchedUnits = [] } = useQuery({
        queryKey: ["watched-units", user?.id],
        queryFn: () => api.get("org-units/watching").then((r) => r.data),
        enabled: Boolean(user) && !leadsATeam && !canFollowUp,
    });
    const { data: ledUnits = [] } = useQuery({
        queryKey: ["led-units", user?.id],
        queryFn: () => api.get("org-units/leading").then((r) => r.data),
        enabled: Boolean(user),
    });
    // Admin is setup/oversight — never a team desk, even if named on a unit.
    const seesTeam =
        user?.role !== "admin" &&
        (canFollowUp || leadsATeam || watchedUnits.length > 0 || ledUnits.length > 0);
    const seesCalendar = ["admin", "president", "vp"].includes(user?.role);
    const seesAttendance = Boolean(user) && !seesCalendar;
    const openFormId = location.pathname.match(/^\/forms\/(\d+)/)?.[1] ?? null;
    const { data: openForm } = useQuery({
        queryKey: ["pcr-form", openFormId],
        queryFn: () => api.get(`pcr-forms/${openFormId}`).then((r) => r.data),
        enabled: Boolean(user) && Boolean(openFormId),
    });

    const items = useMemo(() => {
        const menu = menuFor(user?.role, seesCollegeOpcr, seesTeam, isRater, seesAttendance, seesCalendar);

        return menu.map((item) => {
            if (item.key === "/notifications") {
                return {
                    ...item,
                    className: unread > 0 ? "pms-has-unread" : undefined,
                    label: (
                        <Space size={8}>
                            <span>Notifications</span>
                            {unread > 0 && <Badge count={unread} size="small" overflowCount={99} />}
                        </Space>
                    ),
                };
            }

            if (item.key === "/review-queue") {
                return {
                    ...item,
                    className: queueCount > 0 ? "pms-has-unread" : undefined,
                    label: (
                        <Space size={8}>
                            <span>Review Queue</span>
                            {queueCount > 0 && <Badge count={queueCount} size="small" overflowCount={99} />}
                        </Space>
                    ),
                };
            }

            return item;
        });
    }, [user?.role, unread, queueCount, collapsed, seesCollegeOpcr, seesTeam, seesAttendance, seesCalendar, isRater]);

    const selectedKey = useMemo(() => {
        const path = location.pathname;
        const flat = items.flatMap((item) => (item.children ? item.children : [item]));
        const match = flat
            .filter((item) => path === item.key || (item.key !== "/" && path.startsWith(item.key)))
            .sort((a, b) => b.key.length - a.key.length)[0];

        if (match) {
            return match.key;
        }

        // Opening a form leaves the list URL. Keep the menu the person came
        // from. Otherwise their own form is My Forms, someone else's is My
        // Team, and a form waiting on this person stays in the review queue.
        if (path.startsWith("/forms/")) {
            const from = location.state?.from;
            const ownsForm = Boolean(openForm) && samePerson(openForm.user_id, user?.id);
            const fromMenu = typeof from === "string" && flat.some((item) => item.key === from);

            // A queue notice keeps this menu lit after the form has moved on.
            // The writer's own form still belongs under My Forms.
            if (fromMenu && !(from === "/review-queue" && ownsForm)) {
                return from;
            }

            if (flat.some((item) => item.key === "/review-queue") && formIsWithReviewer(user, openForm)) {
                return "/review-queue";
            }

            if (ownsForm) {
                return "/my-forms";
            }

            if (openForm?.type === "opcr" && flat.some((item) => item.key === "/college-opcr")) {
                return "/college-opcr";
            }

            // The president's direct reports live under Forms. Unit approvers
            // keep My Team.
            if (user?.role === "president" && flat.some((item) => item.key === "/my-forms")) {
                return "/my-forms";
            }

            if (openForm && flat.some((item) => item.key === "/my-team")) {
                return "/my-team";
            }

            return "/my-forms";
        }

        return "/";
    }, [location.pathname, location.state, items, user, openForm]);

    useEffect(() => {
        setNavOpen(false);
        setTabAway(false);
    }, [location.pathname]);

    useEffect(() => {
        const phone = window.matchMedia("(max-width: 991px)");
        let last = window.scrollY;

        const onScroll = () => {
            if (!phone.matches) {
                setTabAway(false);
                last = window.scrollY;
                return;
            }

            const y = window.scrollY;
            const delta = y - last;

            if (y < 32) setTabAway(false);
            else if (delta > 10) setTabAway(true);
            else if (delta < -10) setTabAway(false);

            last = y;
        };

        window.addEventListener("scroll", onScroll, { passive: true });

        return () => window.removeEventListener("scroll", onScroll);
    }, []);

    const home = { key: "/", icon: <NavIcon name="dashboard" />, label: "Home" };
    const ipcr = { key: "/my-ipcr", icon: <NavIcon name="ipcr" />, label: "IPCR" };
    const forms = { key: "/my-forms", icon: <NavIcon name="forms" />, label: "Forms" };
    const opcr = { key: "/college-opcr", icon: <NavIcon name="college" />, label: "OPCR" };
    const review = {
        key: "/review-queue",
        icon: <NavIcon name="review" />,
        label: "Review",
        badge: queueCount,
    };
    const alerts = { key: "/notifications", icon: <NavIcon name="bell" />, label: "Alerts", badge: unread };
    const seesReview = ["admin", "qa", "vp", "program_head", "president"].includes(user?.role) || isRater;

    const tabs =
        user?.role === "admin" || user?.role === "president"
            ? [opcr, forms, home, review, alerts]
            : user?.role === "qa"
              ? [opcr, ipcr, home, review, alerts]
              : user?.role === "employee" && !seesReview
                ? [ipcr, home, alerts]
                : [ipcr, forms, home, ...(seesReview ? [review] : []), alerts];

    const go = (key) => {
        setNavOpen(false);
        navigate(key);
    };

    const goBack = () => {
        if (location.key !== "default") {
            navigate(-1);
            return;
        }

        navigate("/");
    };

    const rail = (isCollapsed, menuItems = items) => (
        <div className="pms-sider">
            <div
                className={isCollapsed ? "pms-brand is-collapsed" : "pms-brand"}
                role="button"
                tabIndex={0}
                onClick={() => go("/")}
                onKeyDown={(event) => event.key === "Enter" && go("/")}
            >
                <img
                    src="/images/occ-logo.webp"
                    alt={organization.name ?? "Opol Community College"}
                    className="pms-brand-logo"
                />
                {!isCollapsed && (
                    <span className="pms-brand-words">
                        <span className="pms-brand-name">
                            {organization.name ?? "Opol Community College"}
                        </span>
                        <span className="pms-brand-sub">Performance Commitment and Review</span>
                    </span>
                )}
            </div>

            <div className="pms-sider-nav">
                <Menu
                    theme={menuTheme}
                    mode="inline"
                    inlineCollapsed={isCollapsed}
                    selectedKeys={[selectedKey]}
                    defaultOpenKeys={isCollapsed ? [] : ["setup"]}
                    items={menuItems}
                    onClick={({ key }) => go(key)}
                />
            </div>

            <div className="pms-sider-foot">
                {!isCollapsed && activeYear && (
                    <div className="pms-sider-cycle">
                        <span>Active cycle</span>
                        <strong>
                            {activeYear.label}
                            {activePeriod ? ` · ${activePeriod.label}` : ""}
                        </strong>
                    </div>
                )}

                <Tooltip
                    title={
                        isCollapsed
                            ? `${user?.name} · ${ROLE_LABELS[user?.role] ?? user?.role}`
                            : null
                    }
                    placement="right"
                >
                    <div
                        className={isCollapsed ? "pms-sider-user is-collapsed" : "pms-sider-user"}
                        role="button"
                        tabIndex={0}
                        onClick={() => go("/profile")}
                        onKeyDown={(event) => event.key === "Enter" && go("/profile")}
                    >
                        <UserAvatar user={user} showTooltip={false} size={isCollapsed ? 32 : 34} />
                        {!isCollapsed && (
                            <span className="pms-sider-who">
                                <span className="pms-sider-name">{user?.name}</span>
                                <span className="pms-sider-role">
                                    {ROLE_LABELS[user?.role] ?? user?.role}
                                </span>
                            </span>
                        )}
                    </div>
                </Tooltip>

                <Menu
                    theme={menuTheme}
                    mode="inline"
                    inlineCollapsed={isCollapsed}
                    selectable={false}
                    items={[
                        { key: "/profile", icon: <NavIcon name="user" />, label: "My profile" },
                        { key: "logout", icon: <NavIcon name="logout" />, label: "Sign out", danger: true },
                    ]}
                    onClick={({ key }) => {
                        if (key === "logout") {
                            setNavOpen(false);
                            confirmLogout();

                            return;
                        }

                        go(key);
                    }}
                />
            </div>
        </div>
    );

    return (
        <PersonProvider>
        <Layout className="pms-shell">
            <NotificationToasts userId={user?.id} items={notifications?.items} />
            <Sider
                className="pms-sider-desktop"
                collapsible
                collapsed={collapsed}
                onCollapse={setCollapsed}
                theme={menuTheme}
                width={280}
            >
                {rail(collapsed, items)}
            </Sider>
            <Drawer
                className="pms-nav-drawer"
                rootClassName="pms-nav-drawer"
                placement="left"
                open={navOpen}
                onClose={() => setNavOpen(false)}
                width={280}
                closable={false}
                styles={{ body: { padding: 0, background: mode === "dark" ? "#0c1218" : "#fff" } }}
            >
                {rail(
                    false,
                    items.map((item) =>
                        item.key === "/notifications"
                            ? {
                                  ...item,
                                  label: (
                                      <Space size={8}>
                                          <span>Alerts</span>
                                          {unread > 0 && (
                                              <Badge count={unread} size="small" overflowCount={99} />
                                          )}
                                      </Space>
                                  ),
                              }
                            : item
                    )
                )}
            </Drawer>
            <Layout>
                <Header className="pms-header">
                    <Button
                        className="pms-nav-toggle"
                        type="text"
                        icon={<NavIcon name="menu" />}
                        aria-label="Open menu"
                        onClick={() => setNavOpen(true)}
                    />
                    <div className="pms-header-lead">
                        {showBack && (
                            <button type="button" className="pms-header-back" aria-label="Back" onClick={goBack}>
                                <NavIcon name="back" />
                            </button>
                        )}
                        {pageTitle ? <h1 className="pms-header-page">{pageTitle}</h1> : null}
                    </div>
                    <Space className="pms-header-tools" size={16}>
                        {activeYear && (
                            <Tag className="pms-header-cycle" bordered={false}>
                                {activeYear.label}
                                {activePeriod ? ` · ${activePeriod.label}` : ""}
                            </Tag>
                        )}
                        <ThemeToggle />
                        <span className="pms-desktop-only">
                            <NotificationBell />
                        </span>
                    </Space>
                </Header>
                <Content className="pms-content">
                    <div key={location.pathname} className="pms-page-shift">
                        {children}
                    </div>
                </Content>
                <nav className={tabAway ? "pms-tabbar is-away" : "pms-tabbar"} aria-label="Primary">
                    {tabs.map((tab) => {
                        const active =
                            tab.key === selectedKey ||
                            (tab.key === "/"
                                ? location.pathname === "/"
                                : location.pathname === tab.key ||
                                  location.pathname.startsWith(`${tab.key}/`));

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                className={
                                    (active ? "is-active" : "") + (tab.key === "/" ? " is-home" : "")
                                }
                                onClick={() => go(tab.key)}
                            >
                                <Badge count={tab.badge || 0} size="small" offset={[2, -2]}>
                                    {tab.icon}
                                </Badge>
                                <span>{tab.label}</span>
                            </button>
                        );
                    })}
                </nav>
            </Layout>
        </Layout>
        </PersonProvider>
    );
}

export default function AppLayout({ children }) {
    return (
        <PageTitleProvider>
            <AppShell>{children}</AppShell>
        </PageTitleProvider>
    );
}
