import React, { forwardRef, useEffect, useImperativeHandle, useLayoutEffect, useMemo, useRef, useState } from "react";
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
import api, { errorText } from "~/utils/api";
import PageHeader from "~/components/PageHeader";
import UserAvatar from "~/components/UserAvatar";
import { usePerson } from "~/hooks/usePerson";

const TYPE_LABELS = { college: "College", office: "Office", program: "Program" };

function byName(a, b) {
    return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
}

function byOrg(a, b) {
    const left = Number(a.sort_order ?? 0);
    const right = Number(b.sort_order ?? 0);
    if (left !== right) return left - right;
    return byName(a, b);
}

function personOn(unit, slot) {
    if (!unit) return null;
    if (slot === "dean_user_id") return unit.dean?.name ? unit.dean : null;
    if (slot === "vp_user_id") return unit.vp?.name ? unit.vp : null;
    const row = (unit.reviewers ?? []).find((item) => item.slot === slot && item.user?.name);
    return row?.user ?? null;
}

function inheritedReviewer(units, parentId, slot) {
    const byId = new Map(units.map((unit) => [unit.id, unit]));
    const seen = new Set();
    let currentId = parentId ?? null;

    while (currentId && !seen.has(currentId)) {
        seen.add(currentId);
        const unit = byId.get(currentId);
        if (!unit) return null;
        const person = personOn(unit, slot);
        if (person) return { person, unit };
        currentId = unit.parent_id ?? null;
    }

    return null;
}

function applyPlace(units, unitId, drop) {
    const next = units.map((unit) => ({ ...unit }));
    const moving = next.find((unit) => unit.id === unitId);
    if (!moving) return units;

    const oldParent = moving.parent_id ?? null;
    const parentId = drop.parentId ?? null;
    moving.parent_id = parentId;

    const renumber = (groupParent, includeMoving) => {
        const siblings = next
            .filter((unit) => (unit.parent_id ?? null) === groupParent && unit.id !== unitId)
            .sort(byOrg);

        if (includeMoving) {
            const index = drop.beforeId == null
                ? siblings.length
                : siblings.findIndex((unit) => unit.id === drop.beforeId);
            siblings.splice(index < 0 ? siblings.length : index, 0, moving);
        }

        siblings.forEach((unit, index) => {
            unit.sort_order = index + 1;
        });
    };

    renumber(parentId, true);
    if (oldParent !== parentId) renumber(oldParent, false);
    return next;
}

function samePlace(units, unitId, drop) {
    const unit = units.find((item) => item.id === unitId);
    if (!unit || !drop) return true;

    const parentId = drop.parentId ?? null;
    if ((unit.parent_id ?? null) !== parentId) return false;

    const currentIds = units
        .filter((item) => (item.parent_id ?? null) === parentId)
        .sort(byOrg)
        .map((item) => item.id);
    const ids = currentIds.filter((id) => id !== unitId);
    const index = drop.beforeId == null ? ids.length : ids.indexOf(drop.beforeId);
    if (index < 0) return false;
    ids.splice(index, 0, unitId);

    return ids.length === currentIds.length && ids.every((id, position) => id === currentIds[position]);
}

function resolveDrop(clientX, clientY, dragId, units, tree) {
    const hit = document.elementsFromPoint(clientX, clientY)
        .map((element) => element.closest?.("[data-unit-id]"))
        .find((element) => element && !element.closest?.(".pms-org-drag-ghost"));

    if (!hit) return null;

    const targetId = Number(hit.dataset.unitId);
    if (!targetId || targetId === dragId) return null;

    const current = findUnit(tree, dragId);
    if (!current || descendantIds(current).includes(targetId)) return null;

    const target = units.find((unit) => unit.id === targetId);
    if (!target) return null;

    const box = hit.querySelector(".pms-org-banner") ?? hit;
    const rect = box.getBoundingClientRect();
    const depth = depthOf(tree, targetId);
    const horizontal = depth <= 2;
    const axis = horizontal
        ? (clientX - rect.left) / rect.width
        : (clientY - rect.top) / rect.height;
    const parentId = target.parent_id ?? null;

    if (axis >= 0 && axis < 0.34) {
        return { mode: "before", targetId, parentId, beforeId: targetId };
    }

    if (axis > 0.66 && axis <= 1) {
        const siblings = units
            .filter((unit) => (unit.parent_id ?? null) === parentId && unit.id !== dragId)
            .sort(byOrg);
        const index = siblings.findIndex((unit) => unit.id === targetId);
        const next = siblings[index + 1];
        return { mode: "after", targetId, parentId, beforeId: next?.id ?? null };
    }

    return { mode: "into", targetId, parentId: targetId, beforeId: null };
}

