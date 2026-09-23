import React from "react";
import { Badge, Button, Image, Space, Typography } from "antd";
import { ExpandOutlined, PaperClipOutlined } from "@ant-design/icons";
import RichTextView from "~/components/RichTextView";
import AttachmentTile from "~/components/AttachmentTile";

export function AttachmentGallery({ files = [], canRemove = false, onRemove, style }) {
    if (!files.length) return null;

    return (
        <Image.PreviewGroup>
            <Space wrap size={8} style={{ marginTop: 10, display: "flex", ...style }}>
                {files.map((file) => (
                    <AttachmentTile
                        key={file.id}
                        file={file}
                        canRemove={canRemove}
                        onRemove={onRemove}
                    />
                ))}
            </Space>
        </Image.PreviewGroup>
    );
}

export function AccomplishmentCell({
    html,
    evidenceCount = 0,
    empty = "Nothing recorded.",
    label = "Open",
    onOpen,
}) {
    return (
        <>
            {html ? (
                <RichTextView html={html} />
            ) : (
                <span className="pms-cell-empty">{empty}</span>
            )}
            <div style={{ marginTop: 4 }}>
                <Space size={6}>
                    <Button size="small" type="text" icon={<ExpandOutlined />} onClick={onOpen}>
                        {label}
                    </Button>
                    {evidenceCount > 0 && (
                        <Badge count={evidenceCount} size="small" color="#1e3a72" offset={[4, 0]}>
                            <PaperClipOutlined style={{ opacity: 0.6 }} />
                        </Badge>
                    )}
                </Space>
            </div>
        </>
    );
}

export default function AccomplishmentView({
    html,
    attachments = [],
    empty = "Nothing recorded.",
    canRemove = false,
    onRemove,
}) {
    return (
        <>
            {html ? (
                <RichTextView html={html} />
            ) : (
                <Typography.Text type="secondary" style={{ display: "block" }}>
                    {empty}
                </Typography.Text>
            )}
            <AttachmentGallery files={attachments} canRemove={canRemove} onRemove={onRemove} />
        </>
    );
}
