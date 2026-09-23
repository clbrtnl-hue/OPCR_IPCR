import React from "react";
import {
    Alert,
    Button,
    Card,
    Checkbox,
    Col,
    Divider,
    InputNumber,
    Popconfirm,
    Row,
    Select,
    Space,
    Spin,
    Switch,
    Table,
    Tag,
    Typography,
    message,
} from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { ROLE_LABELS } from "~/utils/constants";

const KEY_LABELS = {
    review_stages: "Review chain",
    delegation: "Who may hand work out",
    opcr: "The college OPCR",
    rating: "Rating scale",
};

export default function WorkflowPage() {
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["workflow-settings"],
        queryFn: () => api.get("workflow-settings").then((r) => r.data),
    });

    const save = useMutation({
        mutationFn: ({ key, value }) => api.post("workflow-settings", { key, value }),
        onSuccess: (_, { key }) => {
            message.success(`${KEY_LABELS[key]} saved.`);
            queryClient.invalidateQueries({ queryKey: ["workflow-settings"] });
        },
    });

    const reset = useMutation({
        mutationFn: (key) => api.post("workflow-settings/reset", { key }),
        onSuccess: (_, key) => {
            message.success(`${KEY_LABELS[key]} restored to the shipped default.`);
            queryClient.invalidateQueries({ queryKey: ["workflow-settings"] });
        },
    });

    if (isLoading || !data) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    const { settings, roles } = data;
    const roleOptions = roles.map((r) => ({ value: r, label: ROLE_LABELS[r] ?? r }));
    const actingRoleOptions = roleOptions.filter((o) => o.value !== "vp");

    const put = (key, value) => save.mutate({ key, value });

    const ResetButton = ({ target }) => (
        <Popconfirm
            title="Restore the shipped default?"
            description="Your changes to this section are discarded."
            onConfirm={() => reset.mutate(target)}
        >
            <Button size="small">Restore default</Button>
        </Popconfirm>
    );

    const stages = settings.review_stages ?? [];
    const delegation = settings.delegation ?? {};
    const opcr = settings.opcr ?? {};
    const rating = settings.rating ?? {};

    const setStage = (index, patch) =>
        put(
            "review_stages",
            stages.map((s, i) => (i === index ? { ...s, ...patch } : s))
        );

    const moveStage = (index, by) => {
        const next = [...stages];
        const [row] = next.splice(index, 1);
        next.splice(index + by, 0, row);
        put("review_stages", next);
    };

    return (
        <>
            <PageHeader
                title="Workflow"
                subtitle="The rules this college runs on. Change them here rather than asking for a code change — everything below takes effect on the next form action."
            />

            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Who reports to whom is set elsewhere"
                description="A head's VP comes from Setup → Hierarchy, where each unit names its head and the VP it reports to. This page decides the stages and who fills them; that page decides the people."
            />

            <Card
                title="Review chain"
                extra={<ResetButton target="review_stages" />}
                style={{ marginBottom: 16 }}
            >
                <Typography.Paragraph type="secondary">
                    An IPCR walks these stages in order. A skippable stage nobody fills is
                    passed over — which is how a head's own form skips the head stage,
                    and a VP's goes straight to QA. The last stage can never be skipped.
                </Typography.Paragraph>

                <Table
                    rowKey="status"
                    dataSource={stages}
                    pagination={false}
                    size="small"
                    columns={[
                        {
                            title: "#",
                            render: (_, __, i) => i + 1,
                            width: 50,
                        },
                        { title: "Stage", dataIndex: "label" },
                        {
                            title: "Filled by",
                            render: (_, stage, i) =>
                                stage.source === "role" ? (
                                    <Select
                                        size="small"
                                        style={{ width: 180 }}
                                        value={stage.role}
                                        options={roleOptions}
                                        onChange={(role) => setStage(i, { role })}
                                    />
                                ) : (
                                    <Tag>
                                        {stage.slot === "head_user_id"
                                            ? "The unit's head"
                                            : "The unit's VP"}
                                    </Tag>
                                ),
                        },
                        {
                            title: "Skippable",
                            width: 110,
                            render: (_, stage, i) => (
                                <Switch
                                    size="small"
                                    checked={stage.skippable ?? true}
                                    disabled={i === stages.length - 1}
                                    onChange={(skippable) => setStage(i, { skippable })}
                                />
                            ),
                        },
                        {
                            title: "",
                            width: 120,
                            render: (_, __, i) => (
                                <Space size={4}>
                                    <Button size="small" disabled={i === 0} onClick={() => moveStage(i, -1)}>
                                        ↑
                                    </Button>
                                    <Button
                                        size="small"
                                        disabled={i === stages.length - 1}
                                        onClick={() => moveStage(i, 1)}
                                    >
                                        ↓
                                    </Button>
                                </Space>
                            ),
                        },
                    ]}
                />
            </Card>

            <Card
                title="Who may hand work out"
                extra={<ResetButton target="delegation" />}
                style={{ marginBottom: 16 }}
            >
                <Row gutter={16}>
                    <Col xs={24} md={8}>
                        <Typography.Text strong>Assigns an MFO/PPA</Typography.Text>
                        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                            Hands over a whole heading; the assignee writes their own lines under it.
                        </Typography.Paragraph>
                        <Select
                            mode="multiple"
                            style={{ width: "100%" }}
                            value={delegation.assign_outputs ?? []}
                            options={actingRoleOptions}
                            onChange={(v) => put("delegation", { ...delegation, assign_outputs: v })}
                        />
                    </Col>
                    <Col xs={24} md={8}>
                        <Typography.Text strong>Assigns a success indicator</Typography.Text>
                        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                            Hands over one commitment, seeded with its wording.
                        </Typography.Paragraph>
                        <Select
                            mode="multiple"
                            style={{ width: "100%" }}
                            value={delegation.assign_indicators ?? []}
                            options={actingRoleOptions}
                            onChange={(v) => put("delegation", { ...delegation, assign_indicators: v })}
                        />
                    </Col>
                    <Col xs={24} md={8}>
                        <Typography.Text strong>Bottom of the chain</Typography.Text>
                        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                            Their commitments are theirs to deliver and cannot be passed on again.
                        </Typography.Paragraph>
                        <Select
                            mode="multiple"
                            style={{ width: "100%" }}
                            value={delegation.terminal_roles ?? []}
                            options={roleOptions}
                            onChange={(v) => put("delegation", { ...delegation, terminal_roles: v })}
                        />
                    </Col>
                </Row>
            </Card>

            <Card title="The college OPCR" extra={<ResetButton target="opcr" />} style={{ marginBottom: 16 }}>
                <Row gutter={16}>
                    {[
                        ["creator_roles", "Opens it"],
                        ["approver_roles", "Approves the targets"],
                        ["publisher_roles", "Publishes and unpublishes"],
                        ["rating_trigger_roles", "Sends it for rating"],
                    ].map(([slot, label]) => (
                        <Col xs={24} md={6} key={slot}>
                            <Typography.Text strong>{label}</Typography.Text>
                            <Select
                                mode="multiple"
                                style={{ width: "100%", marginTop: 8 }}
                                value={opcr[slot] ?? []}
                                options={
                                    ["creator_roles", "publisher_roles"].includes(slot)
                                        ? actingRoleOptions
                                        : roleOptions
                                }
                                onChange={(v) => put("opcr", { ...opcr, [slot]: v })}
                            />
                        </Col>
                    ))}
                </Row>

                <Divider />

                <Space>
                    <Typography.Text strong>How many?</Typography.Text>
                    <Select
                        style={{ width: 280 }}
                        value={opcr.one_per ?? "organization"}
                        onChange={(v) => put("opcr", { ...opcr, one_per: v })}
                        options={[
                            { value: "organization", label: "One for the whole college each year" },
                            { value: "org_unit", label: "One per office each year" },
                        ]}
                    />
                </Space>
            </Card>

            <Card title="Rating scale" extra={<ResetButton target="rating" />}>
                <Space wrap size={16} style={{ marginBottom: 16 }}>
                    <span>
                        <Typography.Text strong>Score range</Typography.Text>{" "}
                        <InputNumber
                            size="small"
                            min={0}
                            max={10}
                            value={rating.min}
                            onChange={(min) => put("rating", { ...rating, min })}
                        />{" "}
                        to{" "}
                        <InputNumber
                            size="small"
                            min={1}
                            max={100}
                            value={rating.max}
                            onChange={(max) => put("rating", { ...rating, max })}
                        />
                    </span>
                    <span>
                        <Typography.Text strong>Dimensions</Typography.Text>{" "}
                        <Checkbox.Group
                            value={rating.dimensions ?? []}
                            options={[
                                { value: "q", label: "Quality" },
                                { value: "e", label: "Efficiency" },
                                { value: "t", label: "Timeliness" },
                            ]}
                            onChange={(dimensions) => put("rating", { ...rating, dimensions })}
                        />
                    </span>
                </Space>

                <Table
                    rowKey="label"
                    dataSource={rating.bands ?? []}
                    pagination={false}
                    size="small"
                    columns={[
                        { title: "Adjectival rating", dataIndex: "label" },
                        {
                            title: "Average at least",
                            width: 200,
                            render: (_, band, i) => (
                                <InputNumber
                                    size="small"
                                    step={0.1}
                                    min={0}
                                    value={band.min}
                                    onChange={(min) =>
                                        put("rating", {
                                            ...rating,
                                            bands: rating.bands.map((b, j) =>
                                                j === i ? { ...b, min } : b
                                            ),
                                        })
                                    }
                                />
                            ),
                        },
                        { title: "Equivalent", dataIndex: "value", width: 120 },
                    ]}
                />
            </Card>
        </>
    );
}
