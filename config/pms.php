<?php

/**
 * The default rules an organization starts with. Everything here can be
 * overridden per organization from Setup -> Workflow; these values are what a
 * fresh organization gets, and what the system falls back to if a setting has
 * never been touched.
 */
return [

    /*
     | Who reviews an IPCR, in order. `source` says where the reviewer comes
     | from: a slot on the ratee's org unit, or a role held anywhere in the
     | organization. A stage nobody fills is skipped rather than stranding the
     | form — which is why a VP, who has no head or VP above them, goes straight
     | to QA.
     */
    'review_stages' => [
        [
            'status'    => 'head_review',
            'label'     => 'With Head',
            'source'    => 'unit_slot',
            'slot'      => 'head_user_id',
            'skippable' => true,
        ],
        [
            'status'    => 'vp_review',
            'label'     => 'With VP',
            'source'    => 'unit_slot',
            'slot'      => 'vp_user_id',
            'skippable' => true,
        ],
        [
            'status'    => 'qa_rating',
            'label'     => 'With QA',
            'source'    => 'role',
            'role'      => 'qa',
            'skippable' => false,
        ],
    ],

    /*
     | Who may hand work to somebody else. Terminal roles are the bottom of the
     | chain: their commitments are theirs to deliver.
     */
    'delegation' => [
        'assign_outputs'    => ['president'],
        'assign_indicators' => ['president', 'vp', 'program_head'],
        'terminal_roles'    => ['employee'],
        'assignable_excludes_roles' => ['admin'],
    ],

    /*
     | The office document's lifecycle and scope.
     */
    'opcr' => [
        'creator_roles'   => ['president'],
        'approver_roles'  => ['qa'],
        'publisher_roles' => ['president'],
        'rating_trigger_roles' => ['president'],
        'one_per' => 'organization',   // or 'org_unit'
    ],

    /*
     | The rating instrument. Dimensions are averaged into A; bands turn that
     | average into the adjectival rating printed on the form.
     */
    'rating' => [
        'dimensions' => ['q', 'e', 't'],
        'min'        => 1,
        'max'        => 5,
        'bands'      => [
            ['min' => 4.5, 'label' => 'Outstanding',       'value' => 5],
            ['min' => 3.5, 'label' => 'Very Satisfactory', 'value' => 4],
            ['min' => 2.5, 'label' => 'Satisfactory',      'value' => 3],
            ['min' => 1.5, 'label' => 'Unsatisfactory',    'value' => 2],
            ['min' => 0.0, 'label' => 'Poor',              'value' => 1],
        ],
    ],
];
