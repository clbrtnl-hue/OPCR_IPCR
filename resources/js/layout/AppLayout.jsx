import React, { useEffect, useMemo, useState } from "react";
import { Badge, Button, Drawer, Layout, Menu, Dropdown, Space, Tag, Tooltip, Typography } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useLocation, useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";
import { ROLE_LABELS } from "~/utils/constants";
import UserAvatar from "~/components/UserAvatar";
import NotificationBell from "~/components/NotificationBell";
import NavIcon from "~/components/NavIcon";
import { PersonProvider } from "~/hooks/usePerson";

const { Sider, Header, Content } = Layout;

const ITEMS = {
    dashboard: { key: "/", icon: <NavIcon name="dashboard" />, label: "Dashboard" },
    myIpcr: { key: "/my-ipcr", icon: <NavIcon name="ipcr" />, label: "My IPCR" },
    myForms: { key: "/my-forms", icon: <NavIcon name="forms" />, label: "My Forms" },
    forms: { key: "/my-forms", icon: <NavIcon name="forms" />, label: "Forms" },
    myTeam: { key: "/my-team", icon: <NavIcon name="team" />, label: "My Team" },
    collegeOpcr: { key: "/college-opcr", icon: <NavIcon name="college" />, label: "College OPCR" },
    reviewQueue: { key: "/review-queue", icon: <NavIcon name="review" />, label: "Review Queue" },
    rating: { key: "/rating", icon: <NavIcon name="star" />, label: "Rating" },
    reports: { key: "/reports", icon: <NavIcon name="reports" />, label: "Reports" },
    users: { key: "/admin/users", icon: <NavIcon name="accounts" />, label: "Accounts" },
    orgUnits: { key: "/admin/org-units", icon: <NavIcon name="hierarchy" />, label: "Hierarchy" },
    schoolYears: { key: "/admin/school-years", icon: <NavIcon name="calendar" />, label: "School Years" },
    workflow: { key: "/admin/workflow", icon: <NavIcon name="workflow" />, label: "Workflow" },
    audit: { key: "/admin/audit", icon: <NavIcon name="audit" />, label: "Audit Trail" },
    notifications: { key: "/notifications", icon: <NavIcon name="bell" />, label: "Notifications" },
};

const withPersonal = (menu) => [...menu, ITEMS.notifications];

const MENUS = {
    admin: [
        ITEMS.dashboard,
        ITEMS.collegeOpcr,
        ITEMS.forms,
        ITEMS.reviewQueue,
        ITEMS.rating,
        ITEMS.reports,
        {
            key: "setup",
            icon: <NavIcon name="setup" />,
            label: "Setup",
            children: [ITEMS.users, ITEMS.orgUnits, ITEMS.schoolYears, ITEMS.workflow, ITEMS.audit],
        },
    ],
    president: [ITEMS.dashboard, ITEMS.collegeOpcr, ITEMS.myForms, ITEMS.reports],
    qa: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.rating, ITEMS.reports],
    vp: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.rating],
    program_head: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.rating],
    employee: [ITEMS.dashboard, ITEMS.myIpcr, ITEMS.myForms],
};

