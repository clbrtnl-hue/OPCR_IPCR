import React, { createContext, useCallback, useContext, useMemo, useState } from "react";
import PersonModal from "~/components/PersonModal";

const PersonContext = createContext({ openPerson: () => {} });

/**
 * One place people open from. A mention can appear in a commitment, an
 * accomplishment, a remark or a comment — they all reach the same modal rather
 * than each view wiring up its own.
 */
export function PersonProvider({ children }) {
    const [personId, setPersonId] = useState(null);

    const openPerson = useCallback((id) => setPersonId(Number(id) || null), []);
    const value = useMemo(() => ({ openPerson }), [openPerson]);

    return (
        <PersonContext.Provider value={value}>
            {children}
            <PersonModal
                personId={personId}
                open={Boolean(personId)}
                onClose={() => setPersonId(null)}
            />
        </PersonContext.Provider>
    );
}

export function usePerson() {
    return useContext(PersonContext);
}
