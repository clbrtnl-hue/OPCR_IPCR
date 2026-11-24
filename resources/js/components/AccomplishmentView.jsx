import React, { useEffect, useRef, useState } from "react";
import { Badge, Button, Popconfirm, Space, Typography, Upload, message } from "antd";
import { CloseOutlined, ExpandOutlined, LoadingOutlined, PaperClipOutlined } from "@ant-design/icons";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { NARRATIVE_MAX, UPLOAD_ACCEPT, UPLOAD_MAX_MB } from "~/utils/constants";
import RichText from "~/components/RichText";
import RichTextView from "~/components/RichTextView";
import AttachmentTile, { EvidencePreview } from "~/components/AttachmentTile";

export function AttachmentGallery({ files = [], canRemove = false, onRemove, style }) {
    if (!files.length) return null;

    return (
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
    );
}

function CellFiles({ files = [], canRemove = false, onRemove }) {
    const [openFile, setOpenFile] = useState(null);

    if (!files.length) return null;

    return (
        <div className="pms-cell-files">
            {files.map((file) => (
                <div key={file.id} className="pms-cell-file">
                    <button
                        type="button"
                        className="pms-cell-file-name"
                        title={file.original_name}
                        onClick={() => setOpenFile(file)}
                    >
                        {file.original_name}
                    </button>
                    {canRemove && (
                        <Popconfirm
                            title="Remove this evidence?"
                            onConfirm={() => onRemove(file.id)}
                        >
                            <button
                                type="button"
                                tabIndex={-1}
                                className="pms-cell-file-remove"
                                aria-label="Remove evidence"
                            >
                                <CloseOutlined />
                            </button>
                        </Popconfirm>
                    )}
                </div>
            ))}
            <EvidencePreview file={openFile} open={Boolean(openFile)} onClose={() => setOpenFile(null)} />
        </div>
    );
}

/**
 * The accomplishment column, written in place. Enter saves, Shift+Enter starts
 * a new line, Escape undoes — the same keys as every other cell. The drawer
 * stays available through Open.
 */
