import React, { forwardRef, useEffect, useImperativeHandle, useState } from "react";
import { Empty, Tag } from "antd";
import UserAvatar from "~/components/UserAvatar";
import { ROLE_LABELS } from "~/utils/constants";

/**
 * The @ menu. Tiptap drives it by keyboard, so the highlighted row has to
 * follow the arrow keys and Enter has to pick it — a mouse alone is not enough
 * when you are mid-sentence.
 */
const MentionList = forwardRef(({ items, command }, ref) => {
    const [selected, setSelected] = useState(0);

    useEffect(() => setSelected(0), [items]);

    const pick = (index) => {
        const person = items[index];

        if (person) {
            command({ id: person.id, label: person.name });
        }
    };

    useImperativeHandle(ref, () => ({
        onKeyDown: ({ event }) => {
            if (event.key === "ArrowUp") {
                setSelected((current) => (current + items.length - 1) % items.length);

                return true;
            }

            if (event.key === "ArrowDown") {
                setSelected((current) => (current + 1) % items.length);

                return true;
            }

            if (event.key === "Enter" || event.key === "Tab") {
                pick(selected);

                return true;
            }

            return false;
        },
    }));

    if (!items.length) {
        return (
            <div className="pms-mention-list">
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nobody by that name" />
            </div>
        );
    }

    return (
        <div className="pms-mention-list">
            {items.map((person, index) => (
                <button
                    type="button"
                    key={person.id}
                    className={index === selected ? "pms-mention-item is-active" : "pms-mention-item"}
                    onMouseEnter={() => setSelected(index)}
                    onMouseDown={(event) => {
                        event.preventDefault();
                        pick(index);
                    }}
                >
                    <UserAvatar user={person} size="small" showTooltip={false} />
                    <span className="pms-mention-name">{person.name}</span>
                    <Tag>{ROLE_LABELS[person.role] ?? person.role}</Tag>
                </button>
            ))}
        </div>
    );
});

MentionList.displayName = "MentionList";

export default MentionList;
