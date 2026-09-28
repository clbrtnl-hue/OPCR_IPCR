import React from "react";
import { Button, Drawer, Empty, Input } from "antd";
import { CheckOutlined, SearchOutlined } from "@ant-design/icons";
import UserAvatar from "~/components/UserAvatar";

export default function AssignDrawer({ open, kind, title, isOpcr = false, people = [], loading, onClose, onAssign }) {
    const [picked, setPicked] = React.useState([]);
    const [query, setQuery] = React.useState("");

    React.useEffect(() => {
        if (!open) return;

        setPicked([]);
        setQuery("");
    }, [open]);

    const needle = query.trim().toLowerCase();
    const shown = people.filter((person) =>
        `${person.name} ${person.position_title ?? ""}`.toLowerCase().includes(needle)
    );

    const note =
        kind === "output"
            ? "Each person gets this MFO/PPA in their own IPCR and writes their own success indicators under it."
            : isOpcr
              ? "They will see this office target on their IPCR and write their own commitments against it. The college wording is not copied."
              : "They will see this target on their IPCR and write their own commitments against it. The wording is not copied.";

    return (
        <Drawer
            open={open}
            onClose={onClose}
            placement="right"
            width={480}
            className="pms-cascade-drawer"
            title="Assign"
            footer={
                <div className="pms-cascade-foot">
                    <div>
                        <strong>
                            {picked.length === 0
                                ? "No one selected"
                                : `${picked.length} ${picked.length === 1 ? "person" : "people"}`}
                        </strong>
                        <span>{title || "This target"}</span>
                    </div>
                    <div className="pms-cascade-foot-actions">
                        <Button onClick={onClose}>Cancel</Button>
                        <Button
                            type="primary"
                            disabled={picked.length === 0}
                            loading={loading}
                            onClick={() => onAssign(picked)}
                        >
                            Assign
                        </Button>
                    </div>
                </div>
            }
        >
            <p className="pms-cascade-lead">{note}</p>
            {title && <p className="pms-assign-target">{title}</p>}

            <span className="pms-cascade-label">Who</span>
            <Input
                allowClear
                prefix={<SearchOutlined />}
                placeholder="Search people"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />

            {people.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="No one on your team can receive this yet."
                    style={{ margin: "16px 0" }}
                />
            ) : shown.length === 0 ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="No one matches that search."
                    style={{ margin: "16px 0" }}
                />
            ) : (
                <div className="pms-cascade-people">
                    {shown.map((person) => {
                        const on = picked.includes(person.id);

                        return (
                            <button
                                key={person.id}
                                type="button"
                                className={on ? "pms-cascade-person is-on" : "pms-cascade-person"}
                                onClick={() =>
                                    setPicked((current) =>
                                        current.includes(person.id)
                                            ? current.filter((id) => id !== person.id)
                                            : [...current, person.id]
                                    )
                                }
                            >
                                <UserAvatar user={person} size={36} showTooltip={false} />
                                <span className="pms-cascade-person-text">
                                    <strong>{person.name}</strong>
                                    {person.position_title && <span>{person.position_title}</span>}
                                </span>
                                {on && <CheckOutlined className="pms-cascade-check" />}
                            </button>
                        );
                    })}
                </div>
            )}
        </Drawer>
    );
}
