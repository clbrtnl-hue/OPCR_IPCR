<?php

return [

    [
        'key'         => 'support-standard',
        'name'        => 'Standard support functions',
        'section'     => 'support',
        'description' => 'The reporting every member of staff owes the college each period — DTR, IPCR, SALN and the rest.',
        'outputs'     => [
            [
                'title'      => 'Administrative Requirements',
                'indicators' => [
                    'Submit the Daily Time Record within five (5) working days after the end of every month, 100% complete and signed.',
                    'Submit the accomplished IPCR within the deadline set for the rating period.',
                    'File the Statement of Assets, Liabilities and Net Worth on or before April 30.',
                    'Submit leave applications at least three (3) working days before the intended date, except in emergencies.',
                ],
            ],
            [
                'title'      => 'Attendance and Participation',
                'indicators' => [
                    'Attend 100% of the general assemblies, convocations and college-wide activities called for the period, absences covered by an approved leave.',
                    'Attend at least 90% of scheduled department or office meetings.',
                ],
            ],
        ],
    ],

    [
        'key'         => 'support-faculty',
        'name'        => 'Faculty support functions',
        'section'     => 'support',
        'description' => 'The classroom housekeeping expected of teaching staff on top of the standard reporting.',
        'outputs'     => [
            [
                'title'      => 'Classroom Documentation',
                'indicators' => [
                    'Submit the syllabus for every assigned subject on or before the first week of classes.',
                    'Submit class records and grade sheets within the deadline set by the Registrar, 100% complete.',
                    'Encode grades in the student portal within five (5) working days after the final examination.',
                ],
            ],
            [
                'title'      => 'Professional Development',
                'indicators' => [
                    'Attend at least one (1) training, seminar or workshop relevant to the field of assignment within the rating period.',
                ],
            ],
        ],
    ],

    [
        'key'         => 'support-office',
        'name'        => 'Office support functions',
        'section'     => 'support',
        'description' => 'Reporting and property duties carried by non-teaching offices.',
        'outputs'     => [
            [
                'title'      => 'Office Reporting',
                'indicators' => [
                    'Submit the monthly accomplishment report within five (5) working days after the end of every month.',
                    'Maintain and update the office records and filing system, 100% retrievable on request.',
                    'Submit the annual inventory of office property and equipment on or before the date set by the Supply Office.',
                ],
            ],
            [
                'title'      => 'Client Service',
                'indicators' => [
                    'Act on client requests within the turnaround time published in the Citizen’s Charter.',
                    'Maintain a client satisfaction rating of at least Very Satisfactory for the rating period.',
                ],
            ],
        ],
    ],

];
