import React from "react";
import { Space } from "antd";
import { useLocation, useNavigate } from "react-router-dom";
import NavIcon from "~/components/NavIcon";

export default function PageHeader({ title, subtitle, extra }) {
    const navigate = useNavigate();
    const location = useLocation();
    const showBack = location.pathname !== "/";

    const goBack = () => {
        if (location.key !== "default") {
            navigate(-1);
            return;
        }

        navigate("/");
    };

    return (
        <div className="pms-page-head">
            <div>
                <div className="pms-page-title-row">
                    {showBack && (
                        <button type="button" className="pms-back" aria-label="Back" onClick={goBack}>
                            <NavIcon name="back" />
                        </button>
                    )}
                    <h1 className="pms-page-title">{title}</h1>
                </div>
                {subtitle && <p className="pms-page-subtitle">{subtitle}</p>}
            </div>
            {extra && <Space wrap>{extra}</Space>}
        </div>
    );
}
