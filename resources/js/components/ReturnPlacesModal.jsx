import { useEffect, useMemo, useState } from "react";
import { Checkbox, Input, Modal, Tag, Typography } from "antd";
import { returnPlaces } from "~/utils/returnMarks";

/**
 * A return from commitment approval names the cells. The reviewer checks a
 * place and writes what to change there. Blank rows are left alone.
 */
export default function ReturnPlacesModal({ open, form, kind = "approval", confirming, onCancel, onSubmit }) {
    const [notes, setNotes] = useState({});
    const places = useMemo(() => (form ? returnPlaces(form, kind) : []), [form, kind]);
    const groups = useMemo(() => {
        const buckets = [];

        places.forEach((place) => {
            const last = buckets[buckets.length - 1];

            if (last?.section === place.section) last.places.push(place);
            else buckets.push({ section: place.section, label: place.sectionLabel, places: [place] });
        });

        return buckets;
    }, [places]);

    useEffect(() => {
        if (!open) setNotes({});
    }, [open]);
    const chosen = places.filter((place) => notes[place.key]?.trim());

    const close = () => {
        setNotes({});
        onCancel();
    };

    const toggle = (key, checked) => {
        setNotes((prev) => {
            const next = { ...prev };

            if (checked) next[key] = next[key] ?? "";
            else delete next[key];

            return next;
        });
    };

    return (
        <Modal
            title="Return this form"
            open={open}
            onCancel={close}
            okText="Return"
            okButtonProps={{ danger: true, disabled: chosen.length === 0 }}
            confirmLoading={confirming}
            width={640}
            onOk={() =>
                onSubmit(
                    chosen.map((place) => ({
                        output_id: place.output_id,
                        indicator_id: place.indicator_id,
                        field: place.field,
                        note: notes[place.key].trim(),
                    }))
                )
            }
        >
            <Typography.Paragraph>
                {kind === "writeup"
                    ? "Choose the accomplishment or the evidence that needs fixing, and say what to change. The writer sees the note on that cell."
                    : "Choose each place that needs fixing and say what to change. The writer sees the note on that cell."}
            </Typography.Paragraph>

            {places.length === 0 ? (
                <Typography.Text type="secondary">This form has no commitments to point at yet.</Typography.Text>
            ) : (
                <div className="pms-return-places">
                    {groups.map((group) => (
                        <div key={group.section} className="pms-return-group">
                            <div className="pms-return-section">{group.label}</div>
                            {group.places.map((place) => {
                                const checked = place.key in notes;

                                return (
                                    <div key={place.key} className="pms-return-place">
                                        <Checkbox
                                            checked={checked}
                                            onChange={(event) => toggle(place.key, event.target.checked)}
                                        >
                                            <Tag>{place.fieldLabel}</Tag>
                                            <span>{place.label}</span>
                                        </Checkbox>
                                        {checked && (
                                            <Input.TextArea
                                                rows={2}
                                                maxLength={500}
                                                value={notes[place.key]}
                                                placeholder={`What should change in the ${place.fieldLabel.toLowerCase()}?`}
                                                onChange={(event) =>
                                                    setNotes((prev) => ({ ...prev, [place.key]: event.target.value }))
                                                }
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}
        </Modal>
    );
}