function dropCaption(drop, units) {
    if (!drop) return "Drop on a unit";
    const target = units.find((unit) => unit.id === drop.targetId);
    const name = target?.name ?? "this unit";
    if (drop.mode === "before") return `Before ${name}`;
    if (drop.mode === "after") return `After ${name}`;
    return `Under ${name}`;
}

function depthOf(nodes, id, depth = 0) {
    for (const node of nodes) {
        if (node.id === id) return depth;
        if (node.children) {
            const nested = depthOf(node.children, id, depth + 1);
            if (nested != null) return nested;
        }
    }
    return null;
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

function UnitNode({ node, depth, rank = "head", found = false, onEdit, onAdd, onDelete, dragId, drop, onUnitPointerDown, suppressOpen }) {
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
        <div
            data-unit-id={node.id}
            className={`pms-org-node${found ? " is-found" : ""}${namedUnit && !showCard ? " is-name" : ""}${flankRight.length || flankLeft.length ? " has-flank" : ""}${depth > 0 && flankRight.length ? " has-side" : ""}${dragId === node.id ? " is-dragging" : ""}${drop?.targetId === node.id ? ` is-drop-${drop.mode}` : ""}`}
        >
            {banner && (
                <div className="pms-org-banner-wrap">
                    <button
                        type="button"
                        className={`pms-org-banner is-${banner}`}
                        onPointerDown={(event) => onUnitPointerDown?.(event, node)}
                        onClick={() => {
                            if (suppressOpen?.current) {
                                suppressOpen.current = false;
                                return;
                            }
                            openUnit();
                        }}
                    >
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

const OrgViewport = forwardRef(function OrgViewport({ children, placing = false }, ref) {
    const viewport = useRef(null);
    const tree = useRef(null);
    const scaleRef = useRef(1);
    const fitted = useRef(false);
    const drag = useRef(null);
    const suppressClick = useRef(false);
    const [scale, setScale] = useState(1);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [grabbing, setGrabbing] = useState(false);
    const [revealing, setRevealing] = useState(false);

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

    useImperativeHandle(ref, () => ({
        reveal(unitId) {
            const view = viewport.current;
            const scaleEl = tree.current;
            const target = scaleEl?.querySelector(`[data-unit-id="${unitId}"]`);
            if (!view || !scaleEl || !target) return;

            const old = scaleRef.current || 1;
            const scaleRect = scaleEl.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const localX = (targetRect.left - scaleRect.left) / old;
            const localY = (targetRect.top - scaleRect.top) / old;
            const localW = targetRect.width / old;
            const localH = targetRect.height / old;
            const fitted = Math.min(
                (view.clientWidth * 0.42) / Math.max(localW, 1),
                (view.clientHeight * 0.5) / Math.max(localH, 1),
            );
            const next = Math.min(1.6, Math.max(0.9, fitted));
            const left = localX * next - (view.clientWidth - localW * next) / 2;
            const top = localY * next - (view.clientHeight - localH * next) / 2;

            scaleRef.current = next;
            setRevealing(true);
            setScale(next);
            window.setTimeout(() => setRevealing(false), 400);

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    view.scrollTo({
                        left: Math.max(0, left),
                        top: Math.max(0, top),
                        behavior: "smooth",
                    });
                });
            });
        },
    }));

    return (
        <div className="pms-org-frame">
            <p className="pms-org-zoom-hint">Drag a unit name to move it. Drop it on a unit to place it underneath, or on the edge to change the order. Mouse wheel zooms. Drag empty space to look around.</p>
            <div
                className={`pms-org-chart${grabbing ? " is-grabbing" : ""}${placing ? " is-placing" : ""}`}
                ref={viewport}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onClickCapture={onClickCapture}
            >
                <div className="pms-org-stage" style={{ width: size.width * scale, height: size.height * scale }}>
                    <div className={`pms-org-scale${revealing ? " is-revealing" : ""}`} ref={tree} style={{ transform: `scale(${scale})` }}>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
});

function shownDirector(node, depth) {
    const person = chartPerson(node, depth);
    const director = (node.reviewers ?? []).find((row) => row.slot === "director_review")?.user;
    return Boolean(person?.id && director?.id && person.id === director.id);
}

function OrgNode({ node, depth = 0, personAbove = false, foundId, onEdit, onAdd, onDelete, dragId, drop, onUnitPointerDown, suppressOpen }) {
    const children = node.children ?? [];
    const childClass = depth < 2 ? "pms-org-tree" : "pms-org-stack";
    const rank = depth === 0 ? "president" : depth === 1 && leadPerson(node, depth)?.name ? "vp" : shownDirector(node, depth) || !personAbove ? "head" : "child";
    const nextAbove = personAbove || (depth >= 2 && Boolean(chartPerson(node, depth)?.name));

    return (
        <li>
            <UnitNode
                node={node}
                depth={depth}
                rank={rank}
                found={foundId != null && Number(foundId) === Number(node.id)}
                onEdit={onEdit}
                onAdd={onAdd}
                onDelete={onDelete}
                dragId={dragId}
                drop={drop}
                onUnitPointerDown={onUnitPointerDown}
                suppressOpen={suppressOpen}
            />
            {children.length > 0 && (
                <ul className={childClass}>
                    {children.map((child) => (
                        <OrgNode
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            personAbove={nextAbove}
                            foundId={foundId}
                            onEdit={onEdit}
                            onAdd={onAdd}
                            onDelete={onDelete}
                            dragId={dragId}
                            drop={drop}
                            onUnitPointerDown={onUnitPointerDown}
                            suppressOpen={suppressOpen}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

function UnitPeople({ unit }) {
    const { openPerson } = usePerson();
    const { data: people = [], isLoading } = useQuery({
        queryKey: ["org-unit-members", unit?.id],
        enabled: Boolean(unit?.id),
        queryFn: () => api.get(`org-units/${unit.id}/members`).then((r) => r.data),
    });

    if (!unit?.id) return null;

    const under = Math.max(0, (unit.members_count ?? 0) - (unit.direct_members_count ?? 0));
    const summary = isLoading
        ? "Loading the people in this unit."
        : people.length === 0
            ? "No one is assigned to this unit yet."
            : `${people.length} ${people.length === 1 ? "person sits" : "people sit"} in this unit.`;

    return (
        <section className="pms-org-drawer-block is-people">
            <h3>People here</h3>
            <p>
                {summary}
                {!isLoading && under > 0 ? ` ${under} more sit in the units under it.` : ""}
            </p>
            {isLoading ? (
                <Spin size="small" />
            ) : people.length > 0 && (
                <ul className="pms-org-people">
                    {people.map((person) => (
                        <li key={person.id}>
                            <button type="button" onClick={() => openPerson(person.id)}>
                                <UserAvatar user={person} name={person.name} size={28} showTooltip={false} />
                                <span>
                                    <strong>{person.name}</strong>
                                    {person.position_title && <em>{person.position_title}</em>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function OrgTree({ nodes, foundId, onEdit, onAdd, onDelete, dragId, drop, onUnitPointerDown, suppressOpen }) {
    return (
        <ul className="pms-org-tree">
            {nodes.map((node) => (
                <OrgNode
                    key={node.id}
                    node={node}
                    depth={0}
                    foundId={foundId}
                    onEdit={onEdit}
                    onAdd={onAdd}
                    onDelete={onDelete}
                    dragId={dragId}
                    drop={drop}
                    onUnitPointerDown={onUnitPointerDown}
                    suppressOpen={suppressOpen}
                />
            ))}
        </ul>
    );
}

function ReviewerNote({ units, slot, watch, children }) {
    const parentId = Form.useWatch("parent_id");
    const named = Form.useWatch(watch ?? slot);
    const inherited = named ? null : inheritedReviewer(units, parentId, slot);

    return (
        <>
            {children}
            {inherited ? ` Uses ${inherited.person.name} from ${inherited.unit.name} while this stays empty.` : ""}
        </>
    );
}

function peopleOnUnit(unit) {
    const people = [];
    const push = (person) => {
        if (!person?.id || !person.name || people.some((row) => row.id === person.id)) return;
        people.push(person);
    };

    push(unit.head);
    push(unit.dean);
    push(unit.vp);
    for (const row of unit.reviewers ?? []) push(row.user);

    return people;
}

function searchOptions(units, roster) {
    const options = [];
    const named = new Set();

    for (const unit of units) {
        options.push({
            value: `unit:${unit.id}`,
            unitId: unit.id,
            label: unit.code ? `${unit.name} (${unit.code})` : unit.name,
        });

        for (const person of peopleOnUnit(unit)) {
            named.add(`${unit.id}:${person.id}`);
            options.push({
                value: `person:${unit.id}:${person.id}`,
                unitId: unit.id,
                label: `${person.name} · ${unit.name}`,
            });
        }
    }

    for (const person of roster) {
        const key = `${person.org_unit_id}:${person.id}`;
        if (named.has(key) || !units.some((unit) => unit.id === person.org_unit_id)) continue;
        const unit = units.find((item) => item.id === person.org_unit_id);
        options.push({
            value: `member:${key}`,
            unitId: person.org_unit_id,
            label: `${person.name} · ${unit.name}`,
        });
    }

    return options.sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: "base" }));
}

export default function OrgUnitsPage() {
    const queryClient = useQueryClient();
    const [form] = Form.useForm();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [wide, setWide] = useState(false);
    const [foundId, setFoundId] = useState(null);
    const [picked, setPicked] = useState(null);
    const mainChart = useRef(null);
    const wideChart = useRef(null);
    const dragRef = useRef(null);
    const suppressOpen = useRef(false);
    const stopDrag = useRef(null);
    const [drag, setDrag] = useState(null);

    useEffect(() => () => stopDrag.current?.(), []);

    const { data: units = [], isLoading } = useQuery({
        queryKey: ["org-units"],
        queryFn: () => api.get("org-units").then((r) => r.data),
    });

    const { data: people = [] } = useQuery({
        queryKey: ["user-options"],
        queryFn: () => api.get("users/options").then((r) => r.data),
    });

    const { data: roster = [] } = useQuery({
        queryKey: ["org-unit-roster"],
        queryFn: () => api.get("org-units/roster").then((r) => r.data),
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
    const finderOptions = useMemo(() => searchOptions(units, roster), [units, roster]);

    const reveal = (unitId) => {
        setFoundId(Number(unitId));
        const chart = (wide ? wideChart : mainChart).current;
        requestAnimationFrame(() => chart?.reveal(Number(unitId)));
    };

    const finder = tree.length > 0 && (
        <Select
            showSearch
            allowClear
            value={picked}
            placeholder="Find a unit or person"
            style={{ width: 280 }}
            optionFilterProp="label"
            options={finderOptions}
            onChange={(value) => {
                setPicked(value ?? null);
                const hit = finderOptions.find((option) => option.value === value);
                if (hit) reveal(hit.unitId);
                if (!value) setFoundId(null);
            }}
        />
    );

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

    const place = useMutation({
        mutationFn: ({ id, parentId, beforeId }) => api.post(`org-units/${id}/place`, {
            parent_id: parentId,
            before_id: beforeId,
        }),
        onMutate: async ({ id, parentId, beforeId }) => {
            await queryClient.cancelQueries({ queryKey: ["org-units"] });
            const previous = queryClient.getQueryData(["org-units"]);
            queryClient.setQueryData(["org-units"], (current = []) => applyPlace(current, id, { parentId, beforeId }));
            return { previous };
        },
        onError: (error, _values, context) => {
            if (context?.previous) queryClient.setQueryData(["org-units"], context.previous);
            message.error(errorText(error));
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: ["org-units"] }),
    });

    const onUnitPointerDown = (event, node) => {
        if (event.button !== 0) return;
        const startX = event.clientX;
        const startY = event.clientY;
        let active = false;

        const move = (ev) => {
            if (!active && Math.hypot(ev.clientX - startX, ev.clientY - startY) < 8) return;
            active = true;
            ev.preventDefault();
            const next = {
                id: node.id,
                name: node.name,
                x: ev.clientX,
                y: ev.clientY,
                drop: resolveDrop(ev.clientX, ev.clientY, node.id, units, tree),
            };
            dragRef.current = next;
            setDrag(next);
        };

        const stop = () => {
            window.removeEventListener("pointermove", move);
            window.removeEventListener("pointerup", up);
            stopDrag.current = null;
        };

        const up = () => {
            stop();
            const current = dragRef.current;
            dragRef.current = null;
            setDrag(null);
            if (!active) return;
            suppressOpen.current = true;
            if (!current?.drop || samePlace(units, node.id, current.drop)) return;
            place.mutate({
                id: node.id,
                parentId: current.drop.parentId,
                beforeId: current.drop.beforeId,
            });
        };

        stopDrag.current = stop;
        window.addEventListener("pointermove", move);
        window.addEventListener("pointerup", up);
    };

    const openDrawer = (record, parentId = null) => {
        setEditing(record ?? null);
        if (record?.id) setFoundId(record.id);
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
        form.resetFields();
    };

    const renderChart = (chartRef) => isLoading ? (
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
        <OrgViewport ref={chartRef} placing={Boolean(drag)}>
            <OrgTree
                nodes={tree}
                foundId={foundId}
                onEdit={(node) => openDrawer(node)}
                onAdd={(parentId) => openDrawer(null, parentId)}
                onDelete={askDelete}
                dragId={drag?.id ?? null}
                drop={drag?.drop ?? null}
                onUnitPointerDown={onUnitPointerDown}
                suppressOpen={suppressOpen}
            />
        </OrgViewport>
    );

    return (
        <div className="pms-org-page">
            <PageHeader
                title="Hierarchy"
                subtitle="Search zooms the chart to that unit. Drag a unit name to move it."
                extra={
                    <>
                        {finder}
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

            {renderChart(mainChart)}

            <Drawer
                open={wide}
                onClose={() => setWide(false)}
                placement="right"
                width="100%"
                destroyOnClose
                className="pms-org-wide"
                title="Hierarchy"
                extra={
                    <>
                        {finder}
                        <Button onClick={() => setWide(false)}>Close</Button>
                    </>
                }
            >
                {renderChart(wideChart)}
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
                    </section>
                    <UnitPeople unit={editing} />
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
                            extra={(
                                <ReviewerNote units={units} slot="dean_user_id">
                                    Reviews after the head and before the director.
                                </ReviewerNote>
                            )}
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
                            extra={(
                                <ReviewerNote units={units} slot="vp_user_id">
                                    Reviews after the director.
                                </ReviewerNote>
                            )}
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
                                extra={(
                                    <ReviewerNote units={units} slot={stage.slot} watch={["reviewers", stage.slot]}>
                                        Leave empty to use the parent unit.
                                    </ReviewerNote>
                                )}
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
            {drag && createPortal(
                <div className="pms-org-drag-ghost" style={{ left: drag.x + 14, top: drag.y + 14 }}>
                    <strong>{drag.name}</strong>
                    <span>{dropCaption(drag.drop, units)}</span>
                </div>,
                document.body,
            )}
        </div>
    );
}
