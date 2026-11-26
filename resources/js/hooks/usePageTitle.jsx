import React, { createContext, useContext, useLayoutEffect, useMemo, useState } from "react";

const PageTitleContext = createContext({
    title: "",
    setTitle: () => {},
});

export function PageTitleProvider({ children }) {
    const [title, setTitle] = useState("");
    const value = useMemo(() => ({ title, setTitle }), [title]);

    return <PageTitleContext.Provider value={value}>{children}</PageTitleContext.Provider>;
}

export function usePageTitle() {
    return useContext(PageTitleContext);
}

export function useSetPageTitle(title) {
    const { setTitle } = usePageTitle();

    useLayoutEffect(() => {
        setTitle(title || "");

        return () => setTitle("");
    }, [title, setTitle]);
}
