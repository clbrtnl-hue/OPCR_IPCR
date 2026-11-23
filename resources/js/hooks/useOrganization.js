import { useQuery } from "@tanstack/react-query";
import api from "~/utils/api";

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
