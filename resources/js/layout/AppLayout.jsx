import React, { useMemo, useState } from "react";
import { Badge, Layout, Menu, Dropdown, Space, Tag, Tooltip, Typography } from "antd";
import {
    ApartmentOutlined,
    AuditOutlined,
    BankOutlined,
    CalendarOutlined,
    CheckSquareOutlined,
    DashboardOutlined,
    FileTextOutlined,
    BellOutlined,
    LogoutOutlined,
    PieChartOutlined,
    SolutionOutlined,
    StarOutlined,
    TeamOutlined,
    UserOutlined,
} from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import { useLocation, useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";
import { ROLE_LABELS } from "~/utils/constants";
import UserAvatar from "~/components/UserAvatar";
import NotificationBell from "~/components/NotificationBell";
import { PersonProvider } from "~/hooks/usePerson";

const { Sider, Header, Content } = Layout;

const ITEMS = {
    dashboard: { key: "/", icon: <DashboardOutlined />, label: "Dashboard" },
    myIpcr: { key: "/my-ipcr", icon: <SolutionOutlined />, label: "My IPCR" },
    myForms: { key: "/my-forms", icon: <FileTextOutlined />, label: "My Forms" },
    myTeam: { key: "/my-team", icon: <TeamOutlined />, label: "My Team" },
    collegeOpcr: { key: "/college-opcr", icon: <BankOutlined />, label: "College OPCR" },
    reviewQueue: { key: "/review-queue", icon: <CheckSquareOutlined />, label: "Review Queue" },
    rating: { key: "/rating", icon: <StarOutlined />, label: "Rating" },
    reports: { key: "/reports", icon: <PieChartOutlined />, label: "Reports" },
    users: { key: "/admin/users", icon: <TeamOutlined />, label: "Accounts" },
    orgUnits: { key: "/admin/org-units", icon: <BankOutlined />, label: "Hierarchy" },
    schoolYears: { key: "/admin/school-years", icon: <CalendarOutlined />, label: "School Years" },
    workflow: { key: "/admin/workflow", icon: <ApartmentOutlined />, label: "Workflow" },
    audit: { key: "/admin/audit", icon: <AuditOutlined />, label: "Audit Trail" },
    notifications: { key: "/notifications", icon: <BellOutlined />, label: "Notifications" },
};

const withPersonal = (menu) => [...menu, ITEMS.notifications];

const MENUS = {
    admin: [
        ITEMS.dashboard,
        ITEMS.collegeOpcr,
        ITEMS.myForms,
        ITEMS.reviewQueue,
        ITEMS.rating,
        ITEMS.reports,
        {
            key: "setup",
            icon: <BankOutlined />,
            label: "Setup",
            children: [ITEMS.users, ITEMS.orgUnits, ITEMS.schoolYears, ITEMS.workflow, ITEMS.audit],
        },
    ],
    // College OPCR is the president's form; My Forms stays in the app but off this menu.
    president: [ITEMS.dashboard, ITEMS.collegeOpcr, /* ITEMS.myForms, */ ITEMS.reports],
    qa: [ITEMS.dashboard, ITEMS.reviewQueue, ITEMS.rating, ITEMS.reports],
    vp: [ITEMS.dashboard, /* ITEMS.myIpcr, */ ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.rating],
    program_head: [ITEMS.dashboard, /* ITEMS.myIpcr, */ ITEMS.myForms, ITEMS.myTeam, ITEMS.reviewQueue, ITEMS.rating],
    employee: [ITEMS.dashboard, /* ITEMS.myIpcr, */ ITEMS.myForms],
};

export default function AppLayout({ children }) {
    const { user, logout } = useAuth();
    const organization = useOrganization();
    const navigate = useNavigate();
    const location = useLocation();
    const [collapsed, setCollapsed] = useState(false);

    const { data: notifications } = useQuery({
        queryKey: ["notifications", "bell"],
        queryFn: () => api.get("notifications?limit=6").then((r) => r.data),
        refetchInterval: 30000,
        enabled: Boolean(user),
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

        // A form opened from a list still belongs to My Forms — except the
        // president, whose document is the college OPCR.
        if (!match && path.startsWith("/forms/")) {
            return user?.role === "president" ? "/college-opcr" : "/my-forms";
        }

        return match?.key ?? "/";
    }, [location.pathname, items, user?.role]);

    return (
        <PersonProvider>
        <Layout className="pms-shell">
            <Sider collapsible collapsed={collapsed} onCollapse={setCollapsed} theme="dark" width={230}>
                <div className="pms-sider">
                    <div
                        className={collapsed ? "pms-brand is-collapsed" : "pms-brand"}
                        role="button"
                        tabIndex={0}
                        onClick={() => navigate("/")}
                        onKeyDown={(event) => event.key === "Enter" && navigate("/")}
                    >
                        <img
                            src="/images/occ-logo.webp"
                            alt={organization.name ?? "Opol Community College"}
                            className="pms-brand-logo"
                        />
                        {!collapsed && (
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
                            theme="dark"
                            mode="inline"
                            selectedKeys={[selectedKey]}
                            defaultOpenKeys={["setup"]}
                            items={items}
                            onClick={({ key }) => navigate(key)}
                        />
                    </div>

                    <div className="pms-sider-foot">
                        {!collapsed && activeYear && (
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
                                collapsed
                                    ? `${user?.name} · ${ROLE_LABELS[user?.role] ?? user?.role}`
                                    : null
                            }
                            placement="right"
                        >
                            <div
                                className={collapsed ? "pms-sider-user is-collapsed" : "pms-sider-user"}
                                role="button"
                                tabIndex={0}
                                onClick={() => navigate("/profile")}
                                onKeyDown={(event) => event.key === "Enter" && navigate("/profile")}
                            >
                                <UserAvatar user={user} showTooltip={false} size={collapsed ? 32 : 34} />
                                {!collapsed && (
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
                            theme="dark"
                            mode="inline"
                            selectable={false}
                            items={[
                                { key: "/profile", icon: <UserOutlined />, label: "My profile" },
                                { key: "logout", icon: <LogoutOutlined />, label: "Sign out", danger: true },
                            ]}
                            onClick={async ({ key }) => {
                                if (key === "logout") {
                                    await logout();
                                    navigate("/login");

                                    return;
                                }

                                navigate(key);
                            }}
                        />
                    </div>
                </div>
            </Sider>
            <Layout>
                <Header className="pms-header">
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
                        <NotificationBell />
                        <Dropdown
                            menu={{
                                items: [
                                    {
                                        key: "profile",
                                        icon: <UserOutlined />,
                                        label: "My profile",
                                        onClick: () => navigate("/profile"),
                                    },
                                    {
                                        key: "logout",
                                        icon: <LogoutOutlined />,
                                        label: "Sign out",
                                        onClick: async () => {
                                            await logout();
                                            navigate("/login");
                                        },
                                    },
                                ],
                            }}
                        >
                            <Space style={{ cursor: "pointer" }}>
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
            </Layout>
        </Layout>
        </PersonProvider>
    );
}
