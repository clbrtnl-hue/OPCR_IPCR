import React, { useEffect, useMemo } from "react";
import {
    Alert,
    Button,
    Card,
    Form,
    Input,
    Select,
    Space,
    Typography,
    message,
} from "antd";
import { NotificationOutlined } from "@ant-design/icons";
import { useMutation, useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import api, { errorText } from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { ROLE_LABELS } from "~/utils/constants";

const AUDIENCES = [
    {
        value: "all",
        label: "Everyone",
        hint: "All active accounts except administrators.",
    },
    {
        value: "incomplete",
        label: "Writers with unfinished forms",
        hint: "Owners of draft or returned forms for the chosen period.",
    },
    {
        value: "in_review",
        label: "People holding forms in review",
        hint: "Approvers and reviewers who currently have a form waiting on them.",
    },
    {
        value: "roles",
        label: "Selected roles",
        hint: "Only the roles you pick below.",
    },
];

const TEMPLATES = [
    {
        key: "closing",
        label: "Period closing soon",
        title: "The review period is closing soon",
        body: "Finish and submit unfinished forms, and clear anything waiting in your review queue.",
    },
    {
        key: "custom",
        label: "Custom reminder",
        title: "Action needed on your OPCR / IPCR",
        body: "Please check your forms and finish anything still waiting on you.",
    },
];

const ROLE_OPTIONS = ["employee", "program_head", "vp", "qa", "hr", "president"].map((role) => ({
    value: role,
    label: ROLE_LABELS[role] ?? role,
}));

export default function AnnouncementsPage() {
    const [form] = Form.useForm();
    const audiences = Form.useWatch("audiences", form) ?? [];

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const periodOptions = useMemo(() => {
        const options = [];

        years.forEach((year) => {
            (year.periods ?? []).forEach((period) => {
                const window = period.closes_at
                    ? ` · closes ${dayjs(period.closes_at).format("MMM D, YYYY")}`
                    : "";
                options.push({
                    value: period.id,
                    label: `${year.label} — ${period.label}${period.is_active ? " (active)" : ""}${window}`,
                });
            });
        });

        return options;
    }, [years]);

    const activePeriodId = useMemo(() => {
        for (const year of years) {
            const period = (year.periods ?? []).find((item) => item.is_active);
            if (period) return period.id;
        }

        return undefined;
    }, [years]);

    useEffect(() => {
        if (activePeriodId && !form.getFieldValue("rating_period_id")) {
            form.setFieldsValue({ rating_period_id: activePeriodId });
        }
    }, [activePeriodId, form]);

    const send = useMutation({
        mutationFn: (values) => api.post("announcements", values).then((r) => r.data),
        onSuccess: (payload) => {
            message.success(payload?.message ?? "Announcement sent.");
            form.setFieldsValue({ title: undefined, body: undefined });
        },
        onError: (error) => message.error(errorText(error)),
    });

    const applyTemplate = (key) => {
        const template = TEMPLATES.find((item) => item.key === key);
        if (!template) return;

        form.setFieldsValue({
            title: template.title,
            body: template.body,
        });
    };

    const audienceHint = audiences.includes("all")
        ? AUDIENCES.find((item) => item.value === "all")?.hint
        : audiences
              .map((value) => AUDIENCES.find((item) => item.value === value)?.hint)
              .filter(Boolean)
              .join(" ") || "Pick one or more groups.";

    return (
        <div>
            <PageHeader
                title="Announcements"
                subtitle="Send a custom notice when you need one. Opening or locking a review period already notifies everyone automatically; due-soon and period-closing reminders run daily on their own."
            />

            <Alert
                type="info"
                showIcon
                icon={<NotificationOutlined />}
                style={{ marginBottom: 16 }}
                message="Already automatic"
                description="Period open and period locked notices go out from Setup → School Years. Closing-soon, commitment due dates, and unclosed forms are sent by the daily reminder job. Use this page for extra or one-off messages — you can combine audiences (except Everyone, which stands alone)."
            />

            <Card>
                <Form
                    form={form}
                    layout="vertical"
                    style={{ maxWidth: 640 }}
                    initialValues={{
                        audiences: ["incomplete"],
                        rating_period_id: activePeriodId,
                    }}
                    onFinish={(values) =>
                        send.mutate({
                            ...values,
                            roles: (values.audiences ?? []).includes("roles")
                                ? values.roles
                                : undefined,
                        })
                    }
                >
                    <Form.Item
                        name="audiences"
                        label="Audience"
                        rules={[{ required: true, message: "Pick at least one audience." }]}
                        extra={audienceHint}
                        getValueFromEvent={(next = []) => {
                            const previous = form.getFieldValue("audiences") ?? [];

                            // Choosing Everyone clears narrower picks. Adding anything else drops Everyone.
                            if (next.includes("all") && !previous.includes("all")) {
                                return ["all"];
                            }

                            if (next.includes("all") && next.length > 1) {
                                return next.filter((value) => value !== "all");
                            }

                            return next;
                        }}
                    >
                        <Select
                            mode="multiple"
                            placeholder="Pick one or more groups"
                            options={AUDIENCES.map(({ value, label }) => ({ value, label }))}
                        />
                    </Form.Item>

                    {audiences.includes("roles") && (
                        <Form.Item
                            name="roles"
                            label="Roles"
                            rules={[{ required: true, message: "Pick at least one role." }]}
                        >
                            <Select mode="multiple" options={ROLE_OPTIONS} placeholder="Choose roles" />
                        </Form.Item>
                    )}

                    <Form.Item
                        name="rating_period_id"
                        label="Review period"
                        extra="Optional. Adds the closing date to the message and links people to their forms."
                    >
                        <Select
                            allowClear
                            placeholder="Use active period context"
                            options={periodOptions}
                        />
                    </Form.Item>

                    <Form.Item
                        name="title"
                        label={
                            <Space wrap size={[8, 4]} style={{ width: "100%", justifyContent: "space-between" }}>
                                <span>Title</span>
                                <Space size={4} wrap>
                                    {TEMPLATES.map((template) => (
                                        <Button
                                            key={template.key}
                                            type="link"
                                            size="small"
                                            style={{ paddingInline: 4 }}
                                            onClick={() => applyTemplate(template.key)}
                                        >
                                            {template.label}
                                        </Button>
                                    ))}
                                </Space>
                            </Space>
                        }
                        rules={[{ required: true, message: "Enter a title." }]}
                    >
                        <Input maxLength={160} showCount placeholder="The review period is closing soon" />
                    </Form.Item>

                    <Form.Item
                        name="body"
                        label="Message"
                        rules={[{ required: true, message: "Enter a message." }]}
                    >
                        <Input.TextArea
                            rows={4}
                            maxLength={1000}
                            showCount
                            placeholder="What should people do, and by when?"
                        />
                    </Form.Item>

                    <Typography.Paragraph type="secondary" style={{ marginTop: -8 }}>
                        Recipients see this in Notifications and get an email when mail is configured.
                    </Typography.Paragraph>

                    <Button type="primary" htmlType="submit" loading={send.isPending}>
                        Send announcement
                    </Button>
                </Form>
            </Card>
        </div>
    );
}
