import React from "react";
import {
    Button,
    Card,
    Col,
    DatePicker,
    Descriptions,
    Form,
    Input,
    InputNumber,
    Row,
    Select,
    Space,
    Spin,
    Tabs,
    Typography,
    Upload,
    message,
} from "antd";
import { UploadOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { ROLE_LABELS, UPLOAD_MAX_MB } from "~/utils/constants";
import { useAuth } from "~/hooks/useAuth";
import UserAvatar from "~/components/UserAvatar";
import PageHeader from "~/components/PageHeader";
import PersonModal from "~/components/PersonModal";
import PdsSection from "~/pages/profile/PdsSection";

const asDate = (v) => (v ? dayjs(v) : null);
const outDate = (v) => (v ? dayjs(v).format("YYYY-MM-DD") : null);
const showDate = (v) => (v ? dayjs(v).format("MMM D, YYYY") : "—");

export default function ProfilePage() {
    const { user, refresh } = useAuth();
    const queryClient = useQueryClient();
    const [sheetForm] = Form.useForm();
    const [passwordForm] = Form.useForm();
    const [previewOpen, setPreviewOpen] = React.useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["my-pds"],
        queryFn: () => api.get(`people/${user.id}`).then((r) => r.data),
        enabled: Boolean(user?.id),
    });

    React.useEffect(() => {
        if (data?.profile) {
            sheetForm.setFieldsValue({
                ...data.profile,
                date_of_birth: asDate(data.profile.date_of_birth),
            });
        }
    }, [data, sheetForm]);

    const saveSheet = useMutation({
        mutationFn: (values) =>
            api.post("my-profile", { ...values, date_of_birth: outDate(values.date_of_birth) }),
        onSuccess: () => {
            message.success("Personal information saved.");
            queryClient.invalidateQueries({ queryKey: ["my-pds"] });
        },
    });

    const changePassword = useMutation({
        mutationFn: (values) => api.post("my-password", values),
        onSuccess: () => {
            message.success("Password changed. Any other device you were signed in on has been signed out.");
            passwordForm.resetFields();
        },
    });

    const uploadPhoto = async (file) => {
        if (file.size > UPLOAD_MAX_MB * 1024 * 1024) {
            message.error(`Pick a photo smaller than ${UPLOAD_MAX_MB} MB.`);
            return Upload.LIST_IGNORE;
        }

        const body = new FormData();
        body.append("file", file);

        try {
            await api.post(`users/${user.id}/avatar`, body);
            message.success("Photo updated.");
            await refresh();
        } catch {
        }

        return false;
    };

    if (isLoading) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    const account = (
        <Row gutter={16}>
            <Col xs={24} md={8}>
                <Card>
                    <Space direction="vertical" align="center" style={{ width: "100%" }}>
                        <UserAvatar user={user} size={96} showTooltip={false} />
                        <Typography.Title level={5} style={{ marginBottom: 0 }}>
                            {user?.name}
                        </Typography.Title>
                        <Typography.Text type="secondary">{user?.position_title}</Typography.Text>
                        <Upload accept=".jpg,.jpeg,.png,.webp" showUploadList={false} beforeUpload={uploadPhoto}>
                            <Button icon={<UploadOutlined />}>Change photo</Button>
                        </Upload>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            JPG, PNG or WebP up to {UPLOAD_MAX_MB} MB
                        </Typography.Text>
                    </Space>
                </Card>
            </Col>
            <Col xs={24} md={16}>
                <Card title="Account">
                    <Descriptions column={1} bordered size="small">
                        <Descriptions.Item label="Name">{user?.name}</Descriptions.Item>
                        <Descriptions.Item label="Email">{user?.email}</Descriptions.Item>
                        <Descriptions.Item label="Role">
                            {ROLE_LABELS[user?.role] ?? user?.role}
                        </Descriptions.Item>
                        <Descriptions.Item label="Position">{user?.position_title || "—"}</Descriptions.Item>
                        <Descriptions.Item label="Office">{user?.org_unit?.name || "—"}</Descriptions.Item>
                    </Descriptions>
                    <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
                        Your name, role and office are set by an administrator. Everything under
                        the other tabs is yours to fill in.
                    </Typography.Paragraph>
                </Card>

                <Card title="Password" style={{ marginTop: 16 }}>
                    <Form
                        form={passwordForm}
                        layout="vertical"
                        requiredMark={false}
                        onFinish={(values) => changePassword.mutate(values)}
                    >
                        <Row gutter={16}>
                            <Col xs={24} md={8}>
                                <Form.Item
                                    name="current_password"
                                    label="Current password"
                                    rules={[{ required: true, message: "Enter your current password." }]}
                                >
                                    <Input.Password autoComplete="current-password" />
                                </Form.Item>
                            </Col>
                            <Col xs={24} md={8}>
                                <Form.Item
                                    name="password"
                                    label="New password"
                                    rules={[
                                        { required: true, message: "Choose a new password." },
                                        { min: 8, message: "Use at least 8 characters." },
                                    ]}
                                >
                                    <Input.Password autoComplete="new-password" />
                                </Form.Item>
                            </Col>
                            <Col xs={24} md={8}>
                                <Form.Item
                                    name="password_confirmation"
                                    label="Repeat the new password"
                                    dependencies={["password"]}
                                    rules={[
                                        { required: true, message: "Type the new password again." },
                                        ({ getFieldValue }) => ({
                                            validator: (_, value) =>
                                                !value || value === getFieldValue("password")
                                                    ? Promise.resolve()
                                                    : Promise.reject(new Error("The two do not match.")),
                                        }),
                                    ]}
                                >
                                    <Input.Password autoComplete="new-password" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Space>
                            <Button type="primary" htmlType="submit" loading={changePassword.isPending}>
                                Change password
                            </Button>
                            <Typography.Text type="secondary">
                                At least 8 characters. Signing in elsewhere will need the new one.
                            </Typography.Text>
                        </Space>
                    </Form>
                </Card>
            </Col>
        </Row>
    );

    const personal = (
        <Card title="Personal information">
            <Form
                form={sheetForm}
                layout="vertical"
                requiredMark={false}
                onFinish={(values) => saveSheet.mutate(values)}
            >
                <Row gutter={16}>
                    <Col xs={24} md={8}>
                        <Form.Item name="date_of_birth" label="Date of birth">
                            <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={8}>
                        <Form.Item name="place_of_birth" label="Place of birth">
                            <Input />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={4}>
                        <Form.Item name="sex" label="Sex">
                            <Select
                                allowClear
                                options={[
                                    { value: "male", label: "Male" },
                                    { value: "female", label: "Female" },
                                ]}
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={4}>
                        <Form.Item name="civil_status" label="Civil status">
                            <Select
                                allowClear
                                options={["single", "married", "widowed", "separated", "other"].map((v) => ({
                                    value: v,
                                    label: v[0].toUpperCase() + v.slice(1),
                                }))}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={6}>
                        <Form.Item name="citizenship" label="Citizenship">
                            <Input placeholder="Filipino" />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={4}>
                        <Form.Item name="height_m" label="Height (m)">
                            <InputNumber style={{ width: "100%" }} step={0.01} min={0} max={3} />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={4}>
                        <Form.Item name="weight_kg" label="Weight (kg)">
                            <InputNumber style={{ width: "100%" }} step={0.1} min={0} max={500} />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={4}>
                        <Form.Item name="blood_type" label="Blood type">
                            <Input placeholder="O+" />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={6}>
                        <Form.Item name="agency_employee_no" label="Employee number">
                            <Input />
                        </Form.Item>
                    </Col>

                    <Col xs={24}>
                        <Typography.Text strong>Government identifiers</Typography.Text>
                        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
                            Only you, your reviewers and the administrator can see these.
                        </Typography.Paragraph>
                    </Col>
                    {[
                        ["gsis_id", "GSIS"],
                        ["pagibig_id", "Pag-IBIG"],
                        ["philhealth_id", "PhilHealth"],
                        ["sss_id", "SSS"],
                        ["tin", "TIN"],
                    ].map(([name, label]) => (
                        <Col xs={12} md={4} key={name}>
                            <Form.Item name={name} label={label}>
                                <Input />
                            </Form.Item>
                        </Col>
                    ))}

                    <Col xs={24} md={12}>
                        <Form.Item name="residential_address" label="Residential address">
                            <Input />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={12}>
                        <Form.Item name="permanent_address" label="Permanent address">
                            <Input />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={6}>
                        <Form.Item name="mobile" label="Mobile">
                            <Input />
                        </Form.Item>
                    </Col>
                    <Col xs={12} md={6}>
                        <Form.Item name="telephone" label="Telephone">
                            <Input />
                        </Form.Item>
                    </Col>
                </Row>

                <Button type="primary" htmlType="submit" loading={saveSheet.isPending}>
                    Save personal information
                </Button>
            </Form>
        </Card>
    );

    const background = (
        <>
            <PdsSection
                title="Educational background"
                hint="Add the schools you attended."
                section="educations"
                rows={data?.educations}
                columns={[
                    { title: "Level", dataIndex: "level" },
                    { title: "School", dataIndex: "school" },
                    { title: "Degree", dataIndex: "degree" },
                    { title: "Graduated", dataIndex: "year_graduated" },
                ]}
                fields={
                    <>
                        <Form.Item name="level" label="Level" rules={[{ required: true }]}>
                            <Select
                                options={["elementary", "secondary", "vocational", "college", "graduate"].map((v) => ({
                                    value: v,
                                    label: v[0].toUpperCase() + v.slice(1),
                                }))}
                            />
                        </Form.Item>
                        <Form.Item name="school" label="School" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="degree" label="Degree or strand">
                            <Input />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={8}>
                                <Form.Item name="period_from" label="From">
                                    <Input placeholder="2018" />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="period_to" label="To">
                                    <Input placeholder="2022" />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="year_graduated" label="Graduated">
                                    <Input placeholder="2022" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="units_earned" label="Units earned (if not graduated)">
                            <Input />
                        </Form.Item>
                        <Form.Item name="honours" label="Scholarship or honours">
                            <Input />
                        </Form.Item>
                    </>
                }
            />

            <PdsSection
                title="Civil service eligibility"
                hint="Add any eligibility or professional licence."
                section="eligibilities"
                rows={data?.eligibilities}
                columns={[
                    { title: "Eligibility", dataIndex: "eligibility" },
                    { title: "Rating", dataIndex: "rating" },
                    { title: "Examination", render: (_, r) => showDate(r.examination_date) },
                    { title: "Licence", dataIndex: "licence_number" },
                ]}
                toForm={(row) => ({
                    ...row,
                    examination_date: asDate(row.examination_date),
                    licence_valid_until: asDate(row.licence_valid_until),
                })}
                fromForm={(v) => ({
                    ...v,
                    examination_date: outDate(v.examination_date),
                    licence_valid_until: outDate(v.licence_valid_until),
                })}
                fields={
                    <>
                        <Form.Item name="eligibility" label="Eligibility" rules={[{ required: true }]}>
                            <Input placeholder="Career Service Professional" />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="rating" label="Rating">
                                    <Input />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="examination_date" label="Date of examination">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="examination_place" label="Place of examination">
                            <Input />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="licence_number" label="Licence number">
                                    <Input />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="licence_valid_until" label="Valid until">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                        </Row>
                    </>
                }
            />

            <PdsSection
                title="Work experience"
                hint="Add the posts you have held."
                section="workExperiences"
                rows={data?.workExperiences}
                columns={[
                    { title: "Position", dataIndex: "position" },
                    { title: "Office", dataIndex: "company" },
                    {
                        title: "Period",
                        render: (_, r) =>
                            `${showDate(r.started_on)} — ${r.is_current ? "present" : showDate(r.ended_on)}`,
                    },
                ]}
                toForm={(row) => ({
                    ...row,
                    started_on: asDate(row.started_on),
                    ended_on: asDate(row.ended_on),
                })}
                fromForm={(v) => ({
                    ...v,
                    started_on: outDate(v.started_on),
                    ended_on: outDate(v.ended_on),
                })}
                fields={
                    <>
                        <Form.Item name="position" label="Position" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="company" label="Office or company" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="started_on" label="From">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="ended_on" label="To">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="monthly_salary" label="Monthly salary">
                                    <Input />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="salary_grade" label="Salary grade">
                                    <Input placeholder="SG-18" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="appointment_status" label="Status of appointment">
                            <Input placeholder="Permanent" />
                        </Form.Item>
                    </>
                }
            />

            <PdsSection
                title="Training and seminars"
                hint="Add the training you have attended."
                section="trainings"
                rows={data?.trainings}
                columns={[
                    { title: "Title", dataIndex: "title" },
                    { title: "Period", render: (_, r) => `${showDate(r.started_on)} — ${showDate(r.ended_on)}` },
                    { title: "Hours", dataIndex: "hours" },
                    { title: "Conducted by", dataIndex: "conducted_by" },
                ]}
                toForm={(row) => ({
                    ...row,
                    started_on: asDate(row.started_on),
                    ended_on: asDate(row.ended_on),
                })}
                fromForm={(v) => ({
                    ...v,
                    started_on: outDate(v.started_on),
                    ended_on: outDate(v.ended_on),
                })}
                fields={
                    <>
                        <Form.Item name="title" label="Title" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="started_on" label="From">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="ended_on" label="To">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="hours" label="Hours">
                                    <InputNumber style={{ width: "100%" }} min={0} />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="kind" label="Type">
                                    <Select
                                        allowClear
                                        options={["managerial", "supervisory", "technical", "other"].map((v) => ({
                                            value: v,
                                            label: v[0].toUpperCase() + v.slice(1),
                                        }))}
                                    />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="conducted_by" label="Conducted by">
                            <Input />
                        </Form.Item>
                    </>
                }
            />

            <PdsSection
                title="Voluntary work"
                hint="Add any voluntary or community work."
                section="voluntaryWorks"
                rows={data?.voluntaryWorks}
                columns={[
                    { title: "Organisation", dataIndex: "organization" },
                    { title: "Position", dataIndex: "position" },
                    { title: "Period", render: (_, r) => `${showDate(r.started_on)} — ${showDate(r.ended_on)}` },
                    { title: "Hours", dataIndex: "hours" },
                ]}
                toForm={(row) => ({
                    ...row,
                    started_on: asDate(row.started_on),
                    ended_on: asDate(row.ended_on),
                })}
                fromForm={(v) => ({
                    ...v,
                    started_on: outDate(v.started_on),
                    ended_on: outDate(v.ended_on),
                })}
                fields={
                    <>
                        <Form.Item name="organization" label="Organisation" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="position" label="Position">
                            <Input />
                        </Form.Item>
                        <Row gutter={12}>
                            <Col span={12}>
                                <Form.Item name="started_on" label="From">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                            <Col span={12}>
                                <Form.Item name="ended_on" label="To">
                                    <DatePicker style={{ width: "100%" }} format="MMM D, YYYY" />
                                </Form.Item>
                            </Col>
                        </Row>
                        <Form.Item name="hours" label="Hours">
                            <InputNumber style={{ width: "100%" }} min={0} />
                        </Form.Item>
                    </>
                }
            />
        </>
    );

    return (
        <>
            <PageHeader
                title="My Profile"
                subtitle="Your account, and the personal data sheet your reviewers can see."
                extra={<Button onClick={() => setPreviewOpen(true)}>Preview as others see it</Button>}
            />

            <Tabs
                items={[
                    { key: "account", label: "Account", children: account },
                    { key: "personal", label: "Personal information", children: personal },
                    { key: "background", label: "Background", children: background },
                ]}
            />

            <PersonModal
                personId={user?.id}
                open={previewOpen}
                onClose={() => setPreviewOpen(false)}
            />
        </>
    );
}
