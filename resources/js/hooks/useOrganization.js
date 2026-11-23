import { useQuery } from "@tanstack/react-query";
import api from "~/utils/api";

const FALLBACK_LOGO = "/images/occ-logo.webp";

/**
 * Identity and branding for whoever owns this deployment. Public, so the login
 * screen can render before anyone has signed in.
 */
export function useOrganization() {
    const { data } = useQuery({
        queryKey: ["organization"],
        queryFn: () => api.get("organization").then((r) => r.data),
        staleTime: Infinity,
        retry: false,
    });

    return data ?? {};
}

/** College logo URL, falling back to the shipped OCC mark. */
export function organizationLogo(organization) {
    return organization?.logo_url || FALLBACK_LOGO;
}
