const samePerson = (left, right) => Number(left) === Number(right);

/** This form is sitting with the signed-in person for review, approval, or rating. */
export function formIsWithReviewer(user, form) {
    if (!user || !form) {
        return false;
    }

    if (user.role === "president" && form.status === "appeal") {
        return true;
    }

    if (
        form.status === "commitment_review" &&
        samePerson(form.commitment_approver?.id ?? form.commitment_approver_id, user.id)
    ) {
        return true;
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
