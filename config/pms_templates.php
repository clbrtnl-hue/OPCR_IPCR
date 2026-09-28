<?php

return [

    [
        'key'         => 'support-standard',
        'name'        => 'Support functions',
        'for'          => 'ipcr',
        'except_roles' => ['program_head', 'vp'],
        'section'      => 'support',
        'description'  => 'The support commitments every employee reports — submissions and attendance.',
        'outputs'     => [
            [
                'title'      => 'Submission of IPCR to the Department Head',
                'indicators' => [
                    'Prompt submission with 95% Accuracy',
                ],
            ],
            [
                'title'      => 'Submission of DTR',
                'indicators' => [
                    'Prompt submission with 95% Accuracy',
                ],
            ],
            [
                'title'      => 'Submission of SALN',
                'indicators' => [
                    'NOT APPLICABLE',
                ],
            ],
            [
                'title'      => 'Attendance to LGU activities',
                'indicators' => [
                    'NOT APPLICABLE',
                ],
            ],
            [
                'title'      => 'Attendance to meetings',
                'indicators' => [
                    'Attend to at least 80% of all meetings',
                ],
            ],
            [
                'title'      => 'Attendance to school activities',
                'indicators' => [
                    'Attend to at least 80% of all activities',
                ],
            ],
        ],
    ],

    [
        'key'         => 'support-head',
        'name'        => 'Support functions',
        'for'         => 'ipcr',
        'roles'       => ['program_head'],
        'section'     => 'support',
        'description' => 'The support MFOs for a head. Success indicators are left blank so they can be written on the form.',
        'outputs'     => [
            [
                'title'      => 'Submission of IPCR to the Head of Office',
                'indicators' => [],
            ],
            [
                'title'      => 'Submission of DTR',
                'indicators' => [],
            ],
            [
                'title'      => 'Submission of SALN',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to Monday convocation',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to local committee meetings',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to departmental/institutional activities',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to relevant trainings, seminars, symposia, and the like',
                'indicators' => [],
            ],
            [
                'title'      => 'Affiliation/membership to relevant organizations',
                'indicators' => [],
            ],
        ],
    ],

    [
        'key'         => 'support-vp',
        'name'        => 'Support functions',
        'for'         => 'ipcr',
        'roles'       => ['vp'],
        'section'     => 'support',
        'description' => 'The support MFOs for a VP. Success indicators are left blank so they can be written on the form.',
        'outputs'     => [
            [
                'title'      => 'Submission of IPCR to the Head of Office',
                'indicators' => [],
            ],
            [
                'title'      => 'Submission of DTR',
                'indicators' => [],
            ],
            [
                'title'      => 'Submission of SALN',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to LGU Monday convocation',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to committee meetings',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to relevant trainings',
                'indicators' => [],
            ],
        ],
    ],

    [
        'key'         => 'opcr-support',
        'name'        => 'Support functions',
        'for'         => 'opcr',
        'section'     => 'support',
        'description' => 'The college support MFOs. Success indicators are left blank so they can be written on the form. Other support functions can still be added.',
        'outputs'     => [
            [
                'title'      => 'Actions on other matters referred by the LCE or his authorized representative.',
                'indicators' => [],
            ],
            [
                'title'      => 'Compliance to regulatory Orders issued by competent authority.',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to the activities of other committee membership such as regular meetings.',
                'indicators' => [],
            ],
            [
                'title'      => 'Attendance to MANCOM meetings.',
                'indicators' => [],
            ],
            [
                'title'      => 'Budget Utilization Rate (BUR).',
                'indicators' => [],
            ],
        ],
    ],

];
