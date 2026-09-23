import React, { useEffect, useRef } from "react";
import { useEditor, EditorContent } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import Link from "@tiptap/extension-link";
import Mention from "@tiptap/extension-mention";
import { ReactRenderer } from "@tiptap/react";
import MentionList from "~/components/MentionList";
import { Space, Tooltip } from "antd";
import {
    BoldOutlined,
    ItalicOutlined,
    OrderedListOutlined,
    UnderlineOutlined,
    UnorderedListOutlined,
} from "@ant-design/icons";

/**
 * Tiptap hands us raw coordinates; this positions a small list beside the
 * caret and lets the keyboard drive it, the way an @ menu is expected to work.
 */
function makeSuggestionRenderer() {
    let component;
    let el;

    const place = (clientRect) => {
        if (!clientRect || !el) return;

        const rect = clientRect();

        if (!rect) return;

        el.style.top = `${rect.bottom + window.scrollY + 4}px`;
        el.style.left = `${rect.left + window.scrollX}px`;
    };

    return {
        onStart: (props) => {
            component = new ReactRenderer(MentionList, { props, editor: props.editor });

            el = document.createElement("div");
            el.className = "pms-mention-popup";
            el.appendChild(component.element);
            document.body.appendChild(el);

            place(props.clientRect);
        },
        onUpdate: (props) => {
            component?.updateProps(props);
            place(props.clientRect);
        },
        onKeyDown: (props) => {
            if (props.event.key === "Escape") {
                el?.remove();

                return true;
            }

            return component?.ref?.onKeyDown(props) ?? false;
        },
        onExit: () => {
            el?.remove();
            component?.destroy();
        },
    };
}

/**
 * The one editor every free-text field uses. Deliberately small: emphasis and
 * lists, nothing that can restyle or reflow the printed OPCR/IPCR.
 *
 * Props mirror the Ant textarea it replaces, so a swap is mechanical.
 */
export default function RichText({
    value,
    onChange,
    placeholder,
    rows = 4,
    disabled = false,
    autoFocus = false,
    mentions,
    maxLength,
}) {
    // The list is read through a ref so the suggestion popup always sees the
    // current people without the editor being torn down and rebuilt.
    const peopleRef = useRef(mentions ?? []);
    peopleRef.current = mentions ?? [];
    const editor = useEditor({
        editable: !disabled,
        extensions: [
            StarterKit.configure({
                heading: false,
                codeBlock: false,
                blockquote: false,
                horizontalRule: false,
            }),
            Placeholder.configure({ placeholder }),
            Link.configure({ openOnClick: false, autolink: false }),
            ...(mentions
                ? [
                      Mention.configure({
                          // A mention is a link to a person, so it survives
                          // sanitising and is clickable wherever it is shown.
                          renderHTML({ node }) {
                              return [
                                  "a",
                                  {
                                      href: `/people/${node.attrs.id}`,
                                      class: "pms-mention",
                                  },
                                  `@${node.attrs.label ?? node.attrs.id}`,
                              ];
                          },
                          suggestion: {
                              items: ({ query }) =>
                                  peopleRef.current
                                      .filter((p) =>
                                          p.name.toLowerCase().includes(query.toLowerCase())
                                      )
                                      .slice(0, 8),
                              render: makeSuggestionRenderer,
                          },
                      }),
                  ]
                : []),
        ],
        content: value || "",
        editorProps: maxLength
            ? {
                  handleTextInput(view, from, to, text) {
                      const current = view.state.doc.textContent.length;
                      const replacing = Math.max(0, to - from);

                      return current - replacing + text.length > maxLength;
                  },
                  handlePaste(view, event) {
                      const pasted = event.clipboardData?.getData("text/plain") ?? "";
                      const { from, to } = view.state.selection;
                      const current = view.state.doc.textContent.length;
                      const replacing = Math.max(0, to - from);

                      return current - replacing + pasted.length > maxLength;
                  },
              }
            : undefined,
        onUpdate: ({ editor: current }) => {
            // An editor cleared back to nothing should read as empty, not as
            // the empty paragraph it leaves behind.
            onChange?.(current.isEmpty ? "" : current.getHTML());
        },
        autofocus: autoFocus,
    });

    // Follow the field when it is reset or loaded from the server, without
    // fighting the person mid-sentence.
    useEffect(() => {
        if (!editor) return;

        const incoming = value || "";

        if (incoming !== (editor.isEmpty ? "" : editor.getHTML())) {
            editor.commands.setContent(incoming, false);
        }
    }, [value, editor]);

    useEffect(() => {
        editor?.setEditable(!disabled);
    }, [disabled, editor]);

    if (!editor) return null;

    const actions = [
        { key: "bold", icon: <BoldOutlined />, title: "Bold", run: () => editor.chain().focus().toggleBold().run() },
        { key: "italic", icon: <ItalicOutlined />, title: "Italic", run: () => editor.chain().focus().toggleItalic().run() },
        { key: "strike", icon: <UnderlineOutlined />, title: "Strikethrough", run: () => editor.chain().focus().toggleStrike().run() },
        { key: "bulletList", icon: <UnorderedListOutlined />, title: "Bulleted list", run: () => editor.chain().focus().toggleBulletList().run() },
        { key: "orderedList", icon: <OrderedListOutlined />, title: "Numbered list", run: () => editor.chain().focus().toggleOrderedList().run() },
    ];

    return (
        <div className={`pms-richtext${disabled ? " pms-richtext-disabled" : ""}`}>
            {!disabled && (
                <div className="pms-richtext-toolbar">
                    <Space size={2}>
                        {actions.map((action) => (
                            <Tooltip key={action.key} title={action.title}>
                                <button
                                    type="button"
                                    tabIndex={-1}
                                    aria-label={action.title}
                                    aria-pressed={editor.isActive(action.key)}
                                    className={
                                        editor.isActive(action.key)
                                            ? "pms-richtext-button is-active"
                                            : "pms-richtext-button"
                                    }
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={action.run}
                                >
                                    {action.icon}
                                </button>
                            </Tooltip>
                        ))}
                    </Space>
                </div>
            )}
            <EditorContent editor={editor} style={{ minHeight: rows * 22 }} />
            {maxLength ? (
                <div className="pms-richtext-count">
                    {editor.state.doc.textContent.length}/{maxLength}
                </div>
            ) : null}
        </div>
    );
}
