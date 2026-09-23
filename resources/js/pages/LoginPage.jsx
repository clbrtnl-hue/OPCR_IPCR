import React, { useState } from "react";
import { Alert, Button, Card, Form, Input, Typography } from "antd";
import {
    CheckSquareOutlined,
    LockOutlined,
    SolutionOutlined,
    StarOutlined,
    UserOutlined,
} from "@ant-design/icons";
import { Navigate, useNavigate, useSearchParams } from "react-router-dom";
import { errorText } from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import { useOrganization } from "~/hooks/useOrganization";

const POINTS = [
    {
        icon: <SolutionOutlined />,
        text: "Write your IPCR for the period and submit it when it is ready.",
    },
    {
        icon: <CheckSquareOutlined />,
        text: "Your head and VP review it, comment on it, or send it back.",
    },
    {
        icon: <StarOutlined />,
        text: "QA rates every line on Quality, Efficiency and Timeliness.",
    },
];

export default function LoginPage() {
    const { user, login } = useAuth();
    const organization = useOrganization();
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const [submitting, setSubmitting] = useState(false);
    const [failure, setFailure] = useState(null);
    const [capsLock, setCapsLock] = useState(false);

    if (user) {
        return <Navigate to="/" replace />;
    }

    const name = organization.name ?? "Performance Management";

    const onFinish = async (values) => {
        setSubmitting(true);
        setFailure(null);

        try {
            await login(values.email, values.password);
            navigate("/");
        } catch (error) {
            setFailure({
                text: errorText(error),
                waiting: error?.response?.status === 429,
            });
        } finally {
            setSubmitting(false);
        }
    };

    const watchCapsLock = (event) => {
        if (typeof event.getModifierState === "function") {
            setCapsLock(event.getModifierState("CapsLock"));
        }
    };

    return (
        <div className="pms-login">
            <aside className="pms-login-brand">
                <div className="pms-login-mark">
                    <img src="/images/occ-logo.webp" alt="" />
                    <div>
                        <div className="pms-login-org">{name}</div>
                        {organization.address && (
                            <div className="pms-login-place">{organization.address}</div>
                        )}
                    </div>
                </div>

                <h2 className="pms-login-lede">Performance Commitment and Review</h2>
                <p className="pms-login-sub">
                    The OPCR and IPCR forms, from the first draft to the final rating — written,
                    reviewed and filed in one place.
                </p>

                <ul className="pms-login-points">
                    {POINTS.map((point) => (
                        <li key={point.text}>
                            {point.icon}
                            <span>{point.text}</span>
                        </li>
                    ))}
                </ul>
            </aside>

            <main className="pms-login-panel">
                <Card className="pms-login-card" variant="borderless">
                    <div className="pms-login-inline-mark">
                        <img src="/images/occ-logo.webp" alt="" />
                        <span>{name}</span>
                    </div>

                    <div className="pms-login-heading">
                        <h1>Sign in</h1>
                        <p>Use the account your administrator set up for you.</p>
                    </div>

                    {failure ? (
                        <Alert
                            type={failure.waiting ? "warning" : "error"}
                            showIcon
                            message={failure.text}
                            style={{ marginBottom: 16 }}
                        />
                    ) : (
                        params.get("reason") === "session-expired" && (
                            <Alert
                                type="warning"
                                showIcon
                                message="Your session ended. Sign in again to continue."
                                style={{ marginBottom: 16 }}
                            />
                        )
                    )}

                    <Form
                        layout="vertical"
                        onFinish={onFinish}
                        onValuesChange={() => setFailure(null)}
                        requiredMark={false}
                    >
                        <Form.Item
                            name="email"
                            label="Email"
                            rules={[
                                { required: true, message: "Enter your email address." },
                                { type: "email", message: "That does not look like an email address." },
                            ]}
                        >
                            <Input
                                autoFocus
                                autoComplete="username"
                                inputMode="email"
                                prefix={<UserOutlined />}
                                placeholder="name@occ.edu.ph"
                                size="large"
                            />
                        </Form.Item>
                        <Form.Item
                            name="password"
                            label="Password"
                            rules={[{ required: true, message: "Enter your password." }]}
                            extra={
                                capsLock ? (
                                    <Typography.Text type="warning" style={{ fontSize: 12 }}>
                                        Caps Lock is on.
                                    </Typography.Text>
                                ) : null
                            }
                        >
                            <Input.Password
                                autoComplete="current-password"
                                prefix={<LockOutlined />}
                                placeholder="Password"
                                size="large"
                                onKeyDown={watchCapsLock}
                                onKeyUp={watchCapsLock}
                                onBlur={() => setCapsLock(false)}
                            />
                        </Form.Item>
                        <Button
                            type="primary"
                            htmlType="submit"
                            block
                            size="large"
                            loading={submitting}
                        >
                            Sign in
                        </Button>
                    </Form>

                    <p className="pms-login-foot">
                        Forgot your password? Ask an administrator to reset it for you.
                    </p>
                </Card>
            </main>
        </div>
    );
}
