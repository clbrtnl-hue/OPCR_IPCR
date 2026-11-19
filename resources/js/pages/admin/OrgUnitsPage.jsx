import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import {
    Alert,
    Button,
    Drawer,
    Empty,
    Form,
    Input,
    Modal,
    Select,
    Spin,
    Tooltip,
    message,
} from "antd";
import { ExpandOutlined, MoreOutlined, PlusOutlined } from "@ant-design/icons";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";
import { usePerson } from "~/hooks/usePerson";

const TYPE_LABELS = { college: "College", office: "Office", program: "Program" };

function byName(a, b) {
    return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
}

const POSTER_ORDER = {
    SAS: 1,
    AAS: 2,
    AFS: 3,
    RPCES: 4,
    ILS: 5,
    OP: 6,
    "OP-CICT": 1,
    "OP-QAAO": 2,
    "OP-PCO": 3,
    "OP-CHRE": 4,
    "AAS-ASD": 1,
    "AAS-ASV": 2,
    "AAS-HEI": 1,
    REG: 1,
    "AAS-TESDA": 2,
    "REG-AE": 1,
    "REG-RA": 2,
    "REG-GC": 3,
    "AAS-CTE": 1,
    "AAS-BSBA": 2,
    "AAS-BSIT": 3,
    "AAS-CCJ": 4,
    "AAS-GenEd": 5,
    "AAS-PE": 6,
    "SAS-SSS": 1,
    "SAS-SDS": 2,
    "SAS-ISPS": 3,
    "SAS-SEC": 4,
};

function byOrg(a, b) {
    const left = POSTER_ORDER[a.code] ?? 100;
    const right = POSTER_ORDER[b.code] ?? 100;
    if (left !== right) return left - right;
    return byName(a, b);
}

function nestUnits(units) {
    const nodes = new Map(units.map((unit) => [unit.id, { ...unit, children: [] }]));
    const placed = new Set();

    [...units].sort(byOrg).forEach((unit) => {
        const node = nodes.get(unit.id);
        const parent = unit.parent_id ? nodes.get(unit.parent_id) : null;

        if (parent && parent.id !== node.id) {
            parent.children.push(node);
            placed.add(node.id);
        }
    });

    const roots = [...units]
        .filter((unit) => !placed.has(unit.id))
        .map((unit) => nodes.get(unit.id))
        .sort((a, b) => {
            if ((a.type === "college") !== (b.type === "college")) {
                return a.type === "college" ? -1 : 1;
            }
            return byOrg(a, b);
        });

    return dropEmptyChildren(roots);
}

function dropEmptyChildren(nodes) {
    return nodes.map((node) => {
        const children = dropEmptyChildren(node.children);
        if (children.length === 0) {
            const rest = { ...node };
            delete rest.children;
            return rest;
        }
        return { ...node, children };
    });
}

function flattenUnits(nodes, depth = 0) {
    return nodes.flatMap((node) => [
        { id: node.id, name: node.name, depth },
        ...(node.children ? flattenUnits(node.children, depth + 1) : []),
    ]);
}

function findUnit(nodes, id) {
    for (const node of nodes) {
        if (node.id === id) return node;
        const nested = node.children ? findUnit(node.children, id) : null;
        if (nested) return nested;
    }
    return null;
}

function descendantIds(node) {
    return (node.children ?? []).flatMap((child) => [child.id, ...descendantIds(child)]);
}

function leadPerson(node, depth) {
    const director = (node.reviewers ?? []).find((row) => row.slot === "director_review")?.user;
    if (depth === 1 && node.vp?.name) return node.vp;
    if (node.head?.name) return node.head;
    if (node.dean?.name) return node.dean;
    if (director?.name) return director;
    return null;
}

function chartPeople(node) {
    const numbered = new Map();
    const legacy = [];

    for (const row of node?.reviewers ?? []) {
        if (!row.user?.name) continue;
        if (row.slot === "chart_display") legacy[0] = row.user;
        else if (row.slot === "chart_display_2") legacy[1] = row.user;
        else {
            const match = /^chart_display_(\d+)$/.exec(row.slot ?? "");
            if (match) numbered.set(Number(match[1]), row.user);
        }
    }

    const source = numbered.size
        ? [...numbered.entries()].sort((a, b) => a[0] - b[0]).map(([, user]) => user)
        : legacy.filter(Boolean);

    const seen = new Set();
    return source.filter((user) => {
        if (seen.has(user.id)) return false;
        seen.add(user.id);
        return true;
    });
}

