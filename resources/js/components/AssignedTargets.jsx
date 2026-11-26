import React, { useMemo, useState } from "react";
import { Alert, Button, Dropdown, Modal, Radio, Space, Tooltip, message } from "antd";
import { DownOutlined } from "@ant-design/icons";
import { useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { toPlainText } from "~/components/RichTextView";
import { SECTION_LABELS } from "~/utils/constants";
import { commitmentHomes, commitmentSection } from "~/utils/commitmentHome";

function linesAgainst(form, targetId) {
    return (form?.outputs ?? []).flatMap((output) =>
        (output.indicators ?? [])
            .filter((line) => !line.closed_at && Number(line.parent_indicator_id) === Number(targetId))
            .map((line) => ({
                id: line.id,
                label: toPlainText(line.description) || "Blank commitment",
            }))
    );
}

function homeLabel(output) {
    const name = toPlainText(output.title) || "Untitled MFO/PPA";
    const number = output.outline_number ? `${output.outline_number}. ` : "";

    return `${number}${name} · ${SECTION_LABELS[output.section] ?? output.section}`;
}

function TargetList({ targets, notedIds, form, canWrite, writingId, onWrite, onOpen }) {
    const noted = new Set(notedIds);

    return (
        <ul className="pms-assigned-targets">
            {targets.map((target) => {
                const written = linesAgainst(form, target.id);

                const text = (
                    <>
                        <strong>{target.output_title}</strong>
                        {" — "}
                        {toPlainText(target.description)}
                        {target.assigned_by_name ? ` · ${target.assigned_by_name}` : ""}
                    </>
                );
                const busy = writingId === target.id;

                return (
                    <li
                        key={target.id}
                        data-assigned-target={target.id}
                        className={
                            [noted.has(target.id) ? "is-noted" : "", canWrite ? "is-writable" : "", busy ? "is-writing" : ""]
                                .filter(Boolean)
                                .join(" ") || undefined
                        }
                    >
                        <div className="pms-assigned-line">
                            {canWrite ? (
                                <Tooltip
                                    title={written.length ? "Write another commitment" : "Write a commitment"}
                                >
                                    <button
                                        type="button"
                                        className="pms-assigned-hit"
                                        disabled={writingId != null && !busy}
                                        onClick={() => onWrite(target)}
                                    >
                                        {text}
                                    </button>
                                </Tooltip>
                            ) : (
                                <span>{text}</span>
                            )}
                            {written.length > 0 && (
                                <span className="pms-assigned-open">
                                    {written.length === 1 ? (
                                        <Button type="link" size="small" onClick={() => onOpen(written[0].id)}>
                                            Open
                                        </Button>
                                    ) : (
                                        <Dropdown
                                            menu={{
                                                items: written.map((line) => ({
                                                    key: line.id,
                                                    label: line.label,
                                                    onClick: () => onOpen(line.id),
                                                })),
                                            }}
                                        >
                                            <Button type="link" size="small">
                                                Open <DownOutlined />
                                            </Button>
                                        </Dropdown>
                                    )}
                                </span>
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

export default function AssignedTargets({ form, targets, notedIds, canWrite, periodId, onArrive }) {
    const queryClient = useQueryClient();
    const noted = useMemo(() => new Set(notedIds), [notedIds]);
    const fromNotice = noted.size ? targets.filter((target) => noted.has(target.id)) : [];
    const rest = noted.size ? targets.filter((target) => !noted.has(target.id)) : targets;
    const title = fromNotice.length ? "From this notification" : "Targets assigned to you";
    const [open, setOpen] = useState(fromNotice.length > 0);
    const [writingId, setWritingId] = useState(null);
    const [choice, setChoice] = useState(null);

    const place = async (target, outputId) => {
        setWritingId(target.id);
        let createdOutputId = null;

        try {
            let homeId = outputId;

            if (!homeId) {
                const created = await api.post("pcr-outputs", {
                    form_id: form.id,
                    section: commitmentSection(target),
                    title: "",
                });
                createdOutputId = created.data.output.id;
                homeId = createdOutputId;
            }

            const saved = await api.post("pcr-indicators", {
                output_id: homeId,
                description: "",
                parent_indicator_id: target.id,
                rating_period_id: periodId || undefined,
            });

            const homeTitle = (form.outputs ?? []).find((output) => output.id === homeId)?.title;
            const openOutput = Boolean(createdOutputId) || !toPlainText(homeTitle || "");

            await queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            message.success(
                openOutput
                    ? "Commitment added. Name the MFO/PPA, then write the target and measure."
                    : "Commitment added. Write the target and measure."
            );
            onArrive?.({
                id: saved.data.indicator.id,
                outputId: homeId,
                openOutput,
            });
            setChoice(null);
        } catch {
            if (createdOutputId) {
                await api.delete(`pcr-outputs/${createdOutputId}`).catch(() => {});
            }
        } finally {
            setWritingId(null);
        }
    };

    const onWrite = (target) => {
        if (writingId != null) return;

        // A heading handed down from the college OPCR is already the MFO —
        // commitments sit under it. Every other assigned target gets its own
        // new MFO/PPA and success indicator, not an existing sheet heading.
        const locked = commitmentHomes(target, form.outputs).filter(
            (output) =>
                output.assigned_by && Number(output.parent_output_id) === Number(target?.output_id)
        );

        if (locked.length > 1) {
            setChoice({ target, homes: locked, outputId: locked[0].id });
            return;
        }

        if (locked.length === 1) {
            place(target, locked[0].id);
            return;
        }

        place(target, null);
    };

    const listProps = { form, canWrite, writingId, onWrite, onOpen: onArrive };

    return (
        <>
            <Alert
                className={open ? "pms-assigned is-open" : "pms-assigned"}
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message={
                    <>
                        <span className="pms-assigned-label">{title}</span>
                        <button
                            type="button"
                            className="pms-assigned-toggle"
                            aria-expanded={open}
                            onClick={() => setOpen((value) => !value)}
                        >
                            <span>{title}</span>
                            <span className="pms-assigned-count">{targets.length}</span>
                            <DownOutlined />
                        </button>
                    </>
                }
                description={
                    <>
                        {fromNotice.length > 0 && (
                            <TargetList targets={fromNotice} notedIds={notedIds} {...listProps} />
                        )}
                        {rest.length > 0 && (
                            <>
                                {fromNotice.length > 0 && (
                                    <div className="pms-assigned-also">Also assigned to you</div>
                                )}
                                <TargetList targets={rest} notedIds={[]} {...listProps} />
                            </>
                        )}
                    </>
                }
            />
            <Modal
                title="Which MFO/PPA should this commitment sit under?"
                open={Boolean(choice)}
                okText="Write here"
                confirmLoading={writingId != null}
                onCancel={() => writingId == null && setChoice(null)}
                onOk={() => choice && place(choice.target, choice.outputId)}
            >
                <Radio.Group
                    value={choice?.outputId}
                    onChange={(event) =>
                        setChoice((current) =>
                            current ? { ...current, outputId: event.target.value } : current
                        )
                    }
                >
                    <Space direction="vertical">
                        {(choice?.homes ?? []).map((output) => (
                            <Radio key={output.id} value={output.id}>
                                {homeLabel(output)}
                            </Radio>
                        ))}
                    </Space>
                </Radio.Group>
            </Modal>
        </>
    );
}
