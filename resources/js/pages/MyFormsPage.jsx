import React, { useState } from "react";
import { Button, Card, Drawer, Empty, Form, Select, Space, Table, Tabs, Tag, message } from "antd";
import { PlusOutlined /* , TeamOutlined */ } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "~/utils/api";
import { statusMeta } from "~/utils/constants";
import PageHeader from "~/components/PageHeader";
import FormCards from "~/components/FormCards";
import FormListFilters, { matchesListKind } from "~/components/FormListFilters";
import ListPager, { tablePager, usePaged } from "~/components/ListPager";
import { useAuth } from "~/hooks/useAuth";
// import BulkIpcrModal from "~/components/BulkIpcrModal";

function byYear(a, b) {
    const year = String(b.school_year?.label ?? "").localeCompare(String(a.school_year?.label ?? ""), undefined, {
        numeric: true,
        sensitivity: "base",
    });

    return year || (a.rating_period?.seq ?? 0) - (b.rating_period?.seq ?? 0) || a.id - b.id;
}

export default function MyFormsPage() {
    const { user, can } = useAuth();
    const isHr = user?.role === "hr";
    const [list, setList] = useState("mine");
    const collegeList = isHr && list === "all";
    const viewOnly = user?.role === "admin" || collegeList;
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [open, setOpen] = useState(false);
    const [formKind, setFormKind] = useState("all");
    // const [bulkOpen, setBulkOpen] = useState(false);

    const { data: forms = [], isLoading } = useQuery({
        queryKey: ["pcr-forms", collegeList ? "college" : "mine"],
        queryFn: () => api.get(collegeList ? "pcr-forms" : "pcr-forms?mine=1").then((r) => r.data),
    });

    const { data: years = [] } = useQuery({
        queryKey: ["school-years"],
        queryFn: () => api.get("school-years").then((r) => r.data),
    });

    const { data: units = [] } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const create = useMutation({
        mutationFn: (values) => api.post("pcr-forms", values),
        onSuccess: ({ data }) => {
            message.success("Form created. Add your outputs and success indicators.");
            queryClient.invalidateQueries({ queryKey: ["pcr-forms"] });
            setOpen(false);
            form.resetFields();
            navigate(`/forms/${data.form.id}`, { state: { from: "/my-forms" } });
        },
    });

    const activeFormKind = formKind === "all" || forms.some((row) => row.type === formKind) ? formKind : "all";
    const visibleForms = forms.filter((row) => matchesListKind(row, activeFormKind));
    const shownForms = [...visibleForms].sort(byYear);
    const formPage = usePaged(shownForms, 10);
    const activeYear = years.find((y) => y.is_active);
    const chosenType = Form.useWatch("type", form);
    const chosenYearId = Form.useWatch("school_year_id", form);
    const chosenYear = years.find((y) => y.id === chosenYearId);
    const periodOptions = [...(chosenYear?.periods ?? [])].sort((a, b) => a.seq - b.seq);

    const columns = [
        {
            title: "Form",
            dataIndex: "type",
            render: (type) => <Tag color={type === "opcr" ? "geekblue" : "blue"}>{type.toUpperCase()}</Tag>,
        },
        {
            title: "School year",
            key: "year",
            render: (_, record) => record.school_year?.label,
        },
        {
            title: "Period",
            key: "period",
            render: (_, record) => record.rating_period?.label ?? "Whole year",
        },
        {
            title: "Unit",
            key: "unit",
            render: (_, record) => record.org_unit?.name,
        },
        {
            title: "Ratee",
            key: "ratee",
            render: (_, record) => record.owner?.name || `${record.org_unit?.name} (office)`,
        },
        {
            title: "Outputs",
            dataIndex: "outputs_count",
        },
        {
            title: "Status",
            dataIndex: "status",
            render: (status, record) => {
                const meta = statusMeta(status, record);
                return <Tag color={meta.color}>{meta.label}</Tag>;
            },
        },
        {
            title: "",
            key: "actions",
            render: (_, record) => (
                <Space>
                    <Button size="small" type="primary" onClick={() => navigate(`/forms/${record.id}`, { state: { from: "/my-forms" } })}>
                        {viewOnly ? "View" : "Open"}
                    </Button>
                    <Button size="small" onClick={() => window.open(`/forms/${record.id}/print`, "_blank")}>
                        Print
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title={isHr || user?.role === "admin" ? "Forms" : "My Forms"}
                subtitle={
                    collegeList || user?.role === "admin"
                        ? "Every IPCR and OPCR in the college, and where each one sits."
                        : "Your performance commitment for each school year, and where it currently sits."
                }
                extra={
                    viewOnly ? null : (
                    <Space wrap>
                    {/* {can("admin", "program_head", "vp") && (
                        <Button icon={<TeamOutlined />} onClick={() => setBulkOpen(true)}>
                            Open IPCRs for an office
                        </Button>
                    )} */}
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        onClick={() => {
                            const opensOpcr = can("president");

                            form.setFieldsValue({
                                type: opensOpcr ? "opcr" : "ipcr",
                                school_year_id: activeYear?.id,
                                rating_period_id: activeYear?.periods?.find((p) => p.is_active)?.id,
                                // An administrator writes the OPCR for an office,
                                // not for the unit they happen to sit in.
                                org_unit_id: opensOpcr ? undefined : user?.org_unit_id,
                            });
                            setOpen(true);
                        }}
                    >
                        New form
                    </Button>
                    </Space>
                    )
                }
            />

            {isHr && (
                <Tabs
                    className="pms-line-tabs"
                    activeKey={list}
                    onChange={(key) => {
                        setList(key);
                        setFormKind("all");
                    }}
                    style={{ marginBottom: 16 }}
                    items={[
                        { key: "mine", label: "My Forms" },
                        { key: "all", label: "All" },
                    ]}
                />
            )}

            <Card className="pms-list-panel pms-list-fill">
                {forms.length > 0 && (
                    <FormListFilters
                        rows={forms}
                        formKind={activeFormKind}
                        onFormKind={setFormKind}
                    />
                )}
                {shownForms.length === 0 && !isLoading ? (
                    <Empty
                        description={
                            forms.length > 0
                                ? "No forms match this filter."
                                : collegeList
                                  ? "No forms yet."
                                  : "You have no forms yet. Create one for the active school year to get started."
                        }
                    />
                ) : (
                    <>
                        <div className="pms-mobile-only pms-paged">
                            <FormCards
                                forms={formPage.rows}
                                sideAction
                                actionLabel={viewOnly ? () => "View" : undefined}
                                onOpen={(record) => navigate(`/forms/${record.id}`, { state: { from: "/my-forms" } })}
                            />
                            <ListPager
                                page={formPage.page}
                                pageSize={formPage.pageSize}
                                total={formPage.total}
                                onChange={formPage.setPage}
                            />
                        </div>
                        <div className="pms-desktop-only">
                            <Table
                                rowKey="id"
                                loading={isLoading}
                                dataSource={shownForms}
                                columns={columns}
                                pagination={tablePager(10)}
                                scroll={{ x: 900 }}
                            />
                        </div>
                    </>
                )}
            </Card>

            {/* <BulkIpcrModal open={bulkOpen} onClose={() => setBulkOpen(false)} /> */}

            <Drawer
                title="New performance commitment"
                open={open}
                onClose={() => setOpen(false)}
                width={440}
                extra={
                    <Button type="primary" loading={create.isPending} onClick={() => form.submit()}>
                        Create
                    </Button>
                }
            >
                <Form form={form} layout="vertical" requiredMark={false} onFinish={(v) => create.mutate(v)}>
                    <Form.Item name="type" label="Form type" rules={[{ required: true }]}>
                        <Select
                            options={
                                can("president")
                                    ? [{ value: "opcr", label: "OPCR — the college's commitment" }]
                                    : [{ value: "ipcr", label: "IPCR — my individual commitment" }]
                            }
                        />
                    </Form.Item>
                    <Form.Item
                        name="school_year_id"
                        label="School year"
                        rules={[{ required: true, message: "Pick the school year." }]}
                    >
                        <Select
                            onChange={(value) => {
                                const picked = years.find((y) => y.id === value);
                                form.setFieldsValue({
                                    rating_period_id: picked?.periods?.find((p) => p.is_active)?.id
                                        ?? picked?.periods?.[0]?.id,
                                });
                            }}
                            options={years.map((y) => ({
                                value: y.id,
                                label: y.is_active ? `${y.label} ★` : y.label,
                            }))}
                        />
                    </Form.Item>
                    {chosenType === "ipcr" && (
                        <Form.Item
                            name="rating_period_id"
                            label="Review period"
                            rules={[{ required: true, message: "Pick the review period." }]}
                            extra="An IPCR is filed for one review period; the other half of the year gets its own."
                        >
                            <Select
                                options={periodOptions.map((p) => ({
                                    value: p.id,
                                    label: p.is_active ? `${p.label} ★` : p.label,
                                }))}
                            />
                        </Form.Item>
                    )}
                    <Form.Item
                        name="org_unit_id"
                        label="Office"
                        rules={[{ required: true, message: "Pick the office this form belongs to." }]}
                        extra="An OPCR belongs to the office that commits to the targets."
                    >
                        <Select
                            showSearch
                            optionFilterProp="label"
                            placeholder="Select an office"
                            options={units.map((u) => ({ value: u.id, label: u.name }))}
                        />
                    </Form.Item>
                </Form>
            </Drawer>
        </>
    );
}
