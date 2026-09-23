import React from "react";
import {
    Avatar,
    Button,
    Descriptions,
    Divider,
    Empty,
    Modal,
    Space,
    Spin,
    Table,
    Tag,
    Typography,
} from "antd";
import { FilePdfOutlined, UserOutlined } from "@ant-design/icons";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import api from "~/utils/api";
import { useAuth } from "~/hooks/useAuth";
import PdfPreview from "~/components/PdfPreview";
import { ROLE_LABELS } from "~/utils/constants";

/** Shown only inside the sheet, which the server already gates. */
const IDENTIFIERS = [
    ["agency_employee_no", "Employee no."],
    ["gsis_id", "GSIS"],
    ["pagibig_id", "Pag-IBIG"],
    ["philhealth_id", "PhilHealth"],
    ["sss_id", "SSS"],
    ["tin", "TIN"],
];

const date = (value) => (value ? dayjs(value).format("MMM D, YYYY") : "—");
const span = (from, to, current) =>
    `${date(from)} — ${current ? "present" : to ? date(to) : "—"}`;

function Section({ title, rows, columns }) {
    if (!rows?.length) return null;

    return (
        <>
            <Divider orientation="left" plain style={{ marginTop: 20 }}>
                {title}
            </Divider>
            <Table
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={rows}
                columns={columns}
                scroll={{ x: true }}
            />
        </>
    );
}

/**
 * Who somebody is. Everyone signed in sees the card; the Personal Data Sheet
 * appears only for those the server allows — it withholds the record rather
 * than sending it for the browser to hide.
 */
export default function PersonModal({ personId, open, onClose }) {
    const { user } = useAuth();

    // The sheet is readable by reviewers, but taking a copy away is for the
    // people who keep the records.
    const canDownload = ["admin", "president"].includes(user?.role);
    const [sheetOpen, setSheetOpen] = React.useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["person", personId],
        queryFn: () => api.get(`people/${personId}`).then((r) => r.data),
        enabled: Boolean(open && personId),
    });

    const card = data?.card;
    const profile = data?.profile;

    return (
        <>
        <Modal
            open={open}
            onCancel={onClose}
            footer={null}
            width={820}
            title={null}
        >
            {isLoading || !card ? (
                <div style={{ display: "grid", placeItems: "center", padding: 40 }}>
                    <Spin />
                </div>
            ) : (
                <>
                    <Space align="start" size={16}>
                        <Avatar
                            size={72}
                            src={card.image ? `/uploads/profile/${card.image}` : undefined}
                            icon={<UserOutlined />}
                        />
                        <div>
                            <Typography.Title level={4} style={{ marginBottom: 2 }}>
                                {card.name}
                            </Typography.Title>
                            <Typography.Text type="secondary">
                                {card.position_title || ROLE_LABELS[card.role] || card.role}
                            </Typography.Text>
                            <div style={{ marginTop: 6 }}>
                                <Space wrap size={6}>
                                    <Tag>{ROLE_LABELS[card.role] ?? card.role}</Tag>
                                    {card.org_unit?.name && <Tag>{card.org_unit.name}</Tag>}
                                </Space>
                            </div>
                            <Typography.Text copyable style={{ fontSize: 12 }}>
                                {card.email}
                            </Typography.Text>
                            {data.may_view_sheet && canDownload && (
                                <div style={{ marginTop: 8 }}>
                                    <Button
                                        size="small"
                                        icon={<FilePdfOutlined />}
                                        onClick={() => setSheetOpen(true)}
                                    >
                                        View data sheet
                                    </Button>
                                </div>
                            )}
                        </div>
                    </Space>

                    {!data.may_view_sheet ? (
                        <Empty
                            style={{ marginTop: 24 }}
                            image={Empty.PRESENTED_IMAGE_SIMPLE}
                            description="Their personal data sheet is private."
                        />
                    ) : (
                        <>
                            {profile && (
                                <>
                                    <Divider orientation="left" plain style={{ marginTop: 20 }}>
                                        Personal information
                                    </Divider>
                                    <Descriptions size="small" column={2} bordered>
                                        <Descriptions.Item label="Date of birth">{date(profile.date_of_birth)}</Descriptions.Item>
                                        <Descriptions.Item label="Place of birth">{profile.place_of_birth || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Sex">{profile.sex || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Civil status">{profile.civil_status || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Citizenship">{profile.citizenship || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Blood type">{profile.blood_type || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Mobile">{profile.mobile || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Telephone">{profile.telephone || "—"}</Descriptions.Item>
                                        <Descriptions.Item label="Address" span={2}>
                                            {profile.residential_address || "—"}
                                        </Descriptions.Item>
                                    </Descriptions>

                                    {IDENTIFIERS.some(([key]) => profile[key]) && (
                                        <>
                                            <Divider orientation="left" plain style={{ marginTop: 20 }}>
                                                Government identifiers
                                            </Divider>
                                            <Descriptions size="small" column={3} bordered>
                                                {IDENTIFIERS.filter(([key]) => profile[key]).map(([key, label]) => (
                                                    <Descriptions.Item key={key} label={label}>
                                                        {profile[key]}
                                                    </Descriptions.Item>
                                                ))}
                                            </Descriptions>
                                        </>
                                    )}
                                </>
                            )}

                            <Section
                                title="Educational background"
                                rows={data.educations}
                                columns={[
                                    { title: "Level", dataIndex: "level" },
                                    { title: "School", dataIndex: "school" },
                                    { title: "Degree", dataIndex: "degree" },
                                    { title: "Graduated", dataIndex: "year_graduated" },
                                    { title: "Honours", dataIndex: "honours" },
                                ]}
                            />

                            <Section
                                title="Civil service eligibility"
                                rows={data.eligibilities}
                                columns={[
                                    { title: "Eligibility", dataIndex: "eligibility" },
                                    { title: "Rating", dataIndex: "rating" },
                                    { title: "Examination", render: (_, r) => date(r.examination_date) },
                                    { title: "Licence", dataIndex: "licence_number" },
                                ]}
                            />

                            <Section
                                title="Work experience"
                                rows={data.workExperiences}
                                columns={[
                                    { title: "Position", dataIndex: "position" },
                                    { title: "Office", dataIndex: "company" },
                                    { title: "Period", render: (_, r) => span(r.started_on, r.ended_on, r.is_current) },
                                    { title: "Status", dataIndex: "appointment_status" },
                                ]}
                            />

                            <Section
                                title="Training and seminars"
                                rows={data.trainings}
                                columns={[
                                    { title: "Title", dataIndex: "title" },
                                    { title: "Period", render: (_, r) => span(r.started_on, r.ended_on) },
                                    { title: "Hours", dataIndex: "hours" },
                                    { title: "Conducted by", dataIndex: "conducted_by" },
                                ]}
                            />

                            <Section
                                title="Voluntary work"
                                rows={data.voluntaryWorks}
                                columns={[
                                    { title: "Organisation", dataIndex: "organization" },
                                    { title: "Position", dataIndex: "position" },
                                    { title: "Period", render: (_, r) => span(r.started_on, r.ended_on) },
                                    { title: "Hours", dataIndex: "hours" },
                                ]}
                            />
                        </>
                    )}
                </>
            )}
        </Modal>

            <PdfPreview
                open={sheetOpen}
                onClose={() => setSheetOpen(false)}
                url={`people/${personId}/pdf`}
                filename={`${card?.name} - Personal Data Sheet.pdf`}
                title={`${card?.name} — Personal Data Sheet`}
            />
        </>
    );
}
