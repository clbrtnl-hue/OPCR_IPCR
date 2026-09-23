import React from "react";
import { Button, Card, Empty, Select, Space, Spin, Typography, message } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { useAuth } from "~/hooks/useAuth";
import FormEditorPage from "~/pages/FormEditorPage";

/**
 * The college writes one OPCR a year, so it deserves its own place rather than
 * being hunted for among everybody's forms. This resolves the year's OPCR and
 * opens it; if the year has none yet, the president opens it from here.
 */
export default function CollegeOpcrPage() {
    const navigate = useNavigate();
    const { user, can } = useAuth();
    const queryClient = useQueryClient();
    const [yearId, setYearId] = React.useState(null);

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const activeYear = years.find((y) => y.is_active) ?? years[0];
    const chosen = yearId ?? activeYear?.id;

    const { data: forms = [], isLoading } = useQuery({
        queryKey: ["opcr", chosen],
        queryFn: () => api.get(`pcr-forms?type=opcr&school_year_id=${chosen}`).then((r) => r.data),
        enabled: Boolean(chosen),
    });

    const opcr = forms[0];

    const open = useMutation({
        mutationFn: () =>
            api.post("pcr-forms", {
                type: "opcr",
                school_year_id: chosen,
                org_unit_id: user.org_unit_id,
            }),
        onSuccess: ({ data }) => {
            message.success("OPCR opened. Add the college's MFO/PPAs, then send it to QA.");
            queryClient.invalidateQueries({ queryKey: ["opcr"] });
            navigate(`/forms/${data.form.id}`);
        },
    });

    if (isLoading) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    // The sheet lives at this address, so the sidebar knows where we are.
    if (opcr) {
        return <FormEditorPage formId={opcr.id} />;
    }

    return (
        <>
            <PageHeader
                title="College OPCR"
                subtitle="The college's Office Performance Commitment and Review — one for each year."
                extra={
                    years.length > 1 && (
                        <Select
                            style={{ width: 180 }}
                            value={chosen}
                            onChange={setYearId}
                            options={years.map((y) => ({
                                value: y.id,
                                label: y.is_active ? `${y.label} (active)` : y.label,
                            }))}
                        />
                    )
                }
            />

            <Card>
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description={
                        <Space direction="vertical" size={4}>
                            <Typography.Text strong>
                                No OPCR for {activeYear?.label ?? "this year"} yet
                            </Typography.Text>
                            <Typography.Text type="secondary">
                                {can("president")
                                    ? "Open it, list the college's MFO/PPAs and their success indicators, then send it to QA for approval."
                                    : "The president opens the college OPCR for the year."}
                            </Typography.Text>
                        </Space>
                    }
                >
                    {can("president") && (
                        <Button
                            type="primary"
                            icon={<PlusOutlined />}
                            loading={open.isPending}
                            onClick={() => open.mutate()}
                        >
                            Open the {activeYear?.label} OPCR
                        </Button>
                    )}
                </Empty>
            </Card>
        </>
    );
}
