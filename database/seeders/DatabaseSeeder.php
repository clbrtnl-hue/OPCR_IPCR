<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\OrgUnitReviewer;
use App\Models\PcrForm;
use App\Models\PcrIndicator;
use App\Models\PcrOutput;
use App\Models\PcrStatusLog;
use App\Models\RatingPeriod;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\PcrAssignmentService;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Opol Community College as the Hierarchy is set now: the college, the Office of
 * the President, and the offices and programmes under it, with the people and
 * the review slots named there. The 2026 OPCR commitments stay on the college.
 *
 * Everyone signs in with "password".
 */
class DatabaseSeeder extends Seeder
{
    private array $units = [];

    private array $people = [];

    public function run(): void
    {
        $organization = Organization::create([
            'name'       => 'Opol Community College',
            'short_name' => 'OCC',
            'code'       => 'occ',
            'address'    => 'Opol, Misamis Oriental',
            'head_title' => 'College President',
            'status'     => 'active',
        ]);

        app(CurrentOrganization::class)->set($organization->id);

        $this->offices();
        $this->accounts();
        $this->wireHeads();

        [$year, $firstPeriod] = $this->cycle();

        $this->collegeOpcr($year, $firstPeriod);
    }

    private function offices(): void
    {
        $this->units['college'] = OrgUnit::create([
            'name' => 'Opol Community College',
            'code' => 'OCC',
            'type' => 'college',
        ]);

        $this->unit('president_office', 'Office of the College President', 'OP', 'office', 'college');

        $this->unit('aas', 'Academic Affairs and Services', 'AAS', 'office', 'president_office');
        $this->unit('cte', 'College of Teacher Education', 'AAS-CTE', 'program', 'aas');
        $this->unit('cit', 'College of Information Technology', 'AAS-BSIT', 'office', 'aas');
        $this->unit('dit', 'Department of Information Technology', 'AAS-CIT-DIT', 'program', 'cit');
        $this->unit('dis', 'Department of Information Systems', 'AAS-CIT-DIS', 'program', 'cit');
        $this->unit('registrar', 'Registrar', 'REG', 'office', 'aas');
        $this->unit('cba', 'College of Business Administration', 'AAS-BSBA', 'office', 'aas');
        $this->unit('ccj', 'College of Criminology Justice', 'AAS-CCJ', 'program', 'aas');
        $this->unit('gened', 'Department of General Education', 'AAS-GenEd', 'program', 'aas');
        $this->unit('pe', 'Department of Physical Education', 'AAS-PE', 'program', 'aas');

        $this->unit('sas', 'Student Affairs and Services', 'SAS', 'office', 'president_office');
        $this->unit('sss', 'Student Support Services', 'SAS-SSS', 'office', 'sas');
        $this->unit('isps', 'Institutional Student Programs and Services', 'SAS-ISPS', 'office', 'sas');

        $this->unit('afs', 'Administrative and Finance Services', 'AFS', 'office', 'president_office');
        $this->unit('hrmo', 'Human Resource Management Office', 'AFS-HRMO', 'office', 'afs');

        $this->unit('rpces', 'Research, Publication and Community Extension Services', 'RPCES', 'office', 'president_office');
        $this->unit('ecoo', 'Extension and Community Outreach Office', 'RPCES-ECOO', 'office', 'rpces');

        $this->unit('ils', 'International and Linkages Services', 'ILS', 'office', 'president_office');
        $this->unit('cict', 'Center for Information and Communication Technology', 'OP-CICT', 'office', 'president_office');
        $this->unit('qaao', 'Quality Assurance and Accreditation Office', 'OP-QAAO', 'office', 'president_office');
    }

    private function unit(string $key, string $name, string $code, string $type, string $parent): void
    {
        $this->units[$key] = OrgUnit::create([
            'name'      => $name,
            'code'      => $code,
            'type'      => $type,
            'parent_id' => $this->units[$parent]->id,
        ]);
    }

