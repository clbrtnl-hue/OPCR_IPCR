import React from "react";
import { Button, Card, Empty, Space, Table, Tag } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { STATUS_META } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import { useAuth } from "~/hooks/useAuth";

export default function ReviewQueuePage() {
    const navigate = useNavigate();
    const { user } = useAuth();

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["review-queue"],
        queryFn: () => api.get("pcr-forms?queue=1").then((r) => r.data),
    });

    const columns = [
        {
            title: "Form",
            dataIndex: "type",
            render: (type) => <Tag color={type === "opcr" ? "geekblue" : "blue"}>{type.toUpperCase()}</Tag>,
        },
        {
            title: "Ratee",
            dataIndex: ["owner", "name"],
            render: (v, record) => v || `${record.org_unit?.name} (office)`,
        },
        { title: "Unit", dataIndex: ["org_unit", "name"] },
        { title: "School year", dataIndex: ["school_year", "label"] },
        {
            title: "Period",
            dataIndex: ["rating_period", "label"],
            render: (label) => label ?? "Whole year",
        },
        {
            title: "Submitted",
            dataIndex: "submitted_at",
            render: (v) => (v ? dayjs(v).format("MMM D, YYYY") : "—"),
        },
        {
            title: "Status",
            dataIndex: "status",
            render: (status) => <Tag color={STATUS_META[status].color}>{STATUS_META[status].label}</Tag>,
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Button type="primary" size="small" onClick={() => navigate(`/forms/${record.id}`)}>
                    {record.status === "qa_approval" ? "Approve" : record.status === "qa_rating" ? "Rate" : "Review"}
                </Button>
            ),
        },
    ];

    const subtitle =
        user?.role === "qa"
            ? "OPCRs waiting for your approval and forms ready for rating."
            : user?.role === "vp"
              ? "Forms the heads have endorsed and that are waiting for your review."
              : "Forms your unit has submitted and that are waiting for your review.";

    return (
        <>
            <PageHeader title="Review Queue" subtitle={subtitle} />

            <Card>
                {queue.length === 0 && !isLoading ? (
                    <Empty description="Nothing is waiting for you right now." />
                ) : (
                    <Table
                        rowKey="id"
                        loading={isLoading}
                        dataSource={queue}
                        columns={columns}
                        pagination={{ pageSize: 15 }}
                        scroll={{ x: 900 }}
                    />
                )}
            </Card>
        </>
    );
}
