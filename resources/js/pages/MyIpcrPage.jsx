import React from "react";
import { Alert, Card, Empty, Segmented, Select, Space, Spin } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { useAuth } from "~/hooks/useAuth";
import FormEditorPage from "~/pages/FormEditorPage";

const SHORT_MONTH = {
    January: "Jan",
    February: "Feb",
    March: "Mar",
    April: "Apr",
    May: "May",
    June: "Jun",
    July: "Jul",
    August: "Aug",
    September: "Sep",
    October: "Oct",
    November: "Nov",
    December: "Dec",
};

function shortPeriod(label) {
    return label
        .replace(
            /January|February|March|April|May|June|July|August|September|October|November|December/g,
            (month) => SHORT_MONTH[month]
        )
        .replace(/\s+to\s+/g, "–");
}

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

    const periodOptions = (shorten) =>
        periods.map((p) => ({
            value: p.id,
            label: p.is_active
                ? `${shorten ? shortPeriod(p.label) : p.label} ★`
                : shorten
                  ? shortPeriod(p.label)
                  : p.label,
        }));

    const yearOptions = years.map((y) => ({
        value: y.id,
        label: y.is_active ? `${y.label} (active)` : y.label,
    }));

    const yearControl = (compact) =>
        years.length > 1 ? (
            <Select
                className="pms-ipcr-year"
                size={compact ? "small" : "middle"}
                style={compact ? undefined : { width: 160 }}
                value={year.id}
                onChange={(value) => {
                    setYearId(value);
                    setPeriodId(null);
                }}
                options={yearOptions}
            />
        ) : null;

    const switcher = (placement) => (
        <Space wrap className={`pms-ipcr-switch is-${placement}`} style={{ marginBottom: 12 }}>
            <Segmented
                className="pms-ipcr-wide"
                value={chosenPeriodId}
                onChange={setPeriodId}
                options={periodOptions(false)}
            />
            <Segmented
                className="pms-ipcr-periods"
                block
                value={chosenPeriodId}
                onChange={setPeriodId}
                options={periodOptions(true)}
            />
            {placement === "top" && yearControl(false)}
        </Space>
    );

    return (
        <>
            <div className="pms-desktop-only">{switcher("top")}</div>
            {form ? (
                <FormEditorPage
                    key={form.id}
                    formId={form.id}
                    periodBar={<div className="pms-mobile-only">{switcher("below")}</div>}
                    yearSelect={
                        years.length > 1 ? (
                            <span className="pms-mobile-only pms-ipcr-year-slot">{yearControl(true)}</span>
                        ) : null
                    }
                />
            ) : open.isError ? (
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
