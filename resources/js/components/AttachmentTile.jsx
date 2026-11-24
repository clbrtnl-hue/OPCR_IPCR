import React, { useState } from "react";
import { Button, Modal, Popconfirm, Space, Tooltip, Typography } from "antd";
import {
    DeleteOutlined,
    DownloadOutlined,
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

export function evidenceUrl(file) {
    return `/uploads/pcr/${file.file_path}`;
}

function downloadEvidence(file) {
    const link = document.createElement("a");
    link.href = evidenceUrl(file);
    link.download = file.original_name || "evidence";
    document.body.appendChild(link);
    link.click();
    link.remove();
}

export function EvidencePreview({ file, open, onClose }) {
    if (!file) return null;

    const url = evidenceUrl(file);
    const kind = kindOf(file.mime, file.original_name ?? "");
    const viewable = kind === "image" || kind === "pdf";
    const { Icon, color } = ICONS[kind] ?? ICONS.other;

    return (
        <Modal
            open={open}
            onCancel={onClose}
            width={viewable ? "min(960px, 92vw)" : 420}
            title={file.original_name}
            destroyOnClose
            footer={
                <Button icon={<DownloadOutlined />} onClick={() => downloadEvidence(file)}>
                    Download
                </Button>
            }
        >
            {kind === "image" && (
                <img
                    src={url}
                    alt={file.original_name}
                    style={{ display: "block", maxWidth: "100%", maxHeight: "70vh", margin: "0 auto" }}
                />
            )}
            {kind === "pdf" && (
                <iframe
                    src={url}
                    title={file.original_name}
                    style={{ width: "100%", height: "70vh", border: 0 }}
                />
            )}
            {!viewable && (
                <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
                    <Icon style={{ fontSize: 36, color }} />
                    <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
                        Download this file to open it.
                    </Typography.Paragraph>
                </div>
            )}
        </Modal>
    );
}

export default function AttachmentTile({ file, canRemove, onRemove }) {
    const [preview, setPreview] = useState(false);
    const url = evidenceUrl(file);
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
            <div style={face} onClick={() => setPreview(true)}>
                {kind === "image" ? (
                    <img
                        src={url}
                        alt={file.original_name}
                        style={{ width: "100%", height: 84, objectFit: "cover" }}
                    />
                ) : (
                    <Icon style={{ fontSize: 34, color }} />
                )}
            </div>

            <Tooltip title={file.original_name}>
                <Typography.Text
                    ellipsis
                    style={{ fontSize: 12, cursor: "pointer" }}
                    onClick={() => setPreview(true)}
                >
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

            <EvidencePreview file={file} open={preview} onClose={() => setPreview(false)} />
        </div>
    );
}
