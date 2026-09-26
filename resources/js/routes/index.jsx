import React from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import { Spin } from "antd";
import { useAuth } from "~/hooks/useAuth";
import AppLayout from "~/layout/AppLayout";
import LoginPage from "~/pages/LoginPage";
import DashboardPage from "~/pages/DashboardPage";
import UsersPage from "~/pages/admin/UsersPage";
import OrgUnitsPage from "~/pages/admin/OrgUnitsPage";
import CollegeOpcrPage from "~/pages/CollegeOpcrPage";
import SchoolYearsPage from "~/pages/admin/SchoolYearsPage";
import WorkflowPage from "~/pages/admin/WorkflowPage";
import MyFormsPage from "~/pages/MyFormsPage";
import MyIpcrPage from "~/pages/MyIpcrPage";
import MyTeamPage from "~/pages/MyTeamPage";
import FormEditorPage from "~/pages/FormEditorPage";
import ReviewQueuePage from "~/pages/ReviewQueuePage";
import RatingPage from "~/pages/RatingPage";
import ReportsPage from "~/pages/ReportsPage";
import PrintFormPage from "~/pages/PrintFormPage";
import AuditLogPage from "~/pages/admin/AuditLogPage";
import ProfilePage from "~/pages/ProfilePage";
import NotificationsPage from "~/pages/NotificationsPage";

function Protected({ children, roles }) {
    const { user, loading, can } = useAuth();

    if (loading) {
        return (
            <div style={{ display: "grid", placeItems: "center", minHeight: "100vh" }}>
                <Spin size="large" />
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (roles && !can(...roles)) {
        return <Navigate to="/" replace />;
    }

    return children;
}

export default function AppRoutes() {
    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
                path="/forms/:id/print"
                element={
                    <Protected>
                        <PrintFormPage />
                    </Protected>
                }
            />
            <Route
                path="/*"
                element={
                    <Protected>
                        <AppLayout>
                            <Routes>
                                <Route path="/" element={<DashboardPage />} />
                                <Route path="/my-forms" element={<MyFormsPage />} />
                                <Route
                                    path="/my-ipcr"
                                    element={
                                        <Protected roles={["employee", "program_head", "vp"]}>
                                            <MyIpcrPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/my-team"
                                    element={
                                        <Protected roles={["program_head", "vp", "qa"]}>
                                            <MyTeamPage />
                                        </Protected>
                                    }
                                />
                                <Route path="/profile" element={<ProfilePage />} />
                                <Route path="/forms/:id" element={<FormEditorPage />} />
                                <Route
                                    path="/review-queue"
                                    element={
                                        <Protected roles={["program_head", "vp", "qa"]}>
                                            <ReviewQueuePage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/rating"
                                    element={
                                        <Protected roles={["qa", "program_head", "vp", "admin"]}>
                                            <RatingPage />
                                        </Protected>
                                    }
                                />
                                <Route path="/notifications" element={<NotificationsPage />} />
                                <Route
                                    path="/reports"
                                    element={
                                        <Protected roles={["president", "qa"]}>
                                            <ReportsPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/admin/users"
                                    element={
                                        <Protected roles={["admin"]}>
                                            <UsersPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/admin/org-units"
                                    element={
                                        <Protected roles={["admin"]}>
                                            <OrgUnitsPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/admin/school-years"
                                    element={
                                        <Protected roles={["admin"]}>
                                            <SchoolYearsPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/college-opcr"
                                    element={
                                        <Protected roles={["admin", "president"]}>
                                            <CollegeOpcrPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/admin/workflow"
                                    element={
                                        <Protected roles={["admin"]}>
                                            <WorkflowPage />
                                        </Protected>
                                    }
                                />
                                <Route
                                    path="/admin/audit"
                                    element={
                                        <Protected roles={["admin"]}>
                                            <AuditLogPage />
                                        </Protected>
                                    }
                                />
                                <Route path="*" element={<Navigate to="/" replace />} />
                            </Routes>
                        </AppLayout>
                    </Protected>
                }
            />
        </Routes>
    );
}