    private function accounts(): void
    {
        // [key, prefix, first, middle, last, suffix, credentials, email, role, position, unit]
        $roster = [
            ['admin', null, 'System', null, 'Administrator', null, null, 'admin@occ.edu.ph', 'admin', 'System Administrator', 'college'],
            ['bation', null, 'Neilson', 'D.', 'Bation', null, 'DM', 'president@occ.edu.ph', 'president', 'College President', 'president_office'],
            ['canay', 'Dr.', 'Wenie Rose', 'D.', 'Canay', null, null, 'vpaa@occ.edu.ph', 'vp', 'Vice President for Academic Affairs and Services', 'aas'],
            ['gurrea', 'Dr.', 'Alma', 'T.', 'Gurrea', null, null, 'vpial@occ.edu.ph', 'vp', 'Vice President for International Affairs and Linkages', 'ils'],
            ['vacalares', 'Mr.', 'Sophomore', null, 'Vacalares', null, null, 'vpaf@occ.edu.ph', 'vp', 'Vice President for Administration and Finance', 'afs'],
            ['hrmo', null, 'HRMO', null, 'Head', null, null, 'hr@occ.edu.ph', 'hr', 'Head, Human Resource Management Office', 'hrmo'],
            ['hrstaff', null, 'HRMO', null, 'Staff', null, null, 'hr.staff@occ.edu.ph', 'employee', 'Staff, Human Resource Management Office', 'hrmo'],
            ['pinto', 'Mr.', 'Ronnel', null, 'Pinto', null, null, 'vprpe@occ.edu.ph', 'vp', 'Vice President for Research, Publication, and Extension', 'rpces'],
            ['baculio', 'Mr.', 'Keith Lou', null, 'Baculio', null, null, 'vpsas@occ.edu.ph', 'vp', 'Vice President for Student Affairs and Services', 'sas'],
            ['qa', 'Dr.', 'Jeziel', null, 'Alcover-Abuloc', null, null, 'qa.head@occ.edu.ph', 'qa', 'Head, Quality Assurance and Accreditation', 'qaao'],
            ['madriaga', 'Mr.', 'Ruben', null, 'Madriaga', null, null, 'bsit.head@occ.edu.ph', 'program_head', 'Dean, College of Information Technology', 'cit'],
            ['pacana', 'Dr.', 'Amabelle', null, 'Pacana', null, 'CPA', 'dean.cba@occ.edu.ph', 'program_head', 'Dean, College of Business Administration', 'cba'],
            ['magnetico', 'Ms.', 'Daceil', null, 'Magnetico', null, null, 'dean.cte@occ.edu.ph', 'program_head', 'Dean, College of Teacher Education', 'cte'],
            ['nacua', 'Ms.', 'Bernadeth', 'T.', 'Nacua', null, null, 'registrar@occ.edu.ph', 'program_head', 'College Registrar', 'registrar'],
            ['iyo', 'Mr.', 'Gaudencio', null, 'Iyo', 'Jr.', null, 'iyo@occ.edu.ph', 'program_head', 'Director, Center for Information and Communication Technology', 'cict'],
            ['verula', 'Ms.', 'Annabelle', 'T.', 'Verula', null, null, 'guidance@occ.edu.ph', 'program_head', 'Head, Guidance and Counseling Services', 'sss'],
            ['arancon', 'Dr.', 'Rodelia', 'T.', 'Arancon', null, null, 'library@occ.edu.ph', 'program_head', 'Head, Library Services', 'sas'],
            ['arao', 'Dr.', 'Dolorita', 'N.', 'Arao', null, null, 'comm@occ.edu.ph', 'program_head', 'Director, Community Extension', 'ecoo'],
            ['gutierrez', 'Mr.', 'Richard', null, 'Gutierriez', null, null, 'pheadIT@occ.edu.ph', 'program_head', 'Program Head, Department of Information technology', 'dit'],
            ['daculan', 'Ms.', 'Novelyn Joy', null, 'Daculan', null, null, 'faculty@occ.edu.ph', 'employee', 'Faculty', 'dis'],
            ['linogao', 'Ms.', 'Maria Jessa', 'M.', 'Linogao', null, null, 'faculty2@occ.edu.ph', 'employee', 'Faculty', 'dit'],
            ['dantes', 'Ms.', 'Rachel Ana', null, 'Dantes', null, null, 'faculty3@occ.edu.ph', 'employee', 'Faculty', 'dit'],
            ['siangco', 'Mr.', 'Adrian Karlo', null, 'Siangco', null, null, 'faculty4@occ.edu.ph', 'employee', 'Faculty', 'dit'],
            ['jangao', 'Mr', 'Jope Harold', null, 'Jangao', null, null, 'qaacad@occ.edu.ph', 'employee', 'QA Coordinator for Academic Services', 'qaao'],
        ];

        foreach ($roster as [$key, $prefix, $first, $middle, $last, $suffix, $credentials, $email, $role, $position, $unit]) {
            $this->people[$key] = User::create([
                'prefix'         => $prefix,
                'first_name'     => $first,
                'middle_initial' => $middle,
                'last_name'      => $last,
                'suffix'         => $suffix,
                'credentials'    => $credentials,
                'email'          => $email,
                'password'       => 'password',
                'role'           => $role,
                'position_title' => $position,
                'org_unit_id'    => $this->units[$unit]->id,
            ]);
        }
    }

