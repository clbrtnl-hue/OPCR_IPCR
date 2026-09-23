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
        queries: { refetchOnWindowFocus: false, retry: 1 },
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
        borderRadius: 6,
        fontFamily:
            "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    },
};

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
