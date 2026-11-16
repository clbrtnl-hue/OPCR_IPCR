import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { App as AntApp, ConfigProvider } from "antd";
import { AuthProvider } from "~/hooks/useAuth";
import { ThemeProvider, useTheme } from "~/hooks/useTheme";
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

function Providers() {
    const { theme } = useTheme();

    return (
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
    );
}

if ("serviceWorker" in navigator && import.meta.env.PROD) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("/sw.js");
    });
}

createRoot(document.getElementById("root")).render(
    <React.StrictMode>
        <ThemeProvider>
            <Providers />
        </ThemeProvider>
    </React.StrictMode>
);
