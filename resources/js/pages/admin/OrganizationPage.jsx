import React, { useEffect, useState } from "react";
import {
    Button,
    Card,
    Col,
    Form,
    Input,
    Row,
    Space,
    Spin,
    Typography,
    Upload,
    message,
} from "antd";
import { UploadOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api, { errorText } from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import { organizationLogo } from "~/hooks/useOrganization";

const UPLOAD_MAX_MB = 5;

export default function OrganizationPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [logoFile, setLogoFile] = useState(null);
    const [preview, setPreview] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ["organization"],
        queryFn: () => api.get("organization").then((r) => r.data),
    });

    useEffect(() => {
        if (!data) return;

        form.setFieldsValue({
            name: data.name,
            short_name: data.short_name,
            address: data.address,
            head_title: data.head_title,
        });
        setPreview(null);
        setLogoFile(null);
    }, [data, form]);

    const save = useMutation({
        mutationFn: async (values) => {
            const body = new FormData();
            body.append("name", values.name ?? "");
            body.append("short_name", values.short_name ?? "");
            body.append("address", values.address ?? "");
            body.append("head_title", values.head_title ?? "");

            if (logoFile) {
                body.append("logo", logoFile);
            }

            return api.post("organization", body).then((r) => r.data);
        },
        onSuccess: () => {
            message.success("Organization branding saved.");
            setLogoFile(null);
            setPreview(null);
            queryClient.invalidateQueries({ queryKey: ["organization"] });
        },
        onError: (error) => message.error(errorText(error)),
    });

    if (isLoading || !data) {
        return (
            <div style={{ display: "grid", placeItems: "center", padding: 60 }}>
                <Spin size="large" />
            </div>
        );
    }

    const logoSrc = preview || organizationLogo(data);

    return (
        <div>
            <PageHeader
                title="Organization"
                subtitle="College name, address and logo shown on the login screen, sidebar and printed OPCR/IPCR forms."
            />

            <Card>
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={(values) => save.mutate(values)}
                    style={{ maxWidth: 640 }}
                >
                    <Row gutter={24}>
                        <Col xs={24} sm={8}>
                            <Space direction="vertical" align="center" style={{ width: "100%" }}>
                                <img
                                    src={logoSrc}
                                    alt=""
                                    style={{
                                        width: 96,
                                        height: 96,
                                        objectFit: "contain",
                                        borderRadius: 12,
                                        background: "var(--pms-surface-muted, #f5f5f5)",
                                        padding: 8,
                                    }}
                                />
                                <Upload
                                    accept="image/png,image/jpeg,image/webp"
                                    showUploadList={false}
                                    beforeUpload={(file) => {
                                        if (file.size > UPLOAD_MAX_MB * 1024 * 1024) {
                                            message.error(`Pick a logo smaller than ${UPLOAD_MAX_MB} MB.`);
                                            return Upload.LIST_IGNORE;
                                        }

                                        setLogoFile(file);
                                        setPreview(URL.createObjectURL(file));
                                        return false;
                                    }}
                                >
                                    <Button icon={<UploadOutlined />}>Change logo</Button>
                                </Upload>
                                <Typography.Text type="secondary" style={{ fontSize: 12, textAlign: "center" }}>
                                    PNG, JPG or WebP · up to {UPLOAD_MAX_MB} MB
                                </Typography.Text>
                            </Space>
                        </Col>

                        <Col xs={24} sm={16}>
                            <Form.Item
                                name="name"
                                label="College name"
                                rules={[{ required: true, message: "Enter the college name." }]}
                            >
                                <Input placeholder="Opol Community College" />
                            </Form.Item>

                            <Form.Item name="short_name" label="Short name">
                                <Input placeholder="OCC" maxLength={60} />
                            </Form.Item>

                            <Form.Item name="address" label="Address">
                                <Input placeholder="Opol, Misamis Oriental" />
                            </Form.Item>

                            <Form.Item
                                name="head_title"
                                label="Head title"
                                extra="Printed under the approving signature on the OPCR, e.g. College President."
                            >
                                <Input placeholder="College President" maxLength={120} />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Button type="primary" htmlType="submit" loading={save.isPending}>
                        Save branding
                    </Button>
                </Form>
            </Card>
        </div>
    );
}
