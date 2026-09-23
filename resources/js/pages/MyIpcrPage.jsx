import React from "react";
import { Alert, Card, Empty, Segmented, Select, Space, Spin } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { useAuth } from "~/hooks/useAuth";
import FormEditorPage from "~/pages/FormEditorPage";

/**
 * An IPCR is filed twice a year — one form per rating period. This resolves
 * both of the signed-in person's IPCRs for the chosen year, opens the one for
 * the selected period, and quietly opens a draft when it does not exist yet.
 */
export default function MyIpcrPage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [yearId, setYearId] = React.useState(null);
    const [periodId, setPeriodId] = React.useState(null);
    const requested = React.useRef({});

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const activeYear = years.find((y) => y.is_active) ?? years[0];
    const year = years.find((y) => y.id === (yearId ?? activeYear?.id));
    const periods = [...(year?.periods ?? [])].sort((a, b) => a.seq - b.seq);
    const chosenPeriodId =
        periodId ?? periods.find((p) => p.is_active)?.id ?? periods[0]?.id;

    const { data: forms = [], isLoading } = useQuery({
        queryKey: ["my-ipcrs", year?.id],
        queryFn: () =>
            api.get(`pcr-forms?type=ipcr&school_year_id=${year.id}&mine=1`).then((r) => r.data),
        enabled: Boolean(year?.id),
    });

    const mine = forms.filter((f) => f.user_id === user.id);
    const form = mine.find((f) => f.rating_period_id === chosenPeriodId);

    const open = useMutation({
        mutationFn: (targetPeriodId) =>
            api.post("pcr-forms", {
                type: "ipcr",
                school_year_id: year.id,
                org_unit_id: user.org_unit_id,
                rating_period_id: targetPeriodId,
            }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["my-ipcrs"] });
            queryClient.invalidateQueries({ queryKey: ["pcr-forms"] });
        },
        onError: (error) => {
            if (error?.response?.status === 409 && error.response.data?.form_id) {
                queryClient.invalidateQueries({ queryKey: ["my-ipcrs"] });
            }
        },
    });

    React.useEffect(() => {
        if (
            !isLoading &&
            year?.id &&
            chosenPeriodId &&
            user?.org_unit_id &&
            !form &&
            !requested.current[chosenPeriodId]
        ) {
            requested.current[chosenPeriodId] = true;
            open.mutate(chosenPeriodId);
        }
    }, [isLoading, year?.id, chosenPeriodId, user?.org_unit_id, form]);

    if (isLoading || !year) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    if (!user.org_unit_id) {
        return (
            <>
                <PageHeader
                    title="My IPCR"
                    subtitle="Your Individual Performance Commitment and Review — one for each review period."
                />
                <Card>
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description="You are not placed in an office yet. Ask an administrator to add you to the hierarchy first."
                    />
                </Card>
            </>
        );
    }

    const switcher = (
        <Space wrap style={{ marginBottom: 12 }}>
            <Segmented
                value={chosenPeriodId}
                onChange={setPeriodId}
                options={periods.map((p) => ({
                    value: p.id,
                    label: p.is_active ? `${p.label} ★` : p.label,
                }))}
            />
            {years.length > 1 && (
                <Select
                    style={{ width: 160 }}
                    value={year.id}
                    onChange={(value) => {
                        setYearId(value);
                        setPeriodId(null);
                    }}
                    options={years.map((y) => ({
                        value: y.id,
                        label: y.is_active ? `${y.label} (active)` : y.label,
                    }))}
                />
            )}
        </Space>
    );

    if (form) {
        return (
            <>
                {switcher}
                <FormEditorPage key={form.id} formId={form.id} />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title="My IPCR"
                subtitle="Your Individual Performance Commitment and Review — one for each review period."
                extra={switcher}
            />

            {open.isError ? (
                <Alert
                    type="error"
                    showIcon
                    message="Your IPCR could not be opened."
                    description={open.error?.response?.data?.message}
                />
            ) : (
                <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                    <Spin size="large" />
                </div>
            )}
        </>
    );
}
