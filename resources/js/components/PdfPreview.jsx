import React, { useEffect, useState } from "react";
import { Button, Modal, Result, Spin } from "antd";
import { DownloadOutlined } from "@ant-design/icons";
import api from "~/utils/api";

/**
 * The document itself, on screen. The API needs the bearer token, so the PDF is
 * fetched and shown from a blob rather than pointed at in a tab that would
 * arrive unauthenticated.
 */
export default function PdfPreview({ url, filename, title, open, onClose }) {
    const [src, setSrc] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open || !url) return undefined;

        let objectUrl;
        let cancelled = false;

        setSrc(null);
        setFailed(false);

        api.get(url, { responseType: "blob" })
            .then((response) => {
                if (cancelled) return;

                objectUrl = URL.createObjectURL(
                    new Blob([response.data], { type: "application/pdf" })
                );
                setSrc(objectUrl);
            })
            .catch(() => !cancelled && setFailed(true));

        return () => {
            cancelled = true;

            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [open, url]);

    const save = () => {
        if (!src) return;

        const link = document.createElement("a");
        link.href = src;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title={title}
            width="90vw"
            style={{ top: 24 }}
            footer={[
                <Button key="close" onClick={onClose}>
                    Close
                </Button>,
                <Button key="save" type="primary" icon={<DownloadOutlined />} disabled={!src} onClick={save}>
                    Download
                </Button>,
            ]}
        >
            {failed ? (
                <Result status="warning" title="That document could not be prepared." />
            ) : src ? (
                <iframe
                    src={src}
                    title={title}
                    style={{ width: "100%", height: "78vh", border: 0 }}
                />
            ) : (
                <div style={{ display: "grid", placeItems: "center", height: "78vh" }}>
                    <Spin size="large" tip="Preparing the document…" />
                </div>
            )}
        </Modal>
    );
}