    /** Who fills each review slot, copied from the live hierarchy. */
    private function wireHeads(): void
    {
        $slots = [
            'aas'       => ['vp' => 'canay'],
            'cte'       => ['dean' => 'magnetico', 'vp' => 'canay'],
            'cit'       => ['dean' => 'madriaga', 'vp' => 'canay'],
            'dit'       => ['head' => 'gutierrez', 'dean' => 'madriaga', 'vp' => 'canay'],
            'dis'       => ['dean' => 'madriaga', 'vp' => 'canay'],
            'registrar' => ['head' => 'nacua', 'vp' => 'canay'],
            'cba'       => ['dean' => 'pacana', 'vp' => 'canay'],
            'ccj'       => ['vp' => 'canay'],
            'gened'     => ['vp' => 'canay'],
            'pe'        => ['vp' => 'canay'],
            'sas'       => ['vp' => 'baculio'],
            'sss'       => ['vp' => 'baculio'],
            'isps'      => ['vp' => 'baculio'],
            'afs'       => ['vp' => 'vacalares'],
            'hrmo'      => ['head' => 'hrmo', 'vp' => 'vacalares'],
            'rpces'     => ['vp' => 'pinto'],
            'ecoo'      => ['director' => 'arao', 'vp' => 'pinto'],
            'ils'       => ['vp' => 'gurrea'],
            'cict'      => ['director' => 'iyo'],
            'qaao'      => ['head' => 'qa'],
        ];

        foreach ($slots as $unit => $named) {
            $this->units[$unit]->update([
                'head_user_id' => isset($named['head']) ? $this->people[$named['head']]->id : null,
                'dean_user_id' => isset($named['dean']) ? $this->people[$named['dean']]->id : null,
                'vp_user_id'   => isset($named['vp']) ? $this->people[$named['vp']]->id : null,
            ]);

            if (isset($named['director'])) {
                OrgUnitReviewer::updateOrCreate(
                    ['org_unit_id' => $this->units[$unit]->id, 'slot' => 'director_review'],
                    ['user_id' => $this->people[$named['director']]->id],
                );
            }
        }
    }

    private function cycle(): array
    {
        $year = SchoolYear::create([
            'label'      => '2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'status'     => 'open',
            'is_active'  => true,
        ]);

        $first = RatingPeriod::create([
            'school_year_id' => $year->id,
            'seq'            => 1,
            'label'          => 'January to June',
            'opens_at'       => '2026-06-01',
            'closes_at'      => '2026-06-30',
            'status'         => 'open',
            'is_active'      => true,
        ]);

        RatingPeriod::create([
            'school_year_id' => $year->id,
            'seq'            => 2,
            'label'          => 'July to December',
            'opens_at'       => '2026-12-01',
            'closes_at'      => '2026-12-31',
            'status'         => 'upcoming',
        ]);

        return [$year, $first];
    }

