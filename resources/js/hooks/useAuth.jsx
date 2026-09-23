import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import api, { TOKEN_KEY } from "~/utils/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const token = localStorage.getItem(TOKEN_KEY);

        if (!token) {
            setLoading(false);
            return;
        }

        api.get("me")
            .then(({ data }) => setUser(data))
            .catch(() => localStorage.removeItem(TOKEN_KEY))
            .finally(() => setLoading(false));
    }, []);

    const login = useCallback(async (email, password) => {
        const { data } = await api.post("login", { email, password });
        localStorage.setItem(TOKEN_KEY, data.access_token);
        setUser(data.user);
        return data.user;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post("logout");
        } catch {
        }

        localStorage.removeItem(TOKEN_KEY);
        setUser(null);
    }, []);

    const refresh = useCallback(async () => {
        const { data } = await api.get("me");
        setUser(data);
        return data;
    }, []);

    const can = useCallback(
        (...roles) => Boolean(user) && (user.role === "admin" || roles.includes(user.role)),
        [user]
    );

    const value = useMemo(
        () => ({ user, loading, login, logout, refresh, can }),
        [user, loading, login, logout, refresh, can]
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);

    if (!context) {
        throw new Error("useAuth must be used inside AuthProvider");
    }

    return context;
}
