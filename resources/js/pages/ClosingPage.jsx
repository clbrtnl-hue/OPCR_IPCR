import React, { useState } from "react";
import { Alert, Button, Card, Empty, Popconfirm, Select, Space, Table, Tag, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { ADJECTIVAL_COLORS, statusMeta } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";

const score = (value) => (value == null ? "—" : Number(value).toFixed(2));

export default function ClosingPage() {
    const queryClient = useQueryClient();
    const [pickedId, setPickedId] = useState(null);
    const { data, isLoading } = useQuery({
        queryKey: ["hr-closing"],
        queryFn: () => api.get("hr/closing").then((r) => r.data),
    });

    const closeOffice = useMutation({
        mutationFn: (officeId) =>
            api.post(`hr/closing/${officeId}`, { school_year_id: data.school_year.id }),
        onSuccess: (result) => {
            message.success(`Closed ${result.data.closed} form${result.data.closed === 1 ? "" : "s"}.`);
            queryClient.invalidateQueries({ queryKey: ["hr-closing"] });
        },
    });

    const closeOpcr = useMutation({
        mutationFn: () => api.post(`pcr-forms/${data.opcr.id}/status`, { status: "final" }),
        onSuccess: () => {
            message.success("College OPCR closed.");
            queryClient.invalidateQueries({ queryKey: ["hr-closing"] });
        },
    });

    const sendBack = useMutation({
        mutationFn: (formId) => api.post(`pcr-forms/${formId}/status`, { status: "qa_rating" }),
        onSuccess: () => {
            message.success("Sent back to QA.");
            queryClient.invalidateQueries({ queryKey: ["hr-closing"] });
            queryClient.invalidateQueries({ queryKey: ["qa-queue"] });
        },
    });

    const opcr = data?.opcr;
    const offices = data?.offices ?? [];
    const opcrScore = opcr?.has_rating ? opcr.final_average : null;
    const office = offices.find((item) => item.id === pickedId) ?? offices[0] ?? null;

    const columns = [
        { title: "Person", dataIndex: "owner", render: (name) => name || "—" },
        { title: "Period", dataIndex: "period", render: (label) => label || "—" },
        {
            title: "Rating",
            key: "rating",
            render: (_, row) =>
                row.final_average == null ? (
                    "—"
                ) : (
                    <Space size={6}>
                        <span>{score(row.final_average)}</span>
                        {row.adjectival && <Tag color={ADJECTIVAL_COLORS[row.adjectival]}>{row.adjectival}</Tag>}
                    </Space>
                ),
        },
        {
            title: "Status",
            dataIndex: "status",
            render: (status) => <Tag color={statusMeta(status).color}>{statusMeta(status).label}</Tag>,
        },
        ...(office?.over
            ? [
                  {
                      title: "",
                      key: "actions",
                      render: (_, row) =>
                          row.status === "rated" ? (
                              <Button size="small" danger loading={sendBack.isPending} onClick={() => sendBack.mutate(row.id)}>
                                  Send back to QA
                              </Button>
                          ) : null,
                  },
              ]
            : []),
    ];

    return (
        <>
            <PageHeader
                title="Close ratings"
                subtitle="Rated IPCRs wait here until the college OPCR is rated. The average is worked out for you. You still check it, then close the office."
            />

            <Card style={{ marginBottom: 16 }} loading={isLoading}>
                {!opcr ? (
                    <Empty description="This year has no college OPCR yet." />
                ) : (
                    <Space wrap size={16} align="center">
                        <div>
                            <Typography.Text type="secondary">College OPCR</Typography.Text>
                            <div>
                                <Typography.Text strong style={{ fontSize: 20 }}>
                                    {score(opcr.final_average)}
                                </Typography.Text>
                                {opcr.adjectival && (
                                    <Tag color={ADJECTIVAL_COLORS[opcr.adjectival]} style={{ marginLeft: 8 }}>
                                        {opcr.adjectival}
                                    </Tag>
                                )}
                                <Tag color={statusMeta(opcr.status).color} style={{ marginLeft: 8 }}>
                                    {statusMeta(opcr.status).label}
                                </Tag>
                            </div>
                        </div>
                        {opcr.status === "rated" && (
                            <Popconfirm
                                title="Close the college OPCR?"
                                description="It becomes final and can no longer be edited."
                                okText="Close OPCR"
                                onConfirm={() => closeOpcr.mutate()}
                            >
                                <Button type="primary" loading={closeOpcr.isPending}>
                                    Close OPCR
                                </Button>
                            </Popconfirm>
                        )}
                    </Space>
                )}
            </Card>

            {!isLoading && offices.length === 0 && (
                <Card>
                    <Empty description="No IPCRs for this year yet." />
                </Card>
            )}

            {office && (
                <Card
                    title={
                        <Select
                            value={office.id}
                            onChange={setPickedId}
                            style={{ minWidth: 280 }}
                            options={offices.map((item) => ({ value: item.id, label: item.name }))}
                            aria-label="Office"
                        />
                    }
                    extra={
                        <Space>
                            <Typography.Text type="secondary">
                                Average {score(office.average)}
                                {office.scored !== office.total ? ` from ${office.scored} of ${office.total}` : ""}
                            </Typography.Text>
                            {opcrScore != null && office.over && <Tag color="red">Above the OPCR</Tag>}
                            {opcrScore != null && office.can_close && <Tag color="green">Within the OPCR</Tag>}
                        </Space>
                    }
                >
                    {office.reason && (
                        <Alert type={office.over ? "error" : "info"} showIcon message={office.reason} style={{ marginBottom: 16 }} />
                    )}

                    <Table
                        rowKey="id"
                        pagination={false}
                        dataSource={office.forms}
                        scroll={{ x: 640 }}
                        columns={columns}
                    />

                    <div style={{ marginTop: 16 }}>
                        <Popconfirm
                            title={`Close ${office.name}?`}
                            description="Every rated IPCR in this office becomes final."
                            okText="Close office"
                            disabled={!office.can_close}
                            onConfirm={() => closeOffice.mutate(office.id)}
                        >
                            <Button type="primary" disabled={!office.can_close} loading={closeOffice.isPending}>
                                Close office
                            </Button>
                        </Popconfirm>
                        {opcrScore != null && (
                            <Typography.Text type="secondary" style={{ marginLeft: 12 }}>
                                College OPCR is {score(opcrScore)}.
                            </Typography.Text>
                        )}
                    </div>
                </Card>
            )}
        </>
    );
}