    /**
     * The college's 2026 commitments, taken from the OPCR the president signed.
     * Published, so everyone's IPCR has something to answer to.
     */
    private function collegeOpcr(SchoolYear $year, RatingPeriod $period): void
    {
        $assignments = app(PcrAssignmentService::class);

        \Illuminate\Support\Facades\Auth::login($this->people['bation']);

        $opcr = PcrForm::create([
            'type'           => 'opcr',
            'school_year_id' => $year->id,
            'org_unit_id'    => $this->units['college']->id,
            'status'         => 'published',
        ]);

        // Explicit: the create above goes through the model's default status.
        $opcr->forceFill(['status' => 'published'])->save();

        PcrStatusLog::record($opcr->id, null, 'draft', 'Form created');
        PcrStatusLog::record($opcr->id, 'approved', 'published', 'Targets approved and published');

        // [section, title, parent title or null, [[indicator, accountable keys]]]
        $sheet = [
            ['strategic', 'Digitalization', null, []],
            ['strategic', 'Entrance Exam', 'Digitalization', [
                ['75% implementation of an online entrance exam system that provides immediate results to all examinees from January to December 2026.', ['bation', 'verula']],
            ]],
            ['strategic', 'Enrollment', 'Digitalization', [
                ['Accommodate 80% of enrollees through the online enrollment system from January to December 2026.', ['bation', 'nacua']],
            ]],
            ['strategic', 'Faculty Evaluation', 'Digitalization', [
                ['Achieve 70% participation of the total student population in the online faculty evaluation from January to December 2026.', ['bation', 'canay']],
            ]],
            ['strategic', 'Grading Portal', 'Digitalization', [
                ['70% of the total student population can access the online grading system from January to December 2026.', ['bation', 'iyo']],
            ]],
            ['strategic', 'Library', 'Digitalization', [
                ['70% of the total student population can access the online library system from January to December 2026.', ['bation', 'arancon']],
            ]],
            ['strategic', 'Employability Preparation', null, [
                ['70% of the graduating students participate in the job fair programme hosted by OCC and/or other partner institutions.', ['verula']],
            ]],
            ['strategic', 'Modernization', null, []],
            ['strategic', 'Laboratories', 'Modernization', [
                ['Accommodate 80% of the total Information Technology students from January to December 2026.', ['vacalares', 'madriaga']],
            ]],

            ['core', 'Instruction', null, []],
            ['core', 'Increase Enrollment', 'Instruction', [
                ['Increase of enrolment by 5% from January to December 2026 compared to the previous year.', ['madriaga', 'pacana']],
            ]],
            ['core', 'Retention Rate of 2nd year to 4th year Students', 'Instruction', [
                ['Increase of student retention by 5% from January to December 2026 compared to the previous year.', ['madriaga', 'magnetico']],
            ]],
            ['core', 'Retention of Faculty', 'Instruction', [
                ['Increase of faculty retention rate by 5% from January to December 2026 compared to the previous year.', ['madriaga', 'canay']],
            ]],
            ['core', 'Application of Programs/Courses', 'Instruction', [
                ['Apply 1 additional programme or course from January to December 2026.', ['madriaga', 'pacana']],
            ]],
            ['core', 'Passing Rate', 'Instruction', [
                ['Achieve the National Passing Rate for board course/s from January to December 2026.', ['canay', 'magnetico']],
            ]],
            ['core', 'Library', 'Instruction', [
                ['Comply 5% of the required number of new book collections for each programme from the total library holdings.', ['arancon']],
            ]],
            ['core', 'Faculty Development', 'Instruction', [
                ['Provide an 8-hour faculty development training programme from January to December 2026.', ['canay']],
            ]],
            ['core', 'Research', null, []],
            ['core', 'OCC Research and Journal Output', 'Research', [
                ['Publish 25 peer-reviewed researches/journals/articles from January to December 2026.', ['pinto']],
            ]],
            ['core', 'Research Conference', 'Research', [
                ['Conduct 1 research conference from January to December 2026.', ['pinto']],
            ]],
            ['core', 'Extension', null, []],
            ['core', 'Linkages', 'Extension', []],
            ['core', 'Local', 'Linkages', [
                ['2 signed MOU/MOA with local linkages from January to December 2026.', ['arao']],
                ['Adopt 2 communities from January to December 2026.', ['arao']],
            ]],
            ['core', 'International', 'Linkages', [
                ['2 signed MOU/MOA with international linkages from January to December 2026.', ['gurrea']],
            ]],
            ['core', 'Production', null, []],
            ['core', 'Capstone and Research Project', 'Production', [
                ['Utilize 2 Capstone or Research project adopted/used by any institutions/establishments.', ['madriaga', 'pinto']],
            ]],

            ['support', 'Submission of OPCR to the MPDO', null, [
                ['On time submission of OPCR to the MPDO with 100% accuracy.', ['bation']],
            ]],
            ['support', 'Actions on other matters referred to by the LCE or his authorized representative.', null, [
                ['Acted on matters referred to by the LCE or his authorized representative within the reglementary period from receipt with 100% accuracy.', ['bation']],
            ]],
            ['support', 'Compliance to regulatory Orders issued by competent authority.', null, [
                ['Regulatory Orders issued by competent authority are complied with within the given period with 100% accuracy.', ['bation']],
            ]],
            ['support', 'Attendance to the activities of other committee membership such as regular meetings.', null, [
                ['Attended activities such as regular meetings in other committee membership.', ['bation']],
            ]],
            ['support', 'Attendance to MANCOM meetings.', null, [
                ['Attended all Management Committee Meetings.', ['bation']],
            ]],
            ['support', 'Budget Utilization Rate (BUR).', null, [
                ['100% compliance on the required budget utilization rate.', ['bation']],
            ]],
        ];

        $order   = 0;
        $created = [];

        foreach ($sheet as [$section, $title, $parentTitle, $lines]) {
            $output = PcrOutput::create([
                'form_id'          => $opcr->id,
                'section'          => $section,
                'title'            => $title,
                'parent_output_id' => $parentTitle ? ($created[$section.'|'.$parentTitle] ?? null) : null,
                'sort_order'       => ++$order,
            ]);

            $created[$section.'|'.$title] = $output->id;

            foreach ($lines as $index => [$description, $accountableKeys]) {
                $line = PcrIndicator::create([
                    'output_id'        => $output->id,
                    'rating_period_id' => $period->id,
                    'description'      => "<p>{$description}</p>",
                    'sort_order'       => $index + 1,
                ]);

                foreach ($accountableKeys as $key) {
                    $assignments->assignIndicator($line, $this->people[$key], $this->people['bation']);
                }
            }
        }

        $this->madriagaIpcr($year, $period);

        \Illuminate\Support\Facades\Auth::logout();
    }

