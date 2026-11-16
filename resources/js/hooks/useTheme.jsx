import React, { createContext, useContext, useEffect, useMemo, useState } from "react";
import { theme as antTheme } from "antd";
import { VIZ } from "~/utils/constants";

const STORAGE_KEY = "pms-theme";

const fontFamily =
    "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";

export const lightTheme = {
    token: {
        colorPrimary: "#1e3a72",
        colorInfo: "#1e3a72",
        colorInfoBg: "#eef3fc",
        colorInfoBorder: "#c4d3f0",
        colorBgLayout: "#eef1f6",
        borderRadius: 10,
        fontFamily,
    },
    components: {
        Layout: {
            siderBg: "#ffffff",
            lightSiderBg: "#ffffff",
            triggerBg: "#f7f8fc",
            triggerColor: "#1e3a72",
            lightTriggerBg: "#f7f8fc",
            lightTriggerColor: "#1e3a72",
            headerBg: "transparent",
            bodyBg: "#eef1f6",
        },
        Menu: {
            itemBg: "#ffffff",
            itemBorderRadius: 12,
            itemHeight: 42,
            itemMarginInline: 12,
            itemMarginBlock: 4,
            itemColor: "#3d4a63",
            itemHoverColor: "#1e3a72",
            itemHoverBg: "#f3f6fb",
            itemSelectedColor: "#1e3a72",
            itemSelectedBg: "#e7eefb",
            itemActiveBg: "#e7eefb",
            subMenuItemBg: "#ffffff",
            popupBg: "#ffffff",
            darkItemBg: "#ffffff",
            darkSubMenuItemBg: "#ffffff",
            darkPopupBg: "#ffffff",
            darkItemColor: "#3d4a63",
            darkItemHoverColor: "#1e3a72",
            darkItemHoverBg: "#f3f6fb",
            darkItemSelectedColor: "#1e3a72",
            darkItemSelectedBg: "#e7eefb",
            iconSize: 16,
        },
        Card: {
            borderRadiusLG: 18,
        },
    },
};

const heroInk = "#f5f7fb";
const heroMuted = "rgba(245, 247, 251, 0.66)";
const heroLine = "rgba(255, 255, 255, 0.08)";
const heroDeep = "#070b10";
const heroMid = "#161e28";
const heroLift = "#1c2633";

