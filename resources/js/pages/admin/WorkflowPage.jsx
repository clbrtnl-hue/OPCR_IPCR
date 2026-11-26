import React, { useState } from "react";
import {
    Alert,
    Button,
    Card,
    Checkbox,
    Col,
    Divider,
    Input,
    InputNumber,
    Popconfirm,
    Row,
    Segmented,
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

function slotLabel(slot) {
    return {
        head_user_id: "The unit's head",
        dean_user_id: "The unit's dean",
        director_review: "The unit's director",
        vp_user_id: "The unit's VP",
    }[slot] ?? "The unit";
}

const KEY_LABELS = {
    review_stages: "Review chain",
    delegation: "Who may hand work out",
    opcr: "The college OPCR",
    rating: "Rating scale",
};

export default function WorkflowPage() {
    const queryClient = useQueryClient();
    const [stepName, setStepName] = useState("");

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

    const setStage = (index, patch) => {
        const nextPatch = { ...patch };

        if (index === stages.length - 1 && patch.role) {
            nextPatch.source = "role";
            nextPatch.skippable = false;

            if (patch.role === "president") nextPatch.label = "With the President";
            if (patch.role === "qa") nextPatch.label = "With QA";
        }

        put(
            "review_stages",
            stages.map((s, i) => (i === index ? { ...s, ...nextPatch } : s))
        );
    };

    const moveStage = (index, by) => {
        const next = [...stages];
        const [row] = next.splice(index, 1);
        next.splice(index + by, 0, row);
        put("review_stages", next);
    };

    const addStage = () => {
        const name = stepName.trim();

        if (!name) return;

        const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "") || "step";
        const status = `step_${slug}_${Date.now().toString(36)}`;
        const stage = {
            status,
            label: /^with /i.test(name) ? name : `With ${name}`,
            source: "unit_slot",
            slot: status,
            skippable: true,
        };
        const next = [...stages];
        next.splice(Math.max(stages.length - 1, 0), 0, stage);
        setStepName("");
        put("review_stages", next);
    };

    const removeStage = (index) => {
        put(
            "review_stages",
            stages.filter((_, i) => i !== index)
        );
    };

    const last = stages.length - 1;
    const raterRole = stages[last]?.role ?? "qa";
    const raterOptions = [
        { value: "qa", label: "QA" },
        { value: "president", label: "President" },
        ...(raterRole && !["qa", "president"].includes(raterRole)
            ? [{ value: raterRole, label: ROLE_LABELS[raterRole] ?? raterRole }]
            : []),
    ];

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
                className="pms-list-panel"
                title="Review chain"
                extra={<ResetButton target="review_stages" />}
                style={{ marginBottom: 16 }}
            >
                <Typography.Paragraph type="secondary">
                    An IPCR walks these steps in order. An empty step is passed over — a head&apos;s
                    own form skips the head, and a unit with no dean or director skips those.
                    Name the person for a new step on Setup → Hierarchy.
                </Typography.Paragraph>

                <div className="pms-chain-edit">
                    {stages.slice(0, -1).map((stage, i) => (
                        <div key={stage.status} className="pms-chain-edit-row">
                            <Input
                                size="small"
                                defaultValue={stage.label}
                                key={`${stage.status}-${stage.label}`}
                                onBlur={(e) => {
                                    const next = e.target.value.trim();
                                    if (next && next !== stage.label) setStage(i, { label: next });
                                }}
                                onPressEnter={(e) => e.currentTarget.blur()}
                            />
                            {stage.source === "role" ? (
                                <Select
                                    size="small"
                                    value={stage.role}
                                    options={roleOptions}
                                    onChange={(role) => setStage(i, { role })}
                                />
                            ) : (
                                <Tag style={{ margin: 0, justifySelf: "start" }}>{slotLabel(stage.slot)}</Tag>
                            )}
                            <div className="pms-chain-edit-tools">
                                <span className="pms-chain-edit-skip">
                                    <Switch
                                        size="small"
                                        checked={stage.skippable ?? true}
                                        onChange={(skippable) => setStage(i, { skippable })}
                                    />
                                    Skip
                                </span>
                                <Button type="text" size="small" disabled={i === 0} onClick={() => moveStage(i, -1)}>
                                    Up
                                </Button>
                                <Button type="text" size="small" disabled={i >= last - 1} onClick={() => moveStage(i, 1)}>
                                    Down
                                </Button>
                                <Popconfirm title="Remove this step?" onConfirm={() => removeStage(i)}>
                                    <Button type="text" size="small" danger>
                                        Remove
                                    </Button>
                                </Popconfirm>
                            </div>
                        </div>
                    ))}
                    <div className="pms-chain-edit-add">
                        <Space.Compact style={{ width: "100%" }}>
                            <Input
                                placeholder="New step, for example Coordinator"
                                value={stepName}
                                onChange={(e) => setStepName(e.target.value)}
                                onPressEnter={addStage}
                            />
                            <Button type="primary" onClick={addStage} disabled={!stepName.trim() || save.isPending}>
                                Add step
                            </Button>
                        </Space.Compact>
                    </div>
                    {last >= 0 && (
                        <div className="pms-chain-edit-rate">
                            <div>
                                <strong>Rates the IPCR</strong>
                                <span>
                                    {raterRole === "president"
                                        ? "The last step. The President rates it and closes the form."
                                        : raterRole === "qa"
                                          ? "The last step. QA rates it. QA’s own IPCR still goes to the President."
                                          : `The last step. ${ROLE_LABELS[raterRole] ?? raterRole} rates it and closes the form.`}
                                </span>
                            </div>
                            <Segmented
                                value={raterRole}
                                options={raterOptions}
                                onChange={(role) => setStage(last, { role })}
                                disabled={save.isPending}
                            />
                        </div>
                    )}
                </div>
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
                            options={roleOptions}
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
                            options={roleOptions}
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

            <Card
                title="The college OPCR"
                extra={<ResetButton target="opcr" />}
                style={{ marginBottom: 16 }}
            >
                <Row gutter={16}>
                    {[
                        ["creator_roles", "Opens it"],
                        ["approver_roles", "Approves the targets"],
                        ["publisher_roles", "Publishes it"],
                        ["rating_trigger_roles", "Sends it for rating"],
                    ].map(([slot, label]) => (
                        <Col xs={24} md={6} key={slot}>
                            <Typography.Text strong>{label}</Typography.Text>
                            <Select
                                mode="multiple"
                                style={{ width: "100%", marginTop: 8 }}
                                value={opcr[slot] ?? []}
                                options={roleOptions}
                                onChange={(v) => put("opcr", { ...opcr, [slot]: v })}
                            />
                        </Col>
                    ))}
                </Row>

                <Divider />

                <Space wrap>
                    <Typography.Text strong>How many?</Typography.Text>
                    <Select
                        className="pms-workflow-count"
                        value={opcr.one_per ?? "organization"}
                        onChange={(v) => put("opcr", { ...opcr, one_per: v })}
                        options={[
                            { value: "organization", label: "One for the whole college each year" },
                            { value: "org_unit", label: "One per office each year" },
                        ]}
                    />
                </Space>
            </Card>

            <Card className="pms-list-panel" title="Rating scale" extra={<ResetButton target="rating" />}>
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

                <div className="pms-mobile-only pms-pds-cards">
                    {(rating.bands ?? []).map((band, i) => (
                        <article key={band.label} className="pms-pds-card">
                            <div className="pms-pds-line">
                                <span>Rating</span>
                                <strong>{band.label}</strong>
                            </div>
                            <div className="pms-pds-line">
                                <span>Equivalent</span>
                                <strong>{band.value}</strong>
                            </div>
                            <div className="pms-pds-actions">
                                <span>Average at least</span>
                                <InputNumber
                                    size="small"
                                    step={0.1}
                                    min={0}
                                    value={band.min}
                                    onChange={(min) =>
                                        put("rating", {
                                            ...rating,
                                            bands: rating.bands.map((b, j) => (j === i ? { ...b, min } : b)),
                                        })
                                    }
                                />
                            </div>
                        </article>
                    ))}
                </div>
                <div className="pms-desktop-only">
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
                </div>
            </Card>
        </>
    );
}
