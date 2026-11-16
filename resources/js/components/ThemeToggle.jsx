import React from "react";
import { Button, Tooltip } from "antd";
import { MoonOutlined, SunOutlined } from "@ant-design/icons";
import { useTheme } from "~/hooks/useTheme";

export default function ThemeToggle() {
    const { mode, toggle } = useTheme();
    const dark = mode === "dark";
    const label = dark ? "Use light mode" : "Use dark mode";

    return (
        <Tooltip title={label}>
            <Button
                type="text"
                className="pms-theme-toggle"
                aria-label={label}
                icon={dark ? <SunOutlined /> : <MoonOutlined />}
                onClick={toggle}
            />
        </Tooltip>
    );
}
