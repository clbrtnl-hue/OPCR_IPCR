const samePerson = (left, right) => Number(left) === Number(right);

/** QA's own IPCR waits on the president's Rating tab. */
export function qaIpcrWithPresident(form) {
    if (!form || form.type !== "ipcr" || form.status !== "qa_rating") {
        return false;
    }

    if (form.status_label === "With the President") {
        return true;
    }

    return form.owner?.role === "qa";
}

/** This form is sitting with the signed-in person for review, approval, or rating. */
export function formIsWithReviewer(user, form) {
    if (!user || !form) {
        return false;
    }

    if (
        form.status === "commitment_review" &&
        samePerson(form.commitment_approver?.id ?? form.commitment_approver_id, user.id)
    ) {
        return true;
    }

    if (qaIpcrWithPresident(form)) {
        return user.role === "president";
    }

    const approves = (user.workflow?.opcr?.approver_roles ?? ["qa"]).includes(user.role);
    const rates = (user.workflow?.rater_roles ?? ["qa"]).includes(user.role);

    if ((approves && form.status === "qa_approval") || (rates && form.status === "qa_rating")) {
        return true;
    }

    if (samePerson(form.user_id, user.id)) {
        return false;
    }

    const step = (form.review_steps ?? []).find((item) => item.status === form.status);

    if (step && samePerson(step.reviewer_id ?? step.reviewer?.id, user.id)) {
        return true;
    }

    return (
        (form.status === "head_review" && samePerson(form.head_reviewer_id, user.id)) ||
        (form.status === "dean_review" && samePerson(form.dean_reviewer_id, user.id)) ||
        (form.status === "vp_review" && samePerson(form.vp_reviewer_id, user.id))
    );
}