function chartShown(node) {
    return chartPeople(node)[0] ?? null;
}

function chartPerson(node, depth) {
    return leadPerson(node, depth) ?? chartShown(node);
}

const AVATAR_BY_RANK = { president: 80, vp: 56, head: 48, child: 42 };

function UnitActions({ node, onAdd, onDelete }) {
    const canDelete = !(node.direct_members_count > 0);
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState({ top: 0, left: 0 });

    useEffect(() => {
        if (!open) return undefined;
        const close = (event) => {
            if (event.target.closest?.(".pms-org-menu, .pms-org-card-more")) return;
            setOpen(false);
        };
        window.addEventListener("pointerdown", close, true);
        return () => window.removeEventListener("pointerdown", close, true);
    }, [open]);

    const toggle = (event) => {
        event.stopPropagation();
        const rect = event.currentTarget.getBoundingClientRect();
        setPos({ top: rect.bottom + 4, left: rect.right });
        setOpen((value) => !value);
    };

    const choose = (event, action) => {
        event.stopPropagation();
        setOpen(false);
        action();
    };

    return (
        <>
            <button
                type="button"
                className="pms-org-card-more"
                aria-label={`Actions for ${node.name}`}
                aria-expanded={open}
                onPointerDown={(event) => event.stopPropagation()}
                onClick={toggle}
                onKeyDown={(event) => event.stopPropagation()}
            >
                <MoreOutlined />
            </button>
            {open && createPortal(
                <div className="pms-org-menu" style={{ top: pos.top, left: pos.left }}>
                    <button type="button" onClick={(event) => choose(event, () => onAdd(node.id))}>
                        Add unit under this
                    </button>
                    {canDelete && (
                        <button type="button" className="is-danger" onClick={(event) => choose(event, () => onDelete(node))}>
                            Delete
                        </button>
                    )}
                </div>,
                document.body,
            )}
        </>
    );
}

