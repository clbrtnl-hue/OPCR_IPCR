import React, { useEffect, useState } from "react";
import { Pagination } from "antd";

export function tablePager(pageSize, extra = {}) {
    return {
        pageSize,
        hideOnSinglePage: true,
        showSizeChanger: false,
        ...extra,
    };
}

export function usePaged(items, pageSize) {
    const [page, setPage] = useState(1);
    const list = items ?? [];
    const total = list.length;
    const pages = Math.max(1, Math.ceil(total / pageSize) || 1);
    const current = Math.min(page, pages);
    const mark = list.map((item) => item?.id ?? "").join(",");

    useEffect(() => {
        setPage(1);
    }, [mark, pageSize]);

    const start = (current - 1) * pageSize;

    return {
        page: current,
        setPage,
        pageSize,
        total,
        rows: list.slice(start, start + pageSize),
    };
}

export default function ListPager({ page, pageSize, total, onChange }) {
    if (!total) {
        return null;
    }

    return (
        <Pagination
            className="pms-list-pager"
            current={page}
            pageSize={pageSize}
            total={total}
            onChange={onChange}
            showSizeChanger={false}
            hideOnSinglePage
        />
    );
}
