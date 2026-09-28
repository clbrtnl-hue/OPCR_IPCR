import React from "react";

function Glyph({ children }) {
    return (
        <svg
            className="pms-nav-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

const ICONS = {
    dashboard: (
        <>
            <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
            <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
            <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
            <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
        </>
    ),
    forms: (
        <>
            <path d="M14 3.5H7.8A1.8 1.8 0 0 0 6 5.3V18.7A1.8 1.8 0 0 0 7.8 20.5h8.4A1.8 1.8 0 0 0 18 18.7V8.2L14 3.5z" />
            <path d="M14 3.5V8.2H18M9 12.5h6M9 16h4" />
        </>
    ),
    ipcr: (
        <>
            <rect x="6" y="3.5" width="12" height="17" rx="1.6" />
            <path d="M9 8.5h6M9 12.5h6M9 16.5h4" />
        </>
    ),
    team: (
        <>
            <path d="M16 20v-1.2A2.8 2.8 0 0 0 13.2 16H6.8A2.8 2.8 0 0 0 4 18.8V20" />
            <circle cx="10" cy="8" r="3" />
            <path d="M20 20v-1.2A2.8 2.8 0 0 0 17.5 16.2" />
            <path d="M16.2 5.1a3 3 0 0 1 0 5.8" />
        </>
    ),
    accounts: (
        <>
            <rect x="3.5" y="5" width="17" height="14" rx="2" />
            <circle cx="9" cy="12" r="2" />
            <path d="M13.5 10.5h4M13.5 13.5h3" />
        </>
    ),
    college: (
        <>
            <path d="M4 20V9.5L12 4l8 5.5V20" />
            <path d="M9.5 20v-5.5h5V20" />
        </>
    ),
    hierarchy: (
        <>
            <rect x="8" y="3.5" width="8" height="5" rx="1.2" />
            <rect x="3" y="15.5" width="7" height="5" rx="1.2" />
            <rect x="14" y="15.5" width="7" height="5" rx="1.2" />
            <path d="M12 8.5v3.5M6.5 15.5V12h11v3.5" />
        </>
    ),
    review: (
        <>
            <rect x="4" y="4" width="16" height="16" rx="2" />
            <path d="m8 12.2 2.6 2.6L16.2 9.2" />
        </>
    ),
    star: <path d="m12 3.6 2.2 4.5 4.9.7-3.5 3.4.8 4.9L12 14.8 7.6 17.1l.8-4.9L4.9 8.8l4.9-.7L12 3.6z" />,
    reports: (
        <>
            <path d="M12 4.2a7.8 7.8 0 1 0 7.8 7.8H12V4.2z" />
            <path d="M14.2 3.6A7.8 7.8 0 0 1 20.4 9.8h-6.2V3.6z" />
        </>
    ),
    calendar: (
        <>
            <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
            <path d="M8 3.5V7M16 3.5V7M3.5 10.5h17" />
        </>
    ),
    workflow: (
        <>
            <circle cx="6" cy="6" r="2.2" />
            <circle cx="6" cy="18" r="2.2" />
            <circle cx="18" cy="12" r="2.2" />
            <path d="M8.2 6H13a2 2 0 0 1 2 2v1.6M8.2 18H13a2 2 0 0 0 2-2v-1.6" />
        </>
    ),
    audit: (
        <>
            <path d="M8 4h7.2L19 7.8V19a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 7 19V5.5A1.5 1.5 0 0 1 8.5 4H8z" />
            <path d="M14.5 4v4H19M9.2 12.5h5.5M9.2 16h3.5" />
        </>
    ),
    bell: (
        <>
            <path d="M6.2 16.2V11a5.8 5.8 0 1 1 11.6 0v5.2" />
            <path d="M4.8 16.2h14.4" />
            <path d="M10 19.2a2 2 0 0 0 4 0" />
        </>
    ),
    user: (
        <>
            <circle cx="12" cy="8" r="3.1" />
            <path d="M5.5 19.4v-.8A4 4 0 0 1 9.5 14.6h5a4 4 0 0 1 4 4v.8" />
        </>
    ),
    logout: (
        <>
            <path d="M10 7V5.6A1.6 1.6 0 0 1 11.6 4h6.8A1.6 1.6 0 0 1 20 5.6v12.8a1.6 1.6 0 0 1-1.6 1.6h-6.8A1.6 1.6 0 0 1 10 18.4V17" />
            <path d="M4 12h10M7.2 9.2 4.4 12l2.8 2.8" />
        </>
    ),
    menu: <path d="M4 7h16M4 12h16M4 17h16" />,
    setup: (
        <>
            <circle cx="12" cy="12" r="3" />
            <path d="M12 3.6v2M12 18.4v2M3.6 12h2M18.4 12h2M6.1 6.1l1.4 1.4M16.5 16.5l1.4 1.4M17.9 6.1l-1.4 1.4M7.5 16.5 6.1 17.9" />
        </>
    ),
};

export default function NavIcon({ name }) {
    return <Glyph>{ICONS[name]}</Glyph>;
}
