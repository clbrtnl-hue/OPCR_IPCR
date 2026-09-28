import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { App as AntApp, ConfigProvider } from "antd";
import { AuthProvider } from "~/hooks/useAuth";
import AppRoutes from "~/routes";
import "antd/dist/reset.css";
import "../css/app.css";

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: true,
            // A 429 is the API limit, not a blip. Retrying it fills the next
            // minute and keeps the "Too Many Attempts" toast on screen.
            retry: (failureCount, error) =>
                error?.response?.status !== 429 && failureCount < 1,
            // Open screens still refresh, but slowly. Polling every query
            // every 4s crosses the 120-per-minute API limit.
            refetchInterval: 30_000,
            refetchIntervalInBackground: false,
        },
    },
});

const theme = {
    token: {
        colorPrimary: "#1e3a72",
        colorInfo: "#1e3a72",
        // Ant derives info surfaces from the navy, which lands on muddy grey;
        // these keep banners a clean light blue instead.
        colorInfoBg: "#eef3fc",
        colorInfoBorder: "#c4d3f0",
        colorBgLayout: "#eef1f6",
        borderRadius: 10,
        fontFamily:
            "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
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
            // Short menus leave the rail empty. These stop Ant's navy dark
            // theme (#001529) from showing through that gap.
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

if ("serviceWorker" in navigator && import.meta.env.PROD) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("/sw.js");
    });
}

createRoot(document.getElementById("root")).render(
    <React.StrictMode>
        <ConfigProvider theme={theme}>
            <AntApp>
                <QueryClientProvider client={queryClient}>
                    <BrowserRouter>
                        <AuthProvider>
                            <AppRoutes />
                        </AuthProvider>
                    </BrowserRouter>
                </QueryClientProvider>
            </AntApp>
        </ConfigProvider>
    </React.StrictMode>
);