export default function AppLayout({ children }) {
    const { user, logout } = useAuth();
    const organization = useOrganization();
    const navigate = useNavigate();
    const location = useLocation();
    const [collapsed, setCollapsed] = useState(false);
    const [navOpen, setNavOpen] = useState(false);

    const { data: notifications } = useQuery({
        queryKey: ["notifications", "bell"],
        queryFn: () => api.get("notifications?limit=6").then((r) => r.data),
        enabled: Boolean(user),
        refetchInterval: 4000,
        refetchIntervalInBackground: true,
    });

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
        enabled: Boolean(user),
    });

    const unread = notifications?.unread ?? 0;
    const activeYear = years.find((y) => y.is_active);
    const activePeriod = activeYear?.periods?.find((p) => p.is_active);

    const items = useMemo(() => {
        const menu = withPersonal(MENUS[user?.role] ?? MENUS.employee);

        return menu.map((item) =>
            item.key === "/notifications"
                ? {
                      ...item,
                      className: unread > 0 ? "pms-has-unread" : undefined,
                      label: (
                          <Space size={8}>
                              <span>Notifications</span>
                              {unread > 0 && <Badge count={unread} size="small" overflowCount={99} />}
                          </Space>
                      ),
                  }
                : item
        );
    }, [user?.role, unread, collapsed]);

    const selectedKey = useMemo(() => {
        const path = location.pathname;
        const flat = items.flatMap((item) => (item.children ? item.children : [item]));
        const match = flat
            .filter((item) => path === item.key || (item.key !== "/" && path.startsWith(item.key)))
            .sort((a, b) => b.key.length - a.key.length)[0];

        // A form opened from a list still belongs to My Forms. The college
        // OPCR itself stays on /college-opcr.
        if (!match && path.startsWith("/forms/")) {
            return "/my-forms";
        }

        return match?.key ?? "/";
    }, [location.pathname, items]);

    useEffect(() => {
        setNavOpen(false);
    }, [location.pathname]);

    const tabs = [
        { key: "/", icon: <NavIcon name="dashboard" />, label: "Home" },
        user?.role === "president"
            ? { key: "/college-opcr", icon: <NavIcon name="college" />, label: "OPCR" }
            : ["employee", "program_head", "vp", "qa"].includes(user?.role)
              ? { key: "/my-ipcr", icon: <NavIcon name="ipcr" />, label: "IPCR" }
              : { key: "/my-forms", icon: <NavIcon name="forms" />, label: "Forms" },
        ["admin", "qa", "vp", "program_head"].includes(user?.role) && {
            key: "/review-queue",
            icon: <NavIcon name="review" />,
            label: "Review",
        },
        { key: "/notifications", icon: <NavIcon name="bell" />, label: "Alerts", badge: unread },
    ].filter(Boolean);

    const go = (key) => {
        setNavOpen(false);
        navigate(key);
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
                            {organization.short_name ?? "OCC"} PMS
                        </span>
                        <span className="pms-brand-sub">
                            {organization.name ?? "Opol Community College"}
                        </span>
                    </span>
                )}
            </div>

            <div className="pms-sider-nav">
                <Menu
                    theme="light"
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
                    theme="light"
                    mode="inline"
                    inlineCollapsed={isCollapsed}
                    selectable={false}
                    items={[
                        { key: "/profile", icon: <NavIcon name="user" />, label: "My profile" },
                        { key: "logout", icon: <NavIcon name="logout" />, label: "Sign out", danger: true },
                    ]}
                    onClick={async ({ key }) => {
                        if (key === "logout") {
                            setNavOpen(false);
                            await logout();
                            navigate("/login");

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
            <Sider
                className="pms-sider-desktop"
                collapsible
                collapsed={collapsed}
                onCollapse={setCollapsed}
                theme="light"
                width={248}
            >
                {rail(collapsed)}
            </Sider>
            <Drawer
                className="pms-nav-drawer"
                rootClassName="pms-nav-drawer"
                placement="left"
                open={navOpen}
                onClose={() => setNavOpen(false)}
                width={280}
                closable={false}
                styles={{ body: { padding: 0, background: "#fff" } }}
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
                    <div className="pms-header-title">
                        <span className="pms-header-org">
                            {organization.name ?? "Opol Community College"}
                        </span>
                        <span className="pms-header-sub">Performance Commitment and Review</span>
                    </div>
                    <Space size={16}>
                        {activeYear && (
                            <Tag className="pms-header-cycle" bordered={false}>
                                {activeYear.label}
                                {activePeriod ? ` · ${activePeriod.label}` : ""}
                            </Tag>
                        )}
                        <span className="pms-desktop-only">
                            <NotificationBell />
                        </span>
                        <Dropdown
                            menu={{
                                items: [
                                    {
                                        key: "profile",
                                        icon: <NavIcon name="user" />,
                                        label: "My profile",
                                        onClick: () => navigate("/profile"),
                                    },
                                    {
                                        key: "logout",
                                        icon: <NavIcon name="logout" />,
                                        label: "Sign out",
                                        onClick: async () => {
                                            await logout();
                                            navigate("/login");
                                        },
                                    },
                                ],
                            }}
                        >
                            <Space className="pms-header-account" style={{ cursor: "pointer" }}>
                                <UserAvatar user={user} showTooltip={false} />
                                <span className="pms-header-user">
                                    <div style={{ lineHeight: 1.2 }}>{user?.name}</div>
                                    <div style={{ lineHeight: 1.2 }}>
                                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                            {ROLE_LABELS[user?.role] ?? user?.role}
                                        </Typography.Text>
                                    </div>
                                </span>
                            </Space>
                        </Dropdown>
                    </Space>
                </Header>
                <Content className="pms-content">{children}</Content>
                <nav className="pms-tabbar" aria-label="Primary">
                    {tabs.map((tab) => {
                        const active =
                            tab.key === "/"
                                ? location.pathname === "/"
                                : location.pathname === tab.key || location.pathname.startsWith(`${tab.key}/`);

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                className={active ? "is-active" : undefined}
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