export const darkTheme = {
    algorithm: antTheme.darkAlgorithm,
    token: {
        colorPrimary: "#5b86e0",
        colorInfo: "#8eb0ff",
        colorLink: "#c9d8f5",
        colorInfoBg: "rgba(140, 176, 255, 0.16)",
        colorInfoBorder: "rgba(255, 255, 255, 0.22)",
        colorSuccessBg: "rgba(27, 175, 122, 0.16)",
        colorWarningBg: "rgba(250, 178, 25, 0.16)",
        colorErrorBg: "rgba(208, 59, 59, 0.16)",
        colorBgBase: heroDeep,
        colorBgLayout: heroDeep,
        colorBgContainer: heroMid,
        colorBgElevated: heroLift,
        colorBorder: "rgba(255, 255, 255, 0.18)",
        colorBorderSecondary: heroLine,
        colorSplit: "rgba(255, 255, 255, 0.12)",
        colorText: heroInk,
        colorTextSecondary: heroMuted,
        colorTextTertiary: "rgba(255, 255, 255, 0.55)",
        colorTextQuaternary: "rgba(255, 255, 255, 0.4)",
        colorTextDisabled: "rgba(255, 255, 255, 0.38)",
        colorFillAlter: "rgba(255, 255, 255, 0.06)",
        colorFillSecondary: "rgba(255, 255, 255, 0.1)",
        colorFillTertiary: "rgba(255, 255, 255, 0.06)",
        colorBgSpotlight: heroLift,
        borderRadius: 16,
        fontFamily,
    },
    components: {
        Layout: {
            siderBg: heroDeep,
            triggerBg: heroDeep,
            triggerColor: heroInk,
            headerBg: "transparent",
            bodyBg: "transparent",
        },
        Menu: {
            itemBorderRadius: 12,
            itemHeight: 42,
            itemMarginInline: 12,
            itemMarginBlock: 4,
            itemBg: "transparent",
            itemColor: heroMuted,
            itemHoverColor: heroInk,
            itemHoverBg: "rgba(255, 255, 255, 0.08)",
            itemSelectedColor: heroInk,
            itemSelectedBg: "rgba(255, 255, 255, 0.16)",
            itemActiveBg: "rgba(255, 255, 255, 0.16)",
            subMenuItemBg: "transparent",
            popupBg: heroLift,
            darkItemBg: "transparent",
            darkSubMenuItemBg: "transparent",
            darkPopupBg: heroLift,
            darkItemColor: heroMuted,
            darkItemHoverColor: heroInk,
            darkItemHoverBg: "rgba(255, 255, 255, 0.08)",
            darkItemSelectedColor: heroInk,
            darkItemSelectedBg: "rgba(255, 255, 255, 0.16)",
            iconSize: 16,
        },
        Card: {
            borderRadiusLG: 22,
            colorBgContainer: heroMid,
            colorBorderSecondary: heroLine,
            colorTextHeading: heroInk,
        },
        Button: {
            primaryColor: "#ffffff",
            defaultBg: "transparent",
            defaultColor: heroInk,
            defaultBorderColor: "rgba(255, 255, 255, 0.2)",
            defaultHoverBg: "rgba(255, 255, 255, 0.08)",
            defaultHoverColor: "#ffffff",
            defaultHoverBorderColor: "rgba(255, 255, 255, 0.38)",
            textHoverBg: "rgba(255, 255, 255, 0.08)",
        },
        Input: {
            colorBgContainer: "rgba(255, 255, 255, 0.08)",
            colorBorder: "rgba(255, 255, 255, 0.22)",
            activeBorderColor: "#8cb0ff",
            hoverBorderColor: "rgba(255, 255, 255, 0.45)",
            colorTextPlaceholder: "rgba(255, 255, 255, 0.5)",
        },
        Select: {
            selectorBg: "rgba(255, 255, 255, 0.08)",
            colorBorder: "rgba(255, 255, 255, 0.22)",
            optionSelectedBg: "rgba(255, 255, 255, 0.16)",
            optionActiveBg: "rgba(255, 255, 255, 0.1)",
        },
        Table: {
            headerBg: "#1a2330",
            headerColor: heroMuted,
            colorBgContainer: heroMid,
            rowHoverBg: "rgba(255, 255, 255, 0.06)",
            borderColor: "rgba(255, 255, 255, 0.12)",
        },
        Modal: {
            contentBg: heroMid,
            headerBg: heroMid,
            titleColor: heroInk,
        },
        Drawer: {
            colorBgElevated: heroDeep,
        },
        Segmented: {
            itemSelectedBg: "#ffffff",
            itemSelectedColor: heroDeep,
            itemColor: heroMuted,
            itemHoverColor: heroInk,
            trackBg: "rgba(255, 255, 255, 0.1)",
        },
        Dropdown: {
            colorBgElevated: heroLift,
        },
    },
};

export function vizFor(mode) {
    if (mode !== "dark") {
        return VIZ;
    }

    return {
        ...VIZ,
        ordinal: ["#dbe6ff", "#8cb0ff", "#6d93d6", "#3c62b0", "#c5d6ff"],
        grid: "rgba(255, 255, 255, 0.14)",
        axis: "rgba(255, 255, 255, 0.28)",
        muted: "rgba(255, 255, 255, 0.72)",
        track: "rgba(255, 255, 255, 0.12)",
        ink: "#ffffff",
        inkSoft: "rgba(255, 255, 255, 0.8)",
    };
}

function storedMode() {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);

        if (saved === "light" || saved === "dark") {
            return saved;
        }
    } catch {
        // Private mode can block storage. Fall through to the trial default.
    }

    return "dark";
}

const ThemeContext = createContext(null);

export function ThemeProvider({ children }) {
    const [mode, setMode] = useState(storedMode);
    const viz = useMemo(() => vizFor(mode), [mode]);
    const theme = mode === "dark" ? darkTheme : lightTheme;

    useEffect(() => {
        document.documentElement.dataset.theme = mode;

        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch {
            // The choice still applies for this visit.
        }

        const meta = document.querySelector('meta[name="theme-color"]');

        if (meta) {
            meta.setAttribute("content", mode === "dark" ? "#070b10" : "#eef1f6");
        }
    }, [mode]);

    const value = useMemo(
        () => ({
            mode,
            theme,
            viz,
            toggle: () => setMode((current) => (current === "dark" ? "light" : "dark")),
        }),
        [mode, theme, viz]
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
    const value = useContext(ThemeContext);

    if (!value) {
        throw new Error("useTheme must be used within ThemeProvider");
    }

    return value;
}
