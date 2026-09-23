import React from "react";
import { Space } from "antd";

export default function PageHeader({ title, subtitle, extra }) {
    return (
        <div
            style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "flex-start",
                gap: 16,
                flexWrap: "wrap",
            }}
        >
            <div>
                <h1 className="pms-page-title">{title}</h1>
                {subtitle && <p className="pms-page-subtitle">{subtitle}</p>}
            </div>
            {extra && <Space wrap>{extra}</Space>}
        </div>
    );
}