function UnitNode({ node, depth, rank = "head", onEdit, onAdd, onDelete }) {
    const { openPerson } = usePerson();
    const openUnit = () => onEdit(node);
    const banner = depth === 0 ? "root"
        : depth === 1 ? "division"
        : depth === 2 ? "department"
        : depth === 3 ? "section"
        : depth === 4 ? "office"
        : "program";
    const namedUnit = depth >= 2;
    const lead = leadPerson(node, depth);
    const shown = chartPeople(node).filter((user) => user.id !== lead?.id);
    const person = lead?.name ? lead : shown[0] ?? null;
    const personName = person?.name;
    const showCard = depth === 0 || Boolean(personName);
    const besideVp = depth === 1 && Boolean(lead?.name);
    const sideStaff = lead?.name && shown.length > 1
        ? shown.filter((user) => user.position_title === "Support Staff")
        : [];
    const flankRight = lead?.name
        ? (depth === 0 ? shown.filter((_, index) => index % 2 === 0) : besideVp ? shown : sideStaff)
        : [];
    const flankLeft = depth === 0 && lead?.name ? shown.filter((_, index) => index % 2 === 1) : [];
    const flanking = new Set([...flankRight, ...flankLeft].map((user) => user.id));
    const under = shown.filter((user) => !flanking.has(user.id) && (lead?.name || user.id !== shown[0]?.id));
    const missing = depth === 1 ? "VP not set" : "Head not set";
    const title = personName || missing;
    const role = personName ? (person.position_title || node.name) : TYPE_LABELS[node.type];
    const actions = <UnitActions node={node} onAdd={onAdd} onDelete={onDelete} />;

    const openProfile = () => {
        if (person?.id) openPerson(person.id);
    };

    return (
        <div className={`pms-org-node${namedUnit && !showCard ? " is-name" : ""}${flankRight.length || flankLeft.length ? " has-flank" : ""}${depth > 0 && flankRight.length ? " has-side" : ""}`}>
            {banner && (
                <div className="pms-org-banner-wrap">
                    <button type="button" className={`pms-org-banner is-${banner}`} onClick={openUnit}>
                        {node.name}
                    </button>
                    {actions}
                </div>
            )}
            {showCard && (
                <article
                    className={`pms-org-card is-${rank}`}
                    role="button"
                    tabIndex={0}
                    onClick={openProfile}
                    onKeyDown={(event) => {
                        if (event.key === "Enter" || event.key === " ") {
                            event.preventDefault();
                            openProfile();
                        }
                    }}
                >
                    <div className="pms-org-card-main">
                        <span className="pms-org-card-avatar">
                            <UserAvatar user={person} name={personName || node.name} size={AVATAR_BY_RANK[rank]} showTooltip={false} />
                        </span>
                        <div className="pms-org-card-id">
                            <strong className={`pms-org-card-name${!personName && banner ? " is-empty" : ""}`}>{title}</strong>
                            <span className="pms-org-card-role">{role}</span>
                        </div>
                    </div>
                </article>
            )}
            {[["right", flankRight], ["left", flankLeft]].map(([side, list]) => (
                list.length > 0 && (
                    <div key={side} className={`pms-org-flank is-${side}${depth > 0 ? " is-side" : ""}`}>
                        {list.map((shownPerson) => (
                            <article
                                key={shownPerson.id}
                                className={`pms-org-card is-child is-flank${depth > 0 ? " is-side" : ""}`}
                                role="button"
                                tabIndex={0}
                                onClick={() => openPerson(shownPerson.id)}
                                onKeyDown={(event) => {
                                    if (event.key === "Enter" || event.key === " ") {
                                        event.preventDefault();
                                        openPerson(shownPerson.id);
                                    }
                                }}
                            >
                                <div className="pms-org-card-main">
                                    <span className="pms-org-card-avatar">
                                        <UserAvatar user={shownPerson} name={shownPerson.name} size={depth > 0 ? 30 : 40} showTooltip={false} />
                                    </span>
                                    <div className="pms-org-card-id">
                                        <strong className="pms-org-card-name">{shownPerson.name}</strong>
                                        <span className="pms-org-card-role">{shownPerson.position_title || node.name}</span>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )
            ))}
            {under.map((shown) => (
                <article
                    key={shown.id}
                    className="pms-org-card is-child"
                    role="button"
                    tabIndex={0}
                    onClick={() => openPerson(shown.id)}
                    onKeyDown={(event) => {
                        if (event.key === "Enter" || event.key === " ") {
                            event.preventDefault();
                            openPerson(shown.id);
                        }
                    }}
                >
                    <div className="pms-org-card-main">
                        <span className="pms-org-card-avatar">
                            <UserAvatar user={shown} name={shown.name} size={AVATAR_BY_RANK.child} showTooltip={false} />
                        </span>
                        <div className="pms-org-card-id">
                            <strong className="pms-org-card-name">{shown.name}</strong>
                            <span className="pms-org-card-role">{shown.position_title || node.name}</span>
                        </div>
                    </div>
                </article>
            ))}
        </div>
    );
}

function OrgViewport({ children }) {
    const viewport = useRef(null);
    const tree = useRef(null);
    const scaleRef = useRef(1);
    const fitted = useRef(false);
    const drag = useRef(null);
    const suppressClick = useRef(false);
    const [scale, setScale] = useState(1);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [grabbing, setGrabbing] = useState(false);

    useLayoutEffect(() => {
        const node = tree.current;
        if (!node) return undefined;

        const measure = () => {
            const width = node.offsetWidth;
            const height = node.offsetHeight;
            setSize({ width, height });

            const view = viewport.current;
            if (!fitted.current && view && width > 0) {
                fitted.current = true;
                const fit = (view.clientWidth - 8) / width;
                const start = Math.min(1, Math.max(0.55, fit));
                scaleRef.current = start;
                setScale(start);
            }
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);
        return () => observer.disconnect();
    }, [children]);

    useEffect(() => {
        const element = viewport.current;
        if (!element) return undefined;

        const onWheel = (event) => {
            event.preventDefault();
            const old = scaleRef.current;
            const next = Math.min(1.8, Math.max(0.4, old * (event.deltaY < 0 ? 1.08 : 1 / 1.08)));
            if (Math.abs(next - old) < 0.001) return;

            const rect = element.getBoundingClientRect();
            const contentX = (event.clientX - rect.left + element.scrollLeft) / old;
            const contentY = (event.clientY - rect.top + element.scrollTop) / old;
            scaleRef.current = next;
            setScale(next);

            requestAnimationFrame(() => {
                element.scrollLeft = contentX * next - (event.clientX - rect.left);
                element.scrollTop = contentY * next - (event.clientY - rect.top);
            });
        };

        element.addEventListener("wheel", onWheel, { passive: false });
        return () => element.removeEventListener("wheel", onWheel);
    }, []);

    const onPointerDown = (event) => {
        if (event.button !== 0) return;
        if (event.target.closest("button, .pms-org-card, .pms-org-banner, .pms-org-menu")) return;
        const element = viewport.current;
        drag.current = {
            x: event.clientX,
            y: event.clientY,
            left: element.scrollLeft,
            top: element.scrollTop,
            moved: false,
        };
        setGrabbing(true);
        element.setPointerCapture(event.pointerId);
    };

    const onPointerMove = (event) => {
        const current = drag.current;
        if (!current) return;
        const dx = event.clientX - current.x;
        const dy = event.clientY - current.y;
        if (Math.abs(dx) <= 4 && Math.abs(dy) <= 4) return;
        current.moved = true;
        viewport.current.scrollLeft = current.left - dx;
        viewport.current.scrollTop = current.top - dy;
    };

    const onPointerUp = () => {
        suppressClick.current = Boolean(drag.current?.moved);
        drag.current = null;
        setGrabbing(false);
    };

    const onClickCapture = (event) => {
        if (!suppressClick.current) return;
        suppressClick.current = false;
        event.preventDefault();
        event.stopPropagation();
    };

    return (
        <div className="pms-org-frame">
            <p className="pms-org-zoom-hint">Mouse wheel zooms. Drag to move around.</p>
            <div
                className={`pms-org-chart${grabbing ? " is-grabbing" : ""}`}
                ref={viewport}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onClickCapture={onClickCapture}
            >
                <div className="pms-org-stage" style={{ width: size.width * scale, height: size.height * scale }}>
                    <div className="pms-org-scale" ref={tree} style={{ transform: `scale(${scale})` }}>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}

function shownDirector(node, depth) {
    const person = chartPerson(node, depth);
    const director = (node.reviewers ?? []).find((row) => row.slot === "director_review")?.user;
    return Boolean(person?.id && director?.id && person.id === director.id);
}

function OrgNode({ node, depth = 0, personAbove = false, onEdit, onAdd, onDelete }) {
    const children = node.children ?? [];
    const childClass = depth < 2 ? "pms-org-tree" : "pms-org-stack";
    const rank = depth === 0 ? "president" : depth === 1 && leadPerson(node, depth)?.name ? "vp" : shownDirector(node, depth) || !personAbove ? "head" : "child";
    const nextAbove = personAbove || (depth >= 2 && Boolean(chartPerson(node, depth)?.name));

    return (
        <li>
            <UnitNode node={node} depth={depth} rank={rank} onEdit={onEdit} onAdd={onAdd} onDelete={onDelete} />
            {children.length > 0 && (
                <ul className={childClass}>
                    {children.map((child) => (
                        <OrgNode
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            personAbove={nextAbove}
                            onEdit={onEdit}
                            onAdd={onAdd}
                            onDelete={onDelete}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

function OrgTree({ nodes, onEdit, onAdd, onDelete }) {
    return (
        <ul className="pms-org-tree">
            {nodes.map((node) => (
                <OrgNode
                    key={node.id}
                    node={node}
                    depth={0}
                    onEdit={onEdit}
                    onAdd={onAdd}
                    onDelete={onDelete}
                />
            ))}
        </ul>
    );
}

export default function OrgUnitsPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [wide, setWide] = useState(false);
    const [placeLocked, setPlaceLocked] = useState(false);
    const [lockedParentId, setLockedParentId] = useState(null);

    const { data: units = [], isLoading } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const { data: people = [] } = useQuery({
        queryKey: ["user-options"],
        queryFn: () => api.get("users/options").then((r) => r.data),
    });

    const { data: workflow } = useQuery({
        queryKey: ["workflow-settings"],
        queryFn: () => api.get("workflow-settings").then((r) => r.data),
    });

    const extraStages = (workflow?.settings?.review_stages ?? []).filter(
        (stage) =>
            (stage.source ?? "unit_slot") === "unit_slot" &&
            !["head_user_id", "dean_user_id", "vp_user_id"].includes(stage.slot)
    );

    const heads = people.filter((p) => ["program_head", "admin", "vp", "qa", "president"].includes(p.role));
    const vps = people.filter((p) => ["vp", "admin"].includes(p.role));
    const viewers = people.filter((p) => p.role !== "admin");
    const tree = useMemo(() => nestUnits(units), [units]);

    const parentOptions = flattenUnits(tree).filter((unit) => {
        if (unit.id === editing?.id) return false;
        const current = editing ? findUnit(tree, editing.id) : null;
        return !current || !descendantIds(current).includes(unit.id);
    });

    const save = useMutation({
        mutationFn: (values) => api.post("org-units", values),
        onSuccess: ({ data }) => {
            message.success(data.data === "created" ? "Unit created." : "Unit updated.");
            queryClient.invalidateQueries({ queryKey: ["org-units"] });
            close();
        },
    });

    const remove = useMutation({
        mutationFn: (id) => api.delete(`org-units/${id}`),
        onSuccess: () => {
            message.success("Unit deleted.");
            queryClient.invalidateQueries({ queryKey: ["org-units"] });
        },
    });

    const openDrawer = (record, parentId = null, lockPlace = false) => {
        const place = lockPlace ? (record ? record.parent_id ?? null : parentId) : null;
        setEditing(record ?? null);
        setPlaceLocked(lockPlace);
        setLockedParentId(place);
        setOpen(true);
        const reviewers = Object.fromEntries((record?.reviewers ?? []).map((row) => [row.slot, row.user_id]));
        const viewerIds = (record?.viewers ?? []).map((row) => row.user_id);
        form.setFieldsValue(
            record
                ? {
                    ...record,
                    reviewers,
                    viewers: viewerIds,
                    chart_user_ids: chartPeople(record).map((user) => user.id),
                    parent_id: record.parent_id ?? null,
                }
                : { type: "program", viewers: [], chart_user_ids: [], parent_id: parentId ?? undefined }
        );
    };

    const askDelete = (node) => {
        const hasChildren = (node.children ?? []).length > 0;
        Modal.confirm({
            title: `Delete ${node.name}?`,
            content: hasChildren
                ? "Its sub-units move up to the unit above. People and forms move with them."
                : "People and forms in this unit move up to the unit above.",
            okText: "Delete",
            okButtonProps: { danger: true },
            onOk: () => remove.mutateAsync(node.id),
        });
    };

    const close = () => {
        setOpen(false);
        setEditing(null);
        setPlaceLocked(false);
        setLockedParentId(null);
        form.resetFields();
    };

    const renderChart = () => isLoading ? (
        <div className="pms-org-chart">
            <div className="pms-org-chart-loading">
                <Spin />
            </div>
        </div>
    ) : tree.length === 0 ? (
        <div className="pms-org-chart">
            <Empty description="No units yet." />
        </div>
    ) : (
        <OrgViewport>
            <OrgTree
                nodes={tree}
                onEdit={(node) => openDrawer(node, null, true)}
                onAdd={(parentId) => openDrawer(null, parentId, true)}
                onDelete={askDelete}
            />
        </OrgViewport>
    );

    return (
        <>
            <PageHeader
                title="Hierarchy"
                subtitle="Set who reviews whom. A form walks the steps in Setup → Workflow. An empty Head is skipped. An empty Dean, VP, or extra step uses the parent unit."
                extra={
                    <>
                        {tree.length > 0 && (
                            <Tooltip title="Open the chart full width">
                                <Button icon={<ExpandOutlined />} onClick={() => setWide(true)} />
                            </Tooltip>
                        )}
                        <Button type="primary" icon={<PlusOutlined />} onClick={() => openDrawer(null)}>
                            New unit
                        </Button>
                    </>
                }
            />

            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Review order"
                description="Employee submits → Head → Dean → Director → VP → QA. Name the Head on this unit. Colleges fill Dean and leave Director empty. Offices fill Director and leave Dean empty. Empty Dean, Director, or VP uses the parent. A person's own form skips the step they themselves would fill."
            />

            {renderChart()}

            <Drawer
                open={wide}
                onClose={() => setWide(false)}
                placement="right"
                width="100%"
                destroyOnClose
                className="pms-org-wide"
                title="Hierarchy"
                extra={<Button onClick={() => setWide(false)}>Close</Button>}
            >
                {renderChart()}
            </Drawer>

            <Drawer
                title={editing ? `Edit ${editing.name}` : "New unit"}
                open={open}
                onClose={close}
                width={420}
                extra={
                    <Button type="primary" loading={save.isPending} onClick={() => form.submit()}>
                        Save
                    </Button>
                }
            >
                <Form
                    form={form}
                    layout="vertical"
                    requiredMark={false}
                    onFinish={(values) => save.mutate({
                        ...values,
                        id: editing?.id,
                        ...(placeLocked ? { parent_id: lockedParentId } : {}),
                    })}
                >
                    <section className="pms-org-drawer-block is-unit">
                        <h3>The unit</h3>
                        <p>Name and where it sits on the chart.</p>
                        <Form.Item name="name" label="Unit name" rules={[{ required: true, message: "Name the unit." }]}>
                            <Input placeholder="BS Information Technology" />
                        </Form.Item>
                        <Form.Item name="code" label="Short code">
                            <Input placeholder="BSIT" />
                        </Form.Item>
                        <Form.Item name="type" label="Type" rules={[{ required: true }]}>
                            <Select
                                options={Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label }))}
                            />
                        </Form.Item>
                        {placeLocked ? (
                            <p className="pms-org-place">
                                {lockedParentId
                                    ? `Stays under ${units.find((unit) => unit.id === lockedParentId)?.name ?? "the unit you clicked"}.`
                                    : "Stays at the top of the chart."}
                            </p>
                        ) : (
                            <Form.Item name="parent_id" label="Part of">
                                <Select
                                    allowClear
                                    placeholder="Select a parent unit"
                                    options={parentOptions.map((unit) => ({
                                        value: unit.id,
                                        label: `${"— ".repeat(unit.depth)}${unit.name}`,
                                    }))}
                                />
                            </Form.Item>
                        )}
                    </section>
                    <section className="pms-org-drawer-block is-review">
                        <h3>Who reviews</h3>
                        <p>The form walks these steps. An empty Head is skipped. An empty Dean, VP, or later step uses the parent.</p>
                        <Form.Item
                            name="head_user_id"
                            label="Head"
                            extra="First reviewer for people in this unit. Not taken from the parent."
                        >
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder="Select the head"
                                options={heads.map((p) => ({ value: p.id, label: p.name }))}
                            />
                        </Form.Item>
                        <Form.Item
                            name="dean_user_id"
                            label="Dean"
                            extra="Reviews after the head and before the director."
                        >
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder="Select the dean"
                                options={heads.map((p) => ({ value: p.id, label: p.name }))}
                            />
                        </Form.Item>
                        <Form.Item
                            name="vp_user_id"
                            label="Reports to VP"
                            extra="Reviews after the director."
                        >
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder="Select the VP"
                                options={vps.map((p) => ({ value: p.id, label: p.name }))}
                            />
                        </Form.Item>
                        {extraStages.map((stage) => (
                            <Form.Item
                                key={stage.slot}
                                name={["reviewers", stage.slot]}
                                label={String(stage.label || "Step").replace(/^With /i, "")}
                                extra="Leave empty to use the parent unit."
                            >
                                <Select
                                    allowClear
                                    showSearch
                                    optionFilterProp="label"
                                    placeholder="Select who reviews this step"
                                    options={heads.map((p) => ({ value: p.id, label: p.name }))}
                                />
                            </Form.Item>
                        ))}
                    </section>
                    <section className="pms-org-drawer-block is-chart">
                        <h3>On the chart only</h3>
                        <p>These people appear on the chart or can open forms. They do not review.</p>
                        <Form.Item
                            name="chart_user_ids"
                            label="Show on the chart"
                            extra="Up to 10 people."
                        >
                            <Select
                                mode="multiple"
                                maxCount={10}
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder="Select who appears on the chart"
                                options={viewers.map((p) => ({ value: p.id, label: p.name }))}
                            />
                        </Form.Item>
                        <Form.Item
                            name="viewers"
                            label="Can view forms"
                            extra="They can open this unit's forms and the departments under it. They cannot rate."
                        >
                            <Select
                                mode="multiple"
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder="Select who can view"
                                options={viewers.map((p) => ({ value: p.id, label: p.name }))}
                            />
                        </Form.Item>
                    </section>
                </Form>
            </Drawer>
        </>
    );
}
