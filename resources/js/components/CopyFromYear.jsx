import React, { useEffect, useMemo, useState } from "react";
import { Button, Checkbox, Empty, Modal, Radio, Space, Typography, message } from "antd";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import { SECTION_LABELS } from "~/utils/constants";

/**
 * An OPCR is largely the same year to year, so a new one can start from the
 * last rather than being retyped. Structure only — what was achieved and how it
 * was rated belongs to the year it happened in.
 *
 * Step 1: pick a source year. Step 2: pick which commitments to bring across.
 */
function plainText(html) {
    return String(html || "")
        .replace(/<[^>]+>/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

export default function CopyFromYear({ form, open, onClose }) {
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState(null);
    const [step, setStep] = useState(1);
    const [pickedOutputs, setPickedOutputs] = useState([]);
    const [pickedLines, setPickedLines] = useState([]);

    const { data: forms = [] } = useQuery({
        queryKey: ["opcr-history", form.id],
        queryFn: () => api.get("pcr-forms?type=opcr").then((r) => r.data),
        enabled: open,
    });

    const others = forms.filter((f) => f.id !== form.id && f.outputs_count > 0);
    const chosenForm = others.find((f) => f.id === chosen) ?? null;

    const { data: source, isFetching: loadingSource } = useQuery({
        queryKey: ["opcr-copy-source", chosen],
        queryFn: () => api.get(`pcr-forms/${chosen}`).then((r) => r.data),
        enabled: open && step === 2 && Boolean(chosen),
    });

    const outputs = useMemo(() => {
        const rows = source?.outputs ?? [];

        return [...rows].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id);
    }, [source]);

    const allOutputIds = useMemo(() => outputs.map((row) => row.id), [outputs]);
    const allLineIds = useMemo(
        () => outputs.flatMap((row) => (row.indicators ?? []).map((line) => line.id)),
        [outputs]
    );

    useEffect(() => {
        if (!open) {
            setChosen(null);
            setStep(1);
            setPickedOutputs([]);
            setPickedLines([]);
        }
    }, [open]);

    useEffect(() => {
        if (step !== 2 || !outputs.length) return;

        setPickedOutputs(allOutputIds);
        setPickedLines(allLineIds);
    }, [step, source?.id, allOutputIds, allLineIds, outputs.length]);

    const copy = useMutation({
        mutationFn: () =>
            api.post(`pcr-forms/${form.id}/copy-from`, {
                source_form_id: chosen,
                output_ids: pickedOutputs,
                indicator_ids: pickedLines,
            }),
        onSuccess: ({ data }) => {
            message.success(`${data.lines} commitments copied — edit them for this year.`);
            queryClient.invalidateQueries({ queryKey: ["pcr-form", String(form.id)] });
            onClose();
        },
        onError: (error) => {
            message.error(error?.response?.data?.message ?? "Could not copy from that year.");
        },
    });

    const toggleOutput = (output, checked) => {
        const lineIds = (output.indicators ?? []).map((line) => line.id);

        setPickedOutputs((prev) =>
            checked ? [...new Set([...prev, output.id])] : prev.filter((id) => id !== output.id)
        );
        setPickedLines((prev) =>
            checked
                ? [...new Set([...prev, ...lineIds])]
                : prev.filter((id) => !lineIds.includes(id))
        );
    };

    const toggleLine = (output, lineId, checked) => {
        const lineIds = (output.indicators ?? []).map((line) => line.id);

        setPickedLines((prev) => {
            const next = checked
                ? [...new Set([...prev, lineId])]
                : prev.filter((id) => id !== lineId);

            setPickedOutputs((outputsPrev) => {
                if (checked) return [...new Set([...outputsPrev, output.id])];

                const stillHasLine = lineIds.some((id) => next.includes(id));

                return stillHasLine ? outputsPrev : outputsPrev.filter((id) => id !== output.id);
            });

            return next;
        });
    };

    const selectAll = () => {
        setPickedOutputs(allOutputIds);
        setPickedLines(allLineIds);
    };

    const clearAll = () => {
        setPickedOutputs([]);
        setPickedLines([]);
    };

    const canCopy = chosen && (pickedOutputs.length > 0 || pickedLines.length > 0);

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title="Start from another year"
            okText={step === 1 ? "Next" : "Copy the commitments"}
            okButtonProps={{
                disabled: step === 1 ? !chosen : !canCopy,
                loading: copy.isPending,
            }}
            onOk={() => {
                if (step === 1) {
                    setStep(2);
                    return;
                }

                copy.mutate();
            }}
            footer={(_, { OkBtn, CancelBtn }) => (
                <div className="pms-copy-year-footer">
                    {step === 2 ? (
                        <Button type="link" onClick={() => setStep(1)} disabled={copy.isPending}>
                            Back
                        </Button>
                    ) : (
                        <span />
                    )}
                    <Space>
                        <CancelBtn />
                        <OkBtn />
                    </Space>
                </div>
            )}
            width={step === 2 ? 640 : 520}
            destroyOnClose
        >
            {others.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="There is no earlier OPCR to start from."
                />
            ) : step === 1 ? (
                <>
                    <Typography.Paragraph type="secondary">
                        The MFO/PPAs, success indicators and budgets are copied across.
                        Accomplishments, ratings and who was accountable are not — those belong
                        to the year they happened in.
                    </Typography.Paragraph>
                    <Radio.Group
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        style={{ width: "100%" }}
                    >
                        <Space direction="vertical" style={{ width: "100%" }}>
                            {others.map((f) => (
                                <Radio key={f.id} value={f.id}>
                                    {f.school_year?.label ?? "Earlier year"}{" "}
                                    <Typography.Text type="secondary">
                                        · {f.outputs_count} MFO/PPA
                                        {f.outputs_count === 1 ? "" : "s"}
                                    </Typography.Text>
                                </Radio>
                            ))}
                        </Space>
                    </Radio.Group>
                </>
            ) : (
                <>
                    <Typography.Paragraph type="secondary">
                        From <strong>{chosenForm?.school_year?.label ?? "that year"}</strong>, pick
                        which commitments to bring into this draft. Everything is selected by
                        default.
                    </Typography.Paragraph>

                    {loadingSource && !source ? (
                        <Typography.Text type="secondary">Loading commitments…</Typography.Text>
                    ) : outputs.length === 0 ? (
                        <Empty
                            image={Empty.PRESENTED_IMAGE_SIMPLE}
                            description="That year has no commitments to copy."
                        />
                    ) : (
                        <>
                            <div className="pms-copy-year-tools">
                                <Button type="link" size="small" onClick={selectAll}>
                                    Select all
                                </Button>
                                <Button type="link" size="small" onClick={clearAll}>
                                    Clear
                                </Button>
                                <Typography.Text type="secondary">
                                    {pickedLines.length} of {allLineIds.length} indicators ·{" "}
                                    {pickedOutputs.length} of {allOutputIds.length} MFO/PPAs
                                </Typography.Text>
                            </div>
                            <div className="pms-copy-year-list">
                                {outputs.map((output) => {
                                    const lines = output.indicators ?? [];
                                    const lineIds = lines.map((line) => line.id);
                                    const checkedLines = lineIds.filter((id) =>
                                        pickedLines.includes(id)
                                    );
                                    const allLinesOn =
                                        lineIds.length > 0 &&
                                        checkedLines.length === lineIds.length;
                                    const indeterminate =
                                        checkedLines.length > 0 &&
                                        checkedLines.length < lineIds.length;
                                    const outputChecked =
                                        lineIds.length === 0
                                            ? pickedOutputs.includes(output.id)
                                            : allLinesOn;

                                    return (
                                        <div key={output.id} className="pms-copy-year-output">
                                            <Checkbox
                                                checked={outputChecked}
                                                indeterminate={indeterminate}
                                                onChange={(e) =>
                                                    toggleOutput(output, e.target.checked)
                                                }
                                            >
                                                <span className="pms-copy-year-output-title">
                                                    {output.title || "Untitled MFO/PPA"}
                                                </span>
                                                <Typography.Text type="secondary">
                                                    {" "}
                                                    ·{" "}
                                                    {SECTION_LABELS[output.section] ??
                                                        output.section}
                                                </Typography.Text>
                                            </Checkbox>
                                            {lines.length > 0 && (
                                                <div className="pms-copy-year-lines">
                                                    {lines.map((line) => (
                                                        <Checkbox
                                                            key={line.id}
                                                            checked={pickedLines.includes(line.id)}
                                                            onChange={(e) =>
                                                                toggleLine(
                                                                    output,
                                                                    line.id,
                                                                    e.target.checked
                                                                )
                                                            }
                                                        >
                                                            {plainText(line.description) ||
                                                                "Success indicator"}
                                                        </Checkbox>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </>
            )}
        </Modal>
    );
}
