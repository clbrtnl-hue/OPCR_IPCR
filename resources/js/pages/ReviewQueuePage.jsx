import React from "react";
import { Button, Card, Empty, Space, Table, Tag } from "antd";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import dayjs from "dayjs";
import api from "~/utils/api";
import { STATUS_META } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import FormCards from "~/components/FormCards";
import { useAuth } from "~/hooks/useAuth";

const REVIEW_ACTION = (record) =>
    record.status === "qa_approval"
        ? "Approve"
        : ["qa_rating", "head_review", "vp_review"].includes(record.status)
          ? "Rate"
          : "Review";

export default function ReviewQueuePage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const viewOnly = user?.role === "admin";

    const { data: queue = [], isLoading } = useQuery({
        queryKey: ["review-queue", viewOnly ? "oversight" : "mine"],
        queryFn: () =>
            api.get(viewOnly ? "pcr-forms?oversight=review" : "pcr-forms?queue=1").then((r) => r.data),
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
                    {viewOnly ? "View" : REVIEW_ACTION(record)}
                </Button>
            ),
        },
    ];

    const subtitle = viewOnly
        ? "Forms waiting on a reviewer. You can open them, but you cannot approve or send them on."
        : user?.role === "qa"
          ? "OPCRs waiting for your approval and forms ready for rating."
          : user?.role === "vp"
            ? "Forms the heads have endorsed and that are waiting for your review."
            : "Forms your unit has submitted and that are waiting for your review.";

    return (
        <>
            <PageHeader title="Review Queue" subtitle={subtitle} />

            <Card>
                {queue.length === 0 && !isLoading ? (
                    <Empty
                        description={
                            viewOnly
                                ? "Nothing is in review right now."
                                : "Nothing is waiting for you right now."
                        }
                    />
                ) : (
                    <>
                        <div className="pms-mobile-only">
                            <FormCards
                                forms={queue}
                                showSubmitted
                                actionLabel={(record) => (viewOnly ? "View" : REVIEW_ACTION(record))}
                                onOpen={(record) => navigate(`/forms/${record.id}`)}
                            />
                        </div>
                        <div className="pms-desktop-only">
                            <Table
                                rowKey="id"
                                loading={isLoading}
                                dataSource={queue}
                                columns={columns}
                                pagination={{ pageSize: 15 }}
                                scroll={{ x: 900 }}
                            />
                        </div>
                    </>
                )}
            </Card>
        </>
    );
}