    /** Madriaga answers the college targets with his own Program Chair commitments. */
    private function madriagaIpcr(SchoolYear $year, RatingPeriod $period): void
    {
        $madriaga = $this->people['madriaga'];

        $ipcr = PcrForm::firstOrCreate(
            [
                'type'             => 'ipcr',
                'school_year_id'   => $year->id,
                'user_id'          => $madriaga->id,
                'rating_period_id' => $period->id,
            ],
            [
                'org_unit_id' => $madriaga->org_unit_id,
                'status'      => 'draft',
            ]
        );

        $targets = PcrIndicator::whereHas('assignments', fn ($q) => $q->where('user_id', $madriaga->id))
            ->with('output')
            ->get()
            ->keyBy(fn ($line) => $line->output->title);

        $commitments = [
            [
                'Curriculum and Academics: The Program Chair manages course scheduling, teaching assignments, and facility needs while leading program reviews and ensuring accreditation requirements are met.',
                'Ensure 100% of courses are scheduled on time without conflicts each semester.',
                'Increase Enrollment',
            ],
            [
                'Faculty and Staff Management: The Program Chair sets the department’s vision and goals, promotes faculty development, and oversees faculty performance and workload.',
                'Complete 100% of faculty performance evaluations on time within 6 months.',
                'Retention of Faculty',
            ],
            [
                'Student and Program Recruitment: The Program Chair markets the IT program, recruits new students, and works to maintain enrolment.',
                'Maintain or improve the student retention rate by at least 5% over the next 6 months compared to the previous semester.',
                'Retention Rate of 2nd year to 4th year Students',
            ],
        ];

        foreach ($commitments as $index => [$title, $description, $anchor]) {
            $output = PcrOutput::create([
                'form_id'    => $ipcr->id,
                'section'    => 'core',
                'title'      => $title,
                'sort_order' => $index + 1,
            ]);

            $target = $targets[$anchor] ?? null;

            PcrIndicator::create([
                'output_id'           => $output->id,
                'rating_period_id'    => $period->id,
                'description'         => "<p>{$description}</p>",
                'parent_indicator_id' => $target?->id,
                'sort_order'          => 1,
            ]);
        }

        $support = [
            ['Submission of IPCR to the Head of Office', 'IPCR submitted on the deadline with 95% accuracy'],
            ['Submission of DTR', 'Duly accomplished DTR submitted on the deadline with 95% accuracy'],
            ['Attendance to Monday convocation', '100% attendance except when on Travel Order or official leave'],
        ];

        foreach ($support as $index => [$title, $description]) {
            $output = PcrOutput::create([
                'form_id'    => $ipcr->id,
                'section'    => 'support',
                'title'      => $title,
                'sort_order' => $index + 10,
            ]);

            PcrIndicator::create([
                'output_id'        => $output->id,
                'rating_period_id' => $period->id,
                'description'      => "<p>{$description}</p>",
                'sort_order'       => 1,
            ]);
        }
    }
}