export function AccomplishmentField({
    formId,
    indicatorId,
    periodId,
    html,
    attachments = [],
    canRecord = false,
    mark = null,
    evidenceMark = null,
    empty = "Nothing recorded.",
    placeholder = "What was actually delivered against this target?",
    onOpen,
    extra = null,
    note = null,
}) {
    const queryClient = useQueryClient();
    const writable = Boolean(canRecord && periodId && indicatorId);
    const serverText = html ?? "";
    const serverRef = useRef(serverText);
    serverRef.current = serverText;
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(serverText);
    const [pending, setPending] = useState(false);
    const [uploading, setUploading] = useState(0);

    useEffect(() => {
        if (editing) return;

        if (pending && (html ?? "") !== draft) return;

        setDraft(html ?? "");
        setPending(false);
    }, [html, editing, pending, draft]);

    useEffect(() => {
        if (!pending) return undefined;

        const bail = setTimeout(() => {
            setPending(false);
            setDraft(serverRef.current);
        }, 8000);

        return () => clearTimeout(bail);
    }, [pending]);

    const refresh = () => queryClient.invalidateQueries({ queryKey: ["pcr-form", String(formId)] });

    const save = useMutation({
        mutationFn: (text) =>
            api.post("pcr-accomplishments", {
                indicator_id: indicatorId,
                rating_period_id: periodId,
                actual_accomplishment: text,
            }),
        onSuccess: () => refresh(),
        onError: () => {
            setPending(false);
            setDraft(serverRef.current);
        },
    });

    const removeAttachment = useMutation({
        mutationFn: (id) => api.delete(`pcr-attachments/${id}`),
        onSuccess: () => {
            message.success("Evidence removed.");
            refresh();
        },
    });

    const commit = (next) => {
        setEditing(false);

        const text = next ?? "";

        if (text === (serverRef.current ?? "")) return;

        setDraft(text);
        setPending(true);
        save.mutate(text === "" ? null : text);
    };

    const abandon = () => {
        setDraft(serverRef.current);
        setEditing(false);
    };

    const upload = async (file) => {
        if (file.size > UPLOAD_MAX_MB * 1024 * 1024) {
            message.error(`${file.name} is larger than ${UPLOAD_MAX_MB} MB. Compress it and try again.`);

            return Upload.LIST_IGNORE;
        }

        const body = new FormData();
        body.append("indicator_id", indicatorId);
        body.append("rating_period_id", periodId);
        body.append("file", file);

        setUploading((count) => count + 1);

        try {
            await api.post("pcr-attachments", body);
            message.success(`${file.name} attached.`);
            refresh();
        } catch {
            // The request layer already reports the failure.
        } finally {
            setUploading((count) => count - 1);
        }

        return Upload.LIST_IGNORE;
    };

    const text = pending ? draft : serverText;

    return (
        <>
            <div className={mark ? "pms-flag" : undefined}>
                {writable && editing ? (
                    <RichText
                        sheet
                        commitOnEnter
                        autoFocus
                        rows={2}
                        value={draft}
                        onChange={setDraft}
                        onCommit={commit}
                        onCancel={abandon}
                        maxLength={NARRATIVE_MAX}
                        placeholder={placeholder}
                    />
                ) : writable ? (
                    <div
                        className={pending ? "pms-cell is-saving" : "pms-cell"}
                        tabIndex={0}
                        role="button"
                        onClick={() => setEditing(true)}
                        onFocus={() => setEditing(true)}
                    >
                        {text ? (
                            <RichTextView html={text} />
                        ) : (
                            <span className="pms-cell-empty">{empty}</span>
                        )}
                        {pending && <LoadingOutlined className="pms-cell-spinner" />}
                    </div>
                ) : text ? (
                    <RichTextView html={text} />
                ) : (
                    empty && <span className="pms-cell-empty">{empty}</span>
                )}
                {mark && <div className="pms-cell-mark">{mark}</div>}
            </div>
            {note}
            <div className={evidenceMark ? "pms-flag" : undefined} style={{ marginTop: 4 }}>
                <Space size={6} wrap>
                    {writable && (
                        <Upload multiple accept={UPLOAD_ACCEPT} showUploadList={false} beforeUpload={upload}>
                            <Button
                                size="small"
                                type="text"
                                tabIndex={-1}
                                icon={<PaperClipOutlined />}
                                disabled={uploading > 0}
                            >
                                {uploading > 0 ? "Attaching…" : "Attach"}
                            </Button>
                        </Upload>
                    )}
                    {onOpen && (
                        <Button
                            size="small"
                            type="text"
                            tabIndex={-1}
                            icon={<ExpandOutlined />}
                            onClick={onOpen}
                        >
                            Open
                        </Button>
                    )}
                    {extra}
                </Space>
                <CellFiles
                    files={attachments}
                    canRemove={writable}
                    onRemove={(id) => removeAttachment.mutate(id)}
                />
                {evidenceMark && <div className="pms-cell-mark">{evidenceMark}</div>}
            </div>
        </>
    );
}

export function AccomplishmentCell({
    html,
    evidenceCount = 0,
    empty = "Nothing recorded.",
    label = "Open",
    onOpen,
    mark = null,
    evidenceMark = null,
}) {
    return (
        <>
            <div className={mark ? "pms-flag" : undefined}>
                {html ? (
                    <RichTextView html={html} />
                ) : (
                    <span className="pms-cell-empty">{empty}</span>
                )}
                {mark && <div className="pms-cell-mark">{mark}</div>}
            </div>
            <div className={evidenceMark ? "pms-flag" : undefined} style={{ marginTop: 4 }}>
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
                {evidenceMark && <div className="pms-cell-mark">{evidenceMark}</div>}
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
