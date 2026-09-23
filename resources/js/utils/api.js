import axios from "axios";
import { message } from "antd";

export const TOKEN_KEY = "occ_pms_token";

const api = axios.create({
    baseURL: "/api/",
    headers: { Accept: "application/json" },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem(TOKEN_KEY);

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

export function errorText(error) {
    const status = error?.response?.status;
    const data = error?.response?.data;

    if (status === 422 && data?.errors) {
        return Object.values(data.errors).flat().join(" ");
    }

    if (error?.response) {
        return data?.message || "Something went wrong. Please try again.";
    }

    return "The server could not be reached. Check your connection and try again.";
}

const isSignIn = (error) => (error?.config?.url ?? "").replace(/^\/+/, "") === "login";

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;

        if (isSignIn(error)) {
            return Promise.reject(error);
        }

        if (status === 401) {
            localStorage.removeItem(TOKEN_KEY);

            if (!window.location.pathname.startsWith("/login")) {
                window.location.href = "/login?reason=session-expired";
            }

            return Promise.reject(error);
        }

        message.error(errorText(error));

        return Promise.reject(error);
    }
);

export default api;
