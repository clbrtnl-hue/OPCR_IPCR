import React, { useState } from "react";
import { Button, Image, Modal, Popconfirm, Space, Tooltip, Typography } from "antd";
import {
    DeleteOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    FileUnknownOutlined,
    FileWordOutlined,
} from "@ant-design/icons";

const KB = 1024;

export function formatSize(bytes) {
    if (bytes == null) return "";
    if (bytes < KB) return `${bytes} B`;
    if (bytes < KB * KB) return `${Math.round(bytes / KB)} KB`;
    return `${(bytes / (KB * KB)).toFixed(1)} MB`;
}

/** What a file looks like before you open it, keyed off its own mime type. */
function kindOf(mime = "", name = "") {
    if (mime.startsWith("image/")) return "image";
    if (mime === "application/pdf" || name.endsWith(".pdf")) return "pdf";
    if (mime.includes("word") || /\.docx?$/.test(name)) return "word";
    if (mime.includes("sheet") || mime.includes("excel") || /\.(xlsx?|csv)$/.test(name)) return "excel";
    return "other";
}

const ICONS = {
    pdf: { Icon: FilePdfOutlined, color: "#c0392b" },
    word: { Icon: FileWordOutlined, color: "#2b579a" },
    excel: { Icon: FileExcelOutlined, color: "#217346" },
    other: { Icon: FileUnknownOutlined, color: "#8c8c8c" },
};

export default function AttachmentTile({ file, canRemove, onRemove }) {
    const [preview, setPreview] = useState(false);
    const url = `/uploads/pcr/${file.file_path}`;
    const kind = kindOf(file.mime, file.original_name ?? "");

    const frame = {
        width: 132,
        border: "1px solid var(--pms-border, #f0f0f0)",
        borderRadius: 8,
        padding: 8,
        display: "flex",
        flexDirection: "column",
        gap: 6,
    };

    const face = {
        height: 84,
        borderRadius: 6,
        background: "rgba(0,0,0,0.03)",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        overflow: "hidden",
        cursor: "pointer",
    };

    const { Icon, color } = ICONS[kind] ?? ICONS.other;

    return (
        <div style={frame}>
            {kind === "image" ? (
                <div style={face}>
                    <Image
                        src={url}
                        alt={file.original_name}
                        width="100%"
                        height={84}
                        style={{ objectFit: "cover" }}
                        preview={{ src: url }}
                    />
                </div>
            ) : (
                <div
                    style={face}
                    onClick={() => (kind === "pdf" ? setPreview(true) : window.open(url, "_blank"))}
                >
                    <Icon style={{ fontSize: 34, color }} />
                </div>
            )}

            <Tooltip title={file.original_name}>
                <Typography.Text ellipsis style={{ fontSize: 12 }}>
                    {file.original_name}
                </Typography.Text>
            </Tooltip>

            <Space
                size={4}
                style={{ justifyContent: "space-between", width: "100%" }}
            >
                <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                    {formatSize(file.file_size)}
                </Typography.Text>
                {canRemove && (
                    <Popconfirm title="Remove this file?" onConfirm={() => onRemove(file.id)}>
                        <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                    </Popconfirm>
                )}
            </Space>

            {kind === "pdf" && (
                <Modal
                    open={preview}
                    onCancel={() => setPreview(false)}
                    footer={null}
                    width="80vw"
                    title={file.original_name}
                >
                    <iframe
                        src={url}
                        title={file.original_name}
                        style={{ width: "100%", height: "70vh", border: 0 }}
                    />
                </Modal>
            )}
        </div>
    );
}
