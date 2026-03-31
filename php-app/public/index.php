<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/config.php';
$GLOBALS['config'] = $config;

date_default_timezone_set($config['timezone']);

session_name($config['session_name']);
session_set_cookie_params([
    'httponly' => true,
    'secure' => $config['session_secure'],
    'samesite' => $config['session_samesite'],
    'path' => '/',
]);
session_start();

require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/scoring.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/ai.php';

set_security_headers($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
}

$pdo = db($config);
$method = $_SERVER['REQUEST_METHOD'];
$path = current_path();

if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
    $path = $_GET['r'];
}

if (!isset($_COOKIE['disc_browser_token']) || !is_string($_COOKIE['disc_browser_token']) || strlen($_COOKIE['disc_browser_token']) < 20) {
    setcookie('disc_browser_token', bin2hex(random_bytes(16)), [
        'expires' => time() + (60 * 60 * 24 * 365),
        'path' => '/',
        'secure' => $config['cookie_secure'],
        'httponly' => true,
        'samesite' => $config['cookie_samesite'],
    ]);
    $_COOKIE['disc_browser_token'] = $_COOKIE['disc_browser_token'] ?? '';
}

function recommendation_options(): array
{
    return [
        ['value' => 'MANAGER', 'label' => 'Manager'],
        ['value' => 'BACK_OFFICE', 'label' => 'Back Office'],
        ['value' => 'HEAD_KITCHEN', 'label' => 'Head Kitchen'],
        ['value' => 'HEAD_BAR', 'label' => 'Head Bar'],
        ['value' => 'FLOOR_CAPTAIN', 'label' => 'Floor Captain'],
        ['value' => 'COOK', 'label' => 'Cook'],
        ['value' => 'COOK_HELPER', 'label' => 'Cook Helper'],
        ['value' => 'STEWARD', 'label' => 'Steward'],
        ['value' => 'MIXOLOGIST', 'label' => 'Mixologist'],
        ['value' => 'SERVER', 'label' => 'Server'],
        ['value' => 'HOUSEKEEPING', 'label' => 'Housekeeping'],
        ['value' => 'INCOMPLETE', 'label' => 'Incomplete'],
        ['value' => 'TIDAK_DIREKOMENDASIKAN', 'label' => 'Tidak Direkomendasikan'],
    ];
}

function question_role_options(): array
{
    return ['All'];
}

function essay_group_options(): array
{
    return ['Manager', 'Back office', 'Kitchen', 'Bar', 'Floor'];
}

function essay_group_by_selected_role(string $selectedRole): string
{
    $map = [
        'Manager' => 'Manager',
        'Back Office' => 'Back office',
        'Head Kitchen' => 'Kitchen',
        'Cook' => 'Kitchen',
        'Cook Helper' => 'Kitchen',
        'Steward' => 'Kitchen',
        'Head Bar' => 'Bar',
        'Mixologist' => 'Bar',
        'Floor Captain' => 'Floor',
        'Server' => 'Floor',
        'Housekeeping' => 'Floor',
    ];

    return $map[$selectedRole] ?? 'Floor';
}

function interview_checklist_sections(): array
{
    return [
        'Verifikasi Jawaban Esai' => [
            'essay_restate_clear' => 'Kandidat bisa menjelaskan ulang jawaban utama dengan runtut',
            'essay_real_examples' => 'Menyebut contoh nyata (tidak umum)',
            'essay_personal_role' => 'Menjelaskan peran pribadi, bukan hanya tim',
            'essay_measurable_result' => 'Menyebut hasil terukur (angka/waktu/perubahan)',
        ],
        'Konsistensi dengan DISC' => [
            'disc_communication_match' => 'Gaya komunikasi sesuai profil DISC',
            'disc_decision_match' => 'Cara mengambil keputusan sesuai role target',
            'disc_pressure_match' => 'Respons tekanan sesuai tuntutan kerja',
            'disc_no_major_conflict' => 'Tidak ada kontradiksi besar antara tes dan wawancara',
        ],
        'Role Fit Praktis' => [
            'fit_sop' => 'Pemahaman SOP dasar posisi memadai',
            'fit_priority' => 'Mampu menentukan prioritas kerja dengan tepat',
            'fit_problem_solving' => 'Problem solving relevan untuk posisi',
            'fit_teamwork' => 'Koordinasi tim dan komunikasi kerja baik',
            'fit_service_or_accuracy' => 'Service mindset / ketelitian SOP sesuai role',
        ],
        'Mini Case (3-5 menit)' => [
            'case_relevance' => 'Solusi relevan dengan kasus',
            'case_speed' => 'Kecepatan berpikir cukup baik',
            'case_clarity' => 'Langkah eksekusi jelas',
            'case_risk_awareness' => 'Risiko operasional dipertimbangkan',
            'case_decision_quality' => 'Keputusan akhir masuk akal',
        ],
        'Indikator Risiko' => [
            'risk_too_generic' => 'Jawaban terlalu generik / templated',
            'risk_followup_weak' => 'Sulit menjawab pertanyaan lanjutan',
            'risk_inconsistent' => 'Inkonsisten antar jawaban',
            'risk_need_reference' => 'Perlu verifikasi referensi tambahan',
        ],
    ];
}

function normalize_interview_checklist_input(array $raw): array
{
    $sections = interview_checklist_sections();
    $result = [];
    foreach ($sections as $items) {
        foreach ($items as $key => $_label) {
            $result[$key] = !empty($raw[$key]);
        }
    }
    return $result;
}

function integrity_risk_from_candidate(array $candidate): array
{
    $tabSwitches = (int) ($candidate['integrity_tab_switches'] ?? 0);
    $pasteCount = (int) ($candidate['integrity_paste_count'] ?? 0);
    $score = ($tabSwitches * 2) + ($pasteCount * 3);

    $level = 'Low';
    if ($score >= 16) {
        $level = 'High';
    } elseif ($score >= 8) {
        $level = 'Medium';
    }

    return [
        'level' => $level,
        'score' => $score,
        'tab_switches' => $tabSwitches,
        'paste_count' => $pasteCount,
    ];
}

function typing_risk_from_rows(array $rows): array
{
    $score = 0;
    foreach ($rows as $row) {
        $chars = (int) ($row['total_chars'] ?? 0);
        $keystrokes = (int) ($row['keystrokes'] ?? 0);
        $paste = (int) ($row['paste_count'] ?? 0);
        $activeMs = (int) ($row['active_ms'] ?? 0);

        if ($chars >= 240 && $keystrokes <= 20) {
            $score += 6;
        }
        if ($paste >= 2) {
            $score += 4;
        }
        if ($chars >= 200 && $activeMs <= 25000) {
            $score += 4;
        }
    }

    $level = 'Low';
    if ($score >= 14) {
        $level = 'High';
    } elseif ($score >= 7) {
        $level = 'Medium';
    }

    return ['level' => $level, 'score' => $score];
}

function integrity_phase_label(?string $phase): string
{
    $map = [
        'disc' => 'Tes DISC',
        'essay' => 'Tes Esai',
        'unknown' => 'Tidak diketahui',
    ];
    $key = strtolower(trim((string) $phase));
    return $map[$key] ?? ((string) $phase !== '' ? (string) $phase : '-');
}

function integrity_event_label(?string $eventType): string
{
    $map = [
        'candidate_start' => 'Kandidat memulai asesmen',
        'page_open' => 'Halaman tes dibuka',
        'submit_attempt' => 'Mencoba submit jawaban',
        'invalid_submit' => 'Submit ditolak (jawaban belum valid)',
        'tab_switch' => 'Berpindah tab/jendela',
        'paste_detected' => 'Terdeteksi paste',
        'before_unload' => 'Meninggalkan halaman',
        'auto_submit_timeout' => 'Sistem submit otomatis (waktu habis)',
        'phase_complete_submit' => 'Fase selesai lewat submit kandidat',
        'phase_complete_timeout' => 'Fase selesai karena waktu habis',
        'final_submit_timeout' => 'Submit akhir otomatis (waktu habis)',
        'final_submit_manual' => 'Submit akhir oleh kandidat',
        'final_submit_timeout_sweep' => 'Submit akhir otomatis (sweeper)',
    ];
    $key = strtolower(trim((string) $eventType));
    return $map[$key] ?? ((string) $eventType !== '' ? (string) $eventType : '-');
}

function integrity_event_value_label(?string $eventValue): string
{
    $map = [
        'identity_submitted' => 'Identitas berhasil disimpan',
        'disc' => 'Tahap DISC',
        'essay' => 'Tahap Esai',
        'hidden' => 'Halaman tidak aktif (hidden)',
        'disc_to_essay' => 'Lanjut dari DISC ke Esai',
        'assessment_complete' => 'Asesmen selesai',
    ];
    $key = strtolower(trim((string) $eventValue));
    if ($key === '') {
        return '-';
    }
    return $map[$key] ?? (string) $eventValue;
}

function ensure_candidate_session(PDO $pdo): ?array
{
    $candidateId = isset($_SESSION['candidate_id']) ? (int) $_SESSION['candidate_id'] : 0;
    if ($candidateId <= 0) {
        return null;
    }

    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        unset($_SESSION['candidate_id']);
        return null;
    }

    return $candidate;
}

function build_answers_from_payload(array $payload, array $questions): array
{
    $answers = [];
    $discByCodePerQuestion = [];
    foreach ($questions as $q) {
        $qid = (int) ($q['id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }
        $discByCodePerQuestion[$qid] = [
            'A' => strtoupper((string) ($q['discA'] ?? 'D')),
            'B' => strtoupper((string) ($q['discB'] ?? 'I')),
            'C' => strtoupper((string) ($q['discC'] ?? 'S')),
            'D' => strtoupper((string) ($q['discD'] ?? 'C')),
        ];
    }

    foreach ($questions as $question) {
        $qid = (int) $question['id'];
        $most = trim((string) ($payload['q_' . $qid . '_most'] ?? ''));
        $least = trim((string) ($payload['q_' . $qid . '_least'] ?? ''));

        if (!in_array($most, ['A', 'B', 'C', 'D'], true) || !in_array($least, ['A', 'B', 'C', 'D'], true) || $most === $least) {
            continue;
        }

        $answers[$qid] = [
            'most' => ['optionCode' => $most, 'disc' => $discByCodePerQuestion[$qid][$most] ?? OPTION_TO_DISC[$most] ?? null],
            'least' => ['optionCode' => $least, 'disc' => $discByCodePerQuestion[$qid][$least] ?? OPTION_TO_DISC[$least] ?? null],
        ];
    }

    return $answers;
}

function to_disc_payload(array $answers): array
{
    $out = [];
    foreach ($answers as $qid => $entry) {
        $out[$qid] = [
            'mostDisc' => $entry['most']['disc'] ?? null,
            'leastDisc' => $entry['least']['disc'] ?? null,
        ];
    }
    return $out;
}

function is_valid_answer_entry(array $entry): bool
{
    $mostCode = $entry['most']['optionCode'] ?? null;
    $leastCode = $entry['least']['optionCode'] ?? null;
    return in_array($mostCode, ['A', 'B', 'C', 'D'], true)
        && in_array($leastCode, ['A', 'B', 'C', 'D'], true)
        && $mostCode !== $leastCode;
}

function parse_draft_answers_from_candidate(array $candidate): array
{
    $raw = $candidate['draft_answers_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $answers = [];
    foreach ($decoded as $questionId => $entry) {
        if (!is_array($entry) || !is_valid_answer_entry($entry)) {
            continue;
        }

        $qid = (int) $questionId;
        if ($qid <= 0) {
            continue;
        }

        $mostCode = (string) $entry['most']['optionCode'];
        $leastCode = (string) $entry['least']['optionCode'];
        $answers[$qid] = [
            'most' => ['optionCode' => $mostCode, 'disc' => OPTION_TO_DISC[$mostCode] ?? null],
            'least' => ['optionCode' => $leastCode, 'disc' => OPTION_TO_DISC[$leastCode] ?? null],
        ];
    }

    return $answers;
}

function parse_draft_essay_answers_from_candidate(array $candidate): array
{
    $raw = $candidate['draft_essay_answers_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $answers = [];
    foreach ($decoded as $questionId => $text) {
        $qid = (int) $questionId;
        if ($qid <= 0) {
            continue;
        }
        $value = trim((string) $text);
        if ($value === '') {
            continue;
        }
        $answers[$qid] = $value;
    }

    return $answers;
}

function build_essay_answers_from_payload(array $payload, array $essayQuestions): array
{
    $answers = [];
    foreach ($essayQuestions as $question) {
        $qid = (int) ($question['id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }
        $text = trim((string) ($payload['essay_' . $qid] ?? ''));
        if ($text !== '') {
            $answers[$qid] = $text;
        }
    }
    return $answers;
}

function merge_answers_with_fallback(array $primary, array $fallback): array
{
    $merged = $fallback;
    foreach ($primary as $qid => $entry) {
        if (is_valid_answer_entry($entry)) {
            $merged[(int) $qid] = $entry;
        }
    }
    ksort($merged);
    return $merged;
}

function is_login_blocked(PDO $pdo, array $config, string $ip): array
{
    $entry = get_login_attempt($pdo, $ip);
    $now = time();

    if (!$entry) {
        return ['blocked' => false, 'retry_after' => 0, 'entry' => null];
    }

    if ((int) $entry['blocked_until'] > $now) {
        return ['blocked' => true, 'retry_after' => (int) $entry['blocked_until'] - $now, 'entry' => $entry];
    }

    if (($now - (int) $entry['window_start']) > $config['hr_login_window_sec']) {
        clear_login_attempt($pdo, $ip);
        return ['blocked' => false, 'retry_after' => 0, 'entry' => null];
    }

    return ['blocked' => false, 'retry_after' => 0, 'entry' => $entry];
}

function register_login_failure(PDO $pdo, array $config, string $ip): void
{
    $state = is_login_blocked($pdo, $config, $ip);
    $entry = $state['entry'];
    $now = time();

    $windowStart = $entry ? (int) $entry['window_start'] : $now;
    if (($now - $windowStart) > $config['hr_login_window_sec']) {
        $windowStart = $now;
        $failedCount = 1;
    } else {
        $failedCount = (int) ($entry['failed_count'] ?? 0) + 1;
    }

    $blockedUntil = 0;
    if ($failedCount >= $config['hr_login_max_attempts']) {
        $blockedUntil = $now + $config['hr_login_lock_sec'];
    }

    upsert_login_attempt($pdo, $ip, $failedCount, $windowStart, $blockedUntil);
}

function parse_candidate_profile_answers(array $answers): array
{
    $map = [];
    foreach ($answers as $answer) {
        $qid = (int) $answer['question_id'];
        if (!isset($map[$qid])) {
            $map[$qid] = ['id' => $qid, 'most' => '-', 'least' => '-'];
        }

        if ($answer['answer_type'] === 'most') {
            $map[$qid]['most'] = $answer['option_code'] ?: '-';
        }
        if ($answer['answer_type'] === 'least') {
            $map[$qid]['least'] = $answer['option_code'] ?: '-';
        }
    }

    ksort($map);
    return array_values($map);
}

function answer_option_text(array $row, ?string $code): string
{
    if ($code === null || $code === '') {
        return '-';
    }

    switch ($code) {
        case 'A':
            return (string) ($row['option_a'] ?? '-');
        case 'B':
            return (string) ($row['option_b'] ?? '-');
        case 'C':
            return (string) ($row['option_c'] ?? '-');
        case 'D':
            return (string) ($row['option_d'] ?? '-');
        default:
            return '-';
    }
}

function normalize_role_score_10($raw): int
{
    $num = is_numeric($raw) ? (float) $raw : 0.0;
    if ($num > 10) {
        $num = round($num / 10);
    } else {
        $num = round($num);
    }

    if ($num < 0) {
        return 0;
    }
    if ($num > 10) {
        return 10;
    }
    return (int) $num;
}

function canonical_role_code(?string $code): ?string
{
    $map = [
        'SERVER_SPECIALIST' => 'SERVER',
        'BEVERAGE_SPECIALIST' => 'MIXOLOGIST',
        'SENIOR_COOK' => 'COOK',
        'ASSISTANT_MANAGER' => 'MANAGER',
        'OPERATIONS_ADMIN' => 'BACK_OFFICE',
        'FLOOR_CREW' => 'SERVER',
        'BAR_CREW' => 'MIXOLOGIST',
        'KITCHEN_CREW' => 'COOK',
    ];

    if (!is_string($code) || $code === '') {
        return null;
    }
    return $map[$code] ?? $code;
}

function extract_role_scores_from_candidate(array $candidate): array
{
    $scores = [];
    $json = $candidate['role_scores_json'] ?? '';
    if (is_string($json) && $json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $canonical = canonical_role_code((string) $k);
                if ($canonical) {
                    $scores[$canonical] = normalize_role_score_10($v);
                }
            }
        }
    }

    if (empty($scores)) {
        $scores = [
            'SERVER' => normalize_role_score_10($candidate['score_server'] ?? 0),
            'MIXOLOGIST' => normalize_role_score_10($candidate['score_beverage'] ?? 0),
            'COOK' => normalize_role_score_10($candidate['score_cook'] ?? 0),
        ];
    }

    return $scores;
}

function interview_recommendation_label(array $candidate, array $roleScores): string
{
    $recommendation = canonical_role_code($candidate['recommendation'] ?? null);
    if (($candidate['recommendation'] ?? null) === 'INCOMPLETE') {
        return 'Data belum cukup, perlu tes ulang';
    }
    if (($candidate['recommendation'] ?? null) === 'TIDAK_DIREKOMENDASIKAN') {
        return 'Belum disarankan lanjut wawancara';
    }

    $score = 0;
    if ($recommendation && isset($roleScores[$recommendation])) {
        $score = normalize_role_score_10($roleScores[$recommendation]);
    } elseif (!empty($roleScores)) {
        $score = max(array_map('normalize_role_score_10', $roleScores));
    }

    if ($score >= 8) {
        return 'Direkomendasikan lanjut wawancara';
    }
    if ($score >= 7) {
        return 'Masih berpotensi, wawancara terarah';
    }
    if ($score >= 6) {
        return 'Dicoba dulu, perlu pendalaman';
    }
    return 'Belum disarankan lanjut wawancara';
}

function expire_overdue_candidates(PDO $pdo, array $config): int
{
    $limit = (int) ($config['timeout_sweep_limit'] ?? 200);
    $overdueCandidates = list_overdue_in_progress_candidates($pdo, $limit);
    $expiredCount = 0;
    $minRatio = (float) ($config['min_completion_ratio'] ?? 0.8);

    foreach ($overdueCandidates as $candidate) {
        $draftAnswers = parse_draft_answers_from_candidate($candidate);
        $totalQuestions = count(list_questions($pdo, false, null));
        $answeredCount = count($draftAnswers);
        $minimumRequired = (int) ceil($totalQuestions * $minRatio);
        $baseEvaluation = evaluate_candidate(to_disc_payload($draftAnswers), $candidate['selected_role'] ?? null);
        $evaluation = ($answeredCount < $minimumRequired)
            ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
            : $baseEvaluation;

        $phaseDeadline = (string) ($candidate['deadline_at'] ?? now_iso());
        if (!empty($candidate['disc_completed_at']) && !empty($candidate['essay_deadline_at'])) {
            $phaseDeadline = (string) $candidate['essay_deadline_at'];
            $draftEssay = parse_draft_essay_answers_from_candidate($candidate);
            if (!empty($draftEssay)) {
                save_essay_answers($pdo, (int) $candidate['id'], $draftEssay);
            }
        }

        $saved = save_submission($pdo, [
            'candidate_id' => (int) $candidate['id'],
            'answers' => $draftAnswers,
            'submitted_at' => $phaseDeadline,
            'duration_seconds' => seconds_between((string) $candidate['started_at'], $phaseDeadline),
            'evaluation' => $evaluation,
            'force_status' => 'timeout_submitted',
        ]);

        if ($saved) {
            log_integrity_event($pdo, (int) $candidate['id'], !empty($candidate['disc_completed_at']) ? 'essay' : 'disc', 'final_submit_timeout_sweep', 'assessment_complete');
            $expiredCount++;
        }
    }

    return $expiredCount;
}

function run_periodic_timeout_sweep(PDO $pdo, array $config): void
{
    $interval = max(5, (int) ($config['timeout_sweep_every_seconds'] ?? 20));
    $now = time();
    $lastSweep = isset($_SESSION['last_timeout_sweep']) ? (int) $_SESSION['last_timeout_sweep'] : 0;

    if (($now - $lastSweep) < $interval) {
        return;
    }

    expire_overdue_candidates($pdo, $config);
    $_SESSION['last_timeout_sweep'] = $now;
}

run_periodic_timeout_sweep($pdo, $config);

function extract_bulk_csv_input(): string
{
    $csvRaw = trim((string) ($_POST['bulk_csv'] ?? ''));
    if ($csvRaw !== '') {
        return $csvRaw;
    }

    if (!empty($_FILES['bulk_csv_file']) && is_array($_FILES['bulk_csv_file']) && (int) ($_FILES['bulk_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmpPath = (string) ($_FILES['bulk_csv_file']['tmp_name'] ?? '');
        if ($tmpPath !== '' && is_uploaded_file($tmpPath)) {
            $fileContent = file_get_contents($tmpPath);
            if (is_string($fileContent)) {
                return trim($fileContent);
            }
        }
    }

    return '';
}

if ($method === 'GET' && $path === '/') {
    $candidate = ensure_candidate_session($pdo);
    if ($candidate && $candidate['status'] === 'in_progress') {
        if (!empty($candidate['disc_completed_at'])) {
            redirect(route_path('/essay-test'));
        }
        redirect(route_path('/disc-test'));
    }

    $browserToken = $_COOKIE['disc_browser_token'] ?? '';
    if (is_string($browserToken) && $browserToken !== '') {
        $active = get_in_progress_candidate_by_browser_token($pdo, $browserToken);
        if ($active) {
            $_SESSION['candidate_id'] = (int) $active['id'];
            if (!empty($active['disc_completed_at'])) {
                redirect(route_path('/essay-test'));
            }
            redirect(route_path('/disc-test'));
        }
    }

    render('candidate/identity', [
        'page_title' => 'Asesmen Kandidat',
        'role_options' => $config['role_options'],
        'values' => [],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/start') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $selectedRole = trim((string) ($_POST['selected_role'] ?? ''));

    $values = [
        'full_name' => $fullName,
        'email' => $email,
        'whatsapp' => $whatsapp,
        'selected_role' => $selectedRole,
    ];

    $activeQuestions = list_questions($pdo, false, null);
    if (empty($activeQuestions)) {
        render('candidate/identity', [
            'page_title' => 'Asesmen Kandidat',
            'role_options' => $config['role_options'],
            'error_message' => 'Tes belum tersedia. Saat ini belum ada soal aktif dari tim HR.',
            'values' => $values,
        ]);
        exit;
    }

    $valid = $fullName !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && strlen($whatsapp) >= 8
        && in_array($selectedRole, $config['role_options'], true);

    if (!$valid) {
        render('candidate/identity', [
            'page_title' => 'Asesmen Kandidat',
            'role_options' => $config['role_options'],
            'error_message' => 'Data belum lengkap atau format tidak valid.',
            'values' => $values,
        ]);
        exit;
    }

    $browserToken = (string) ($_COOKIE['disc_browser_token'] ?? '');
    if ($browserToken !== '') {
        $activeByBrowser = get_in_progress_candidate_by_browser_token($pdo, $browserToken);
        if ($activeByBrowser) {
            $_SESSION['candidate_id'] = (int) $activeByBrowser['id'];
            if (!empty($activeByBrowser['disc_completed_at'])) {
                redirect(route_path('/essay-test'));
            }
            redirect(route_path('/disc-test'));
        }
    }

    $emailKey = normalize_email($email);
    $waKey = normalize_whatsapp($whatsapp);
    $existing = find_candidate_by_identity($pdo, $emailKey, $waKey);

    if ($existing) {
        if ($existing['status'] === 'in_progress' && ($existing['browser_token'] ?? '') === $browserToken) {
            $_SESSION['candidate_id'] = (int) $existing['id'];
            if (!empty($existing['disc_completed_at'])) {
                redirect(route_path('/essay-test'));
            }
            redirect(route_path('/disc-test'));
        }

        render('candidate/identity', [
            'page_title' => 'Asesmen Kandidat',
            'role_options' => $config['role_options'],
            'error_message' => 'Kandidat dengan email/WA ini sudah pernah terdaftar dan tidak bisa mengikuti tes lebih dari satu kali.',
            'values' => $values,
        ]);
        exit;
    }

    $startedAt = now_iso();
    $deadlineAt = gmdate('c', time() + ($config['test_duration_minutes'] * 60));
    $candidateId = create_candidate($pdo, [
        'browser_token' => $browserToken,
        'full_name' => $fullName,
        'email' => $email,
        'email_key' => $emailKey,
        'whatsapp' => $whatsapp,
        'whatsapp_key' => $waKey,
        'selected_role' => $selectedRole,
        'started_at' => $startedAt,
        'deadline_at' => $deadlineAt,
    ]);
    log_integrity_event($pdo, $candidateId, 'disc', 'candidate_start', 'identity_submitted', [
        'selected_role' => $selectedRole,
    ]);

    $_SESSION['candidate_id'] = $candidateId;
    redirect(route_path('/disc-test'));
}

if ($method === 'GET' && $path === '/test') {
    redirect(route_path('/disc-test'));
}

if ($method === 'GET' && $path === '/disc-test') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }

    if ($candidate['status'] !== 'in_progress') {
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }
    if (!empty($candidate['disc_completed_at'])) {
        redirect(route_path('/essay-test'));
    }

    $questions = list_questions($pdo, false, null);
    $draftAnswers = parse_draft_answers_from_candidate($candidate);
    if (empty($questions)) {
        unset($_SESSION['candidate_id']);
        render('candidate/thank-you', [
            'page_title' => 'Tes Belum Tersedia',
            'candidate' => null,
        ]);
        exit;
    }

    if (strtotime((string) $candidate['deadline_at']) <= time()) {
        $discCompletedAt = now_iso();
        $essayDeadlineAt = gmdate('c', time() + ((int) ($config['essay_duration_minutes'] ?? 15) * 60));
        update_candidate_draft_answers($pdo, (int) $candidate['id'], $draftAnswers);
        mark_disc_completed($pdo, (int) $candidate['id'], $discCompletedAt, $essayDeadlineAt);
        log_integrity_event($pdo, (int) $candidate['id'], 'disc', 'phase_complete_timeout', 'disc_to_essay');
        redirect(route_path('/essay-test'));
    }

    render('candidate/test', [
        'page_title' => 'Asesmen',
        'candidate' => $candidate,
        'questions' => $questions,
        'draft_answers' => $draftAnswers,
        'deadline_at' => $candidate['deadline_at'],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/progress-save') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        json_response(['ok' => false, 'message' => 'Session kandidat tidak ditemukan'], 401);
    }

    if (($candidate['status'] ?? null) !== 'in_progress') {
        json_response(['ok' => false, 'message' => 'Tes sudah selesai'], 409);
    }

    $questions = list_questions($pdo, false, null);
    $answers = build_answers_from_payload($_POST, $questions);
    update_candidate_draft_answers($pdo, (int) $candidate['id'], $answers);

    json_response([
        'ok' => true,
        'saved_count' => count($answers),
    ]);
}

if ($method === 'POST' && $path === '/integrity-signal') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        json_response(['ok' => false, 'message' => 'Session kandidat tidak ditemukan'], 401);
    }

    if (($candidate['status'] ?? null) !== 'in_progress') {
        json_response(['ok' => false, 'message' => 'Tes sudah selesai'], 409);
    }

    $signal = trim((string) ($_POST['signal'] ?? ''));
    $count = (int) ($_POST['count'] ?? 1);
    $saved = add_candidate_integrity_signal($pdo, (int) $candidate['id'], $signal, $count);
    if (!$saved) {
        json_response(['ok' => false, 'message' => 'Signal tidak valid'], 422);
    }

    json_response(['ok' => true]);
}

if ($method === 'POST' && $path === '/integrity-event') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        json_response(['ok' => false, 'message' => 'Session kandidat tidak ditemukan'], 401);
    }
    if (($candidate['status'] ?? null) !== 'in_progress') {
        json_response(['ok' => false, 'message' => 'Tes sudah selesai'], 409);
    }

    $phase = trim((string) ($_POST['phase'] ?? 'unknown'));
    $eventType = trim((string) ($_POST['event_type'] ?? ''));
    $eventValue = trim((string) ($_POST['event_value'] ?? ''));
    $metaRaw = trim((string) ($_POST['meta_json'] ?? ''));
    $meta = [];
    if ($metaRaw !== '') {
        $decoded = json_decode($metaRaw, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    if ($eventType === '') {
        json_response(['ok' => false, 'message' => 'event_type wajib diisi'], 422);
    }

    log_integrity_event($pdo, (int) $candidate['id'], $phase, $eventType, $eventValue, $meta);
    json_response(['ok' => true]);
}

if ($method === 'POST' && $path === '/disc-submit') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }

    if ($candidate['status'] !== 'in_progress') {
        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }
    if (!empty($candidate['disc_completed_at'])) {
        redirect(route_path('/essay-test'));
    }

    $questions = list_questions($pdo, false, null);
    $postedAnswers = build_answers_from_payload($_POST, $questions);
    $draftAnswers = parse_draft_answers_from_candidate($candidate);
    $answers = merge_answers_with_fallback($postedAnswers, $draftAnswers);
    $submittedAt = now_iso();
    $expired = strtotime((string) $candidate['deadline_at']) <= time();

    $answeredCount = count($answers);
    $totalQuestions = count($questions);
    $minimumRequired = (int) ceil($totalQuestions * $config['min_completion_ratio']);

    if ($answeredCount !== $totalQuestions && !$expired) {
        update_candidate_draft_answers($pdo, (int) $candidate['id'], $answers);
        render('candidate/test', [
            'page_title' => 'Asesmen',
            'candidate' => $candidate,
            'questions' => $questions,
            'draft_answers' => $answers,
            'deadline_at' => $candidate['deadline_at'],
            'error_message' => 'Semua nomor harus diisi Most dan Least, dan tidak boleh memilih opsi yang sama.',
        ]);
        exit;
    }

    update_candidate_draft_answers($pdo, (int) $candidate['id'], $answers);
    $discCompletedAt = $submittedAt;
    $essayDeadlineAt = gmdate('c', time() + ((int) ($config['essay_duration_minutes'] ?? 15) * 60));
    mark_disc_completed($pdo, (int) $candidate['id'], $discCompletedAt, $essayDeadlineAt);
    log_integrity_event($pdo, (int) $candidate['id'], 'disc', 'phase_complete_submit', 'disc_to_essay');
    redirect(route_path('/essay-test'));
}

if ($method === 'GET' && $path === '/essay-test') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }
    if (($candidate['status'] ?? '') !== 'in_progress') {
        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }
    if (empty($candidate['disc_completed_at'])) {
        redirect(route_path('/disc-test'));
    }

    $group = essay_group_by_selected_role((string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = list_essay_questions($pdo, false, $group);
    $draftEssayAnswers = parse_draft_essay_answers_from_candidate($candidate);
    $essayDeadlineAt = (string) ($candidate['essay_deadline_at'] ?? '');
    if ($essayDeadlineAt === '') {
        $essayDeadlineAt = gmdate('c', time() + ((int) ($config['essay_duration_minutes'] ?? 15) * 60));
        mark_disc_completed($pdo, (int) $candidate['id'], (string) ($candidate['disc_completed_at'] ?? now_iso()), $essayDeadlineAt);
    }

    if (strtotime($essayDeadlineAt) <= time()) {
        $discAnswers = parse_draft_answers_from_candidate($candidate);
        $totalQuestions = count(list_questions($pdo, false, null));
        $answeredCount = count($discAnswers);
        $minimumRequired = (int) ceil($totalQuestions * $config['min_completion_ratio']);
        $baseEvaluation = evaluate_candidate(to_disc_payload($discAnswers), $candidate['selected_role'] ?? null);
        $evaluation = ($answeredCount < $minimumRequired)
            ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
            : $baseEvaluation;

        if (!empty($draftEssayAnswers)) {
            save_essay_answers($pdo, (int) $candidate['id'], $draftEssayAnswers);
        }

        save_submission($pdo, [
            'candidate_id' => (int) $candidate['id'],
            'answers' => $discAnswers,
            'submitted_at' => $essayDeadlineAt,
            'duration_seconds' => seconds_between((string) $candidate['started_at'], $essayDeadlineAt),
            'evaluation' => $evaluation,
            'force_status' => 'timeout_submitted',
        ]);

        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }

    render('candidate/essay-test', [
        'page_title' => 'Tes Esai',
        'candidate' => $candidate,
        'essay_questions' => $essayQuestions,
        'draft_essay_answers' => $draftEssayAnswers,
        'essay_deadline_at' => $essayDeadlineAt,
        'essay_group' => $group,
    ]);
    exit;
}

if ($method === 'POST' && $path === '/essay-progress-save') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        json_response(['ok' => false, 'message' => 'Session kandidat tidak ditemukan'], 401);
    }
    if (($candidate['status'] ?? null) !== 'in_progress' || empty($candidate['disc_completed_at'])) {
        json_response(['ok' => false, 'message' => 'Fase esai tidak aktif'], 409);
    }

    $group = essay_group_by_selected_role((string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = list_essay_questions($pdo, false, $group);
    $essayAnswers = build_essay_answers_from_payload($_POST, $essayQuestions);
    update_candidate_draft_essay_answers($pdo, (int) $candidate['id'], $essayAnswers);
    json_response(['ok' => true, 'saved_count' => count($essayAnswers)]);
}

if ($method === 'POST' && $path === '/typing-metrics-save') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        json_response(['ok' => false, 'message' => 'Session kandidat tidak ditemukan'], 401);
    }
    if (($candidate['status'] ?? null) !== 'in_progress' || empty($candidate['disc_completed_at'])) {
        json_response(['ok' => false, 'message' => 'Fase esai tidak aktif'], 409);
    }

    $metricsRaw = trim((string) ($_POST['metrics_json'] ?? ''));
    if ($metricsRaw === '') {
        json_response(['ok' => false, 'message' => 'metrics_json kosong'], 422);
    }

    $decoded = json_decode($metricsRaw, true);
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'message' => 'metrics_json tidak valid'], 422);
    }

    $saved = 0;
    foreach ($decoded as $questionId => $metric) {
        $qid = (int) $questionId;
        if ($qid <= 0 || !is_array($metric)) {
            continue;
        }
        upsert_essay_typing_metric($pdo, (int) $candidate['id'], $qid, $metric);
        $saved++;
    }

    json_response(['ok' => true, 'saved' => $saved]);
}

if ($method === 'POST' && ($path === '/essay-submit' || $path === '/submit')) {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }
    if (($candidate['status'] ?? '') !== 'in_progress') {
        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }
    if (empty($candidate['disc_completed_at'])) {
        redirect(route_path('/disc-test'));
    }

    $group = essay_group_by_selected_role((string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = list_essay_questions($pdo, false, $group);
    $postedEssay = build_essay_answers_from_payload($_POST, $essayQuestions);
    $draftEssay = parse_draft_essay_answers_from_candidate($candidate);
    $essayAnswers = array_merge($draftEssay, $postedEssay);

    $deadlineAt = (string) ($candidate['essay_deadline_at'] ?? '');
    $expired = $deadlineAt !== '' && strtotime($deadlineAt) <= time();
    if (!$expired && count($essayAnswers) < count($essayQuestions)) {
        update_candidate_draft_essay_answers($pdo, (int) $candidate['id'], $essayAnswers);
        render('candidate/essay-test', [
            'page_title' => 'Tes Esai',
            'candidate' => $candidate,
            'essay_questions' => $essayQuestions,
            'draft_essay_answers' => $essayAnswers,
            'essay_deadline_at' => $deadlineAt,
            'essay_group' => $group,
            'error_message' => 'Semua pertanyaan esai wajib diisi sebelum submit.',
        ]);
        exit;
    }

    if (!empty($essayAnswers)) {
        save_essay_answers($pdo, (int) $candidate['id'], $essayAnswers);
    }

    $discAnswers = parse_draft_answers_from_candidate($candidate);
    $totalQuestions = count(list_questions($pdo, false, null));
    $answeredCount = count($discAnswers);
    $minimumRequired = (int) ceil($totalQuestions * $config['min_completion_ratio']);
    $baseEvaluation = evaluate_candidate(to_disc_payload($discAnswers), $candidate['selected_role'] ?? null);
    $evaluation = ($answeredCount < $minimumRequired)
        ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
        : $baseEvaluation;

    $submittedAt = now_iso();
    $effectiveEnd = ($expired && $deadlineAt !== '') ? $deadlineAt : $submittedAt;
    save_submission($pdo, [
        'candidate_id' => (int) $candidate['id'],
        'answers' => $discAnswers,
        'submitted_at' => $effectiveEnd,
        'duration_seconds' => seconds_between((string) $candidate['started_at'], $effectiveEnd),
        'evaluation' => $evaluation,
        'force_status' => $expired ? 'timeout_submitted' : 'submitted',
    ]);
    log_integrity_event($pdo, (int) $candidate['id'], 'essay', $expired ? 'final_submit_timeout' : 'final_submit_manual', 'assessment_complete');

    unset($_SESSION['candidate_id']);
    redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
}

if ($method === 'GET' && $path === '/thank-you') {
    $candidateId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $candidate = $candidateId > 0 ? get_candidate_by_id($pdo, $candidateId) : null;

    render('candidate/thank-you', [
        'page_title' => 'Terima Kasih',
        'candidate' => $candidate,
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr') {
    if (is_hr_authenticated($config)) {
        redirect(route_path('/hr/dashboard'));
    }
    redirect(route_path('/hr/login'));
}

if ($method === 'GET' && $path === '/hr/login') {
    if (is_hr_authenticated($config)) {
        redirect(route_path('/hr/dashboard'));
    }

    render('hr/login', [
        'page_title' => 'Login HR',
        'values' => [],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/login') {
    if (is_hr_authenticated($config)) {
        redirect(route_path('/hr/dashboard'));
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        render('hr/login', [
            'page_title' => 'Login HR',
            'error_message' => 'Format email atau password tidak valid.',
            'values' => ['email' => $email],
        ]);
        exit;
    }

    $ip = client_ip();
    $state = is_login_blocked($pdo, $config, $ip);
    if ($state['blocked']) {
        render('hr/login', [
            'page_title' => 'Login HR',
            'error_message' => 'Terlalu banyak percobaan login. Coba lagi dalam ' . (int) $state['retry_after'] . ' detik.',
            'values' => ['email' => $email],
        ]);
        exit;
    }

    if (!verify_hr_credentials($config, $email, $password)) {
        register_login_failure($pdo, $config, $ip);
        render('hr/login', [
            'page_title' => 'Login HR',
            'error_message' => 'Email atau password HR salah.',
            'values' => ['email' => $email],
        ]);
        exit;
    }

    clear_login_attempt($pdo, $ip);
    $_SESSION['hr_auth'] = true;
    $_SESSION['hr_email'] = $email;

    redirect(route_path('/hr/dashboard'));
}

if ($method === 'POST' && $path === '/hr/logout') {
    unset($_SESSION['hr_auth'], $_SESSION['hr_email']);
    redirect(route_path('/hr/login'));
}

if ($method === 'GET' && $path === '/hr/dashboard') {
    require_hr_auth($config);

    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'role' => trim((string) ($_GET['role'] ?? '')),
        'recommendation' => trim((string) ($_GET['recommendation'] ?? '')),
    ];

    $candidates = list_candidates($pdo, $filters);
    foreach ($candidates as &$candidateRow) {
        $scores = extract_role_scores_from_candidate($candidateRow);
        $candidateRow['interview_recommendation'] = interview_recommendation_label($candidateRow, $scores);
    }
    unset($candidateRow);
    $stats = get_summary_stats($pdo, $filters);

    render('hr/dashboard', [
        'page_title' => 'Dashboard HR - DISC',
        'filters' => $filters,
        'role_options' => $config['role_options'],
        'recommendation_options' => recommendation_options(),
        'candidates' => $candidates,
        'stats' => $stats,
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr/api/candidates') {
    if (!is_hr_authenticated($config)) {
        json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
    }

    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'role' => trim((string) ($_GET['role'] ?? '')),
        'recommendation' => trim((string) ($_GET['recommendation'] ?? '')),
    ];

    $candidates = list_candidates($pdo, $filters);
    foreach ($candidates as &$candidateRow) {
        $scores = extract_role_scores_from_candidate($candidateRow);
        $candidateRow['interview_recommendation'] = interview_recommendation_label($candidateRow, $scores);
    }
    unset($candidateRow);
    $stats = get_summary_stats($pdo, $filters);

    json_response([
        'candidates' => $candidates,
        'stats' => $stats,
        'filters' => $filters,
    ]);
}

if ($method === 'POST' && $path === '/hr/api/refresh-timeouts') {
    if (!is_hr_authenticated($config)) {
        json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
    }

    $expiredCount = expire_overdue_candidates($pdo, $config);
    json_response([
        'ok' => true,
        'expired_count' => $expiredCount,
        'message' => $expiredCount > 0
            ? ("Berhasil update " . $expiredCount . " kandidat lewat batas waktu.")
            : 'Tidak ada kandidat yang perlu di-update.',
    ]);
}

if ($method === 'DELETE' && preg_match('#^/hr/api/candidates/(\d+)$#', $path, $m)) {
    if (!is_hr_authenticated($config)) {
        json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
    }
    verify_csrf_or_abort();

    $candidateId = (int) $m[1];
    $deleted = delete_candidate($pdo, $candidateId);
    if (!$deleted) {
        json_response(['ok' => false, 'message' => 'Candidate not found'], 404);
    }

    json_response(['ok' => true]);
}

if ($method === 'POST' && preg_match('#^/hr/candidates/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    delete_candidate($pdo, (int) $m[1]);
    redirect(route_path('/hr/dashboard'));
}

if ($method === 'POST' && preg_match('#^/hr/candidates/(\d+)/interview-checklist$#', $path, $m)) {
    require_hr_auth($config);

    $candidateId = (int) $m[1];
    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $finalDecision = trim((string) ($_POST['final_decision'] ?? ''));
    $allowedDecisions = ['Lanjut User Interview', 'Lanjut Trial', 'Hold (Butuh Verifikasi)', 'Tidak Lanjut'];
    if (!in_array($finalDecision, $allowedDecisions, true)) {
        $finalDecision = '';
    }

    upsert_interview_checklist($pdo, $candidateId, [
        'checklist' => normalize_interview_checklist_input($_POST),
        'final_decision' => $finalDecision,
        'strengths_notes' => trim((string) ($_POST['strengths_notes'] ?? '')),
        'risk_notes' => trim((string) ($_POST['risk_notes'] ?? '')),
        'placement_notes' => trim((string) ($_POST['placement_notes'] ?? '')),
    ]);

    $_SESSION['profile_flash_message'] = 'Checklist wawancara berhasil disimpan.';
    $_SESSION['profile_flash_type'] = 'success';
    redirect(route_path('/hr/candidates/' . $candidateId));
}

if ($method === 'POST' && preg_match('#^/hr/candidates/(\d+)/ai-evaluate$#', $path, $m)) {
    require_hr_auth($config);

    $candidateId = (int) $m[1];
    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $ready = ai_can_evaluate($config);
    if (!$ready['ok']) {
        $_SESSION['profile_flash_message'] = (string) ($ready['message'] ?? 'Evaluasi AI belum siap.');
        $_SESSION['profile_flash_type'] = 'error';
        redirect(route_path('/hr/candidates/' . $candidateId));
    }

    $payload = build_ai_payload_for_candidate($pdo, $candidate);
    $result = run_openai_deep_evaluation($config, $payload);
    if (!$result['ok']) {
        upsert_ai_evaluation($pdo, $candidateId, [
            'model' => (string) ($result['model'] ?? ($config['openai_model'] ?? 'gpt-5.4')),
            'status' => 'error',
            'error_message' => (string) ($result['error'] ?? 'AI evaluation failed'),
            'payload' => $payload,
            'raw_response' => $result['raw_response'] ?? [],
        ]);
        $_SESSION['profile_flash_message'] = 'Evaluasi AI gagal: ' . (string) ($result['error'] ?? 'Unknown error');
        $_SESSION['profile_flash_type'] = 'error';
        redirect(route_path('/hr/candidates/' . $candidateId));
    }

    $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
    upsert_ai_evaluation($pdo, $candidateId, [
        'model' => (string) ($result['model'] ?? ($config['openai_model'] ?? 'gpt-5.4')),
        'status' => 'success',
        'score_1_10' => (int) ($parsed['score_1_10'] ?? 0),
        'suggested_position' => (string) ($parsed['suggested_position'] ?? ''),
        'conclusion' => (string) ($parsed['conclusion'] ?? ''),
        'rationale' => (string) ($parsed['rationale'] ?? ''),
        'strengths' => is_array($parsed['strengths'] ?? null) ? $parsed['strengths'] : [],
        'risks' => is_array($parsed['risks'] ?? null) ? $parsed['risks'] : [],
        'follow_up_questions' => is_array($parsed['follow_up_questions'] ?? null) ? $parsed['follow_up_questions'] : [],
        'payload' => $payload,
        'raw_response' => $result['raw_response'] ?? [],
        'error_message' => '',
    ]);

    $_SESSION['profile_flash_message'] = 'Evaluasi AI berhasil di-generate.';
    $_SESSION['profile_flash_type'] = 'success';
    redirect(route_path('/hr/candidates/' . $candidateId));
}

if ($method === 'GET' && preg_match('#^/hr/candidates/(\d+)$#', $path, $m)) {
    require_hr_auth($config);

    $candidate = get_candidate_by_id($pdo, (int) $m[1]);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $answers = get_answers_for_candidate($pdo, (int) $candidate['id']);
    $questionRows = parse_candidate_profile_answers($answers);
    $essayRows = get_essay_answer_details_for_candidate($pdo, (int) $candidate['id']);
    $integrityEvents = list_integrity_events($pdo, (int) $candidate['id'], 250);
    $integrityEventsDisplay = array_map(static function (array $ev): array {
        $phase = (string) ($ev['phase'] ?? '');
        $type = (string) ($ev['event_type'] ?? '');
        $value = (string) ($ev['event_value'] ?? '');
        $ev['phase_label'] = integrity_phase_label($phase);
        $ev['event_type_label'] = integrity_event_label($type);
        $ev['event_value_label'] = integrity_event_value_label($value);
        return $ev;
    }, $integrityEvents);
    $typingMetricsRows = get_essay_typing_metrics_for_candidate($pdo, (int) $candidate['id']);
    $aiEvaluation = get_ai_evaluation_by_candidate($pdo, (int) $candidate['id']);

    $roleScoreData = [];
    $roleScoresJson = $candidate['role_scores_json'] ?? '';
    if (is_string($roleScoresJson) && $roleScoresJson !== '') {
        $decodedRoleScores = json_decode($roleScoresJson, true);
        if (is_array($decodedRoleScores)) {
            $roleScoreData = $decodedRoleScores;
        }
    }
    if (empty($roleScoreData)) {
        $roleScoreData = [
            'SERVER' => (int) ($candidate['score_server'] ?? 0),
            'MIXOLOGIST' => (int) ($candidate['score_beverage'] ?? 0),
            'COOK' => (int) ($candidate['score_cook'] ?? 0),
        ];
    }

    foreach ($roleScoreData as $k => $v) {
        $roleScoreData[$k] = normalize_role_score_10($v);
    }
    $interviewRecommendation = interview_recommendation_label($candidate, $roleScoreData);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingMetricsRows);

    $checklistRow = get_interview_checklist($pdo, (int) $candidate['id']);
    $savedChecklist = [];
    if ($checklistRow && is_string($checklistRow['checklist_json'] ?? null)) {
        $decodedChecklist = json_decode((string) $checklistRow['checklist_json'], true);
        if (is_array($decodedChecklist)) {
            $savedChecklist = $decodedChecklist;
        }
    }
    $flashMessage = $_SESSION['profile_flash_message'] ?? null;
    $flashType = $_SESSION['profile_flash_type'] ?? 'info';
    unset($_SESSION['profile_flash_message'], $_SESSION['profile_flash_type']);

    render('hr/profile', [
        'page_title' => 'Profil Kandidat #' . $candidate['id'],
        'candidate' => $candidate,
        'question_rows' => $questionRows,
        'essay_rows' => $essayRows,
        'integrity_events' => $integrityEventsDisplay,
        'typing_metrics_rows' => $typingMetricsRows,
        'ai_evaluation' => $aiEvaluation,
        'disc_data' => [
            'D' => (int) ($candidate['disc_d'] ?? 0),
            'I' => (int) ($candidate['disc_i'] ?? 0),
            'S' => (int) ($candidate['disc_s'] ?? 0),
            'C' => (int) ($candidate['disc_c'] ?? 0),
        ],
        'role_score_data' => $roleScoreData,
        'interview_recommendation' => $interviewRecommendation,
        'integrity_risk' => $integrityRisk,
        'typing_risk' => $typingRisk,
        'interview_sections' => interview_checklist_sections(),
        'interview_saved_checklist' => $savedChecklist,
        'interview_saved_final_decision' => (string) ($checklistRow['final_decision'] ?? ''),
        'interview_saved_strengths_notes' => (string) ($checklistRow['strengths_notes'] ?? ''),
        'interview_saved_risk_notes' => (string) ($checklistRow['risk_notes'] ?? ''),
        'interview_saved_placement_notes' => (string) ($checklistRow['placement_notes'] ?? ''),
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'info',
    ]);
    exit;
}

if ($method === 'GET' && preg_match('#^/hr/candidates/(\d+)/export/answers\.csv$#', $path, $m)) {
    require_hr_auth($config);

    $candidateId = (int) $m[1];
    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $rows = get_answer_details_for_candidate_export($pdo, $candidateId);
    $essayRows = get_essay_answer_details_for_candidate($pdo, $candidateId);
    $typingRows = get_essay_typing_metrics_for_candidate($pdo, $candidateId);
    $integrityEventsRaw = list_integrity_events($pdo, $candidateId, 300);
    $integrityEvents = array_map(static function (array $ev): array {
        $phase = (string) ($ev['phase'] ?? '');
        $type = (string) ($ev['event_type'] ?? '');
        $value = (string) ($ev['event_value'] ?? '');
        $ev['phase_label'] = integrity_phase_label($phase);
        $ev['event_type_label'] = integrity_event_label($type);
        $ev['event_value_label'] = integrity_event_value_label($value);
        return $ev;
    }, $integrityEventsRaw);
    $roleScores = extract_role_scores_from_candidate($candidate);
    $interviewRecommendation = interview_recommendation_label($candidate, $roleScores);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingRows);
    $checklistRow = get_interview_checklist($pdo, $candidateId);

    $checkedItems = [];
    if ($checklistRow && is_string($checklistRow['checklist_json'] ?? null)) {
        $decodedChecklist = json_decode((string) $checklistRow['checklist_json'], true);
        if (is_array($decodedChecklist)) {
            foreach (interview_checklist_sections() as $items) {
                foreach ($items as $key => $label) {
                    if (!empty($decodedChecklist[$key])) {
                        $checkedItems[] = (string) $label;
                    }
                }
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="candidate-answers-' . $candidateId . '-' . time() . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['RINGKASAN KANDIDAT']);
    fputcsv($out, [
        'Candidate ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Rekomendasi Sistem', 'Kelayakan Wawancara',
        'Status', 'Mulai', 'Selesai', 'Durasi (detik)', 'DISC D', 'DISC I', 'DISC S', 'DISC C',
        'Integrity Risk', 'Typing Risk', 'Final Keputusan HR',
    ]);
    fputcsv($out, [
        (int) ($candidate['id'] ?? 0),
        (string) ($candidate['full_name'] ?? ''),
        (string) ($candidate['email'] ?? ''),
        (string) ($candidate['whatsapp'] ?? ''),
        (string) ($candidate['selected_role'] ?? ''),
        map_recommendation_label($candidate['recommendation'] ?? null),
        $interviewRecommendation,
        (string) ($candidate['status'] ?? ''),
        format_date_id((string) ($candidate['started_at'] ?? '')),
        format_date_id((string) ($candidate['submitted_at'] ?? '')),
        (int) ($candidate['duration_seconds'] ?? 0),
        (int) ($candidate['disc_d'] ?? 0),
        (int) ($candidate['disc_i'] ?? 0),
        (int) ($candidate['disc_s'] ?? 0),
        (int) ($candidate['disc_c'] ?? 0),
        (string) ($integrityRisk['level'] ?? 'Low') . ' (tab=' . (int) ($integrityRisk['tab_switches'] ?? 0) . ', paste=' . (int) ($integrityRisk['paste_count'] ?? 0) . ')',
        (string) ($typingRisk['level'] ?? 'Low') . ' (score=' . (int) ($typingRisk['score'] ?? 0) . ')',
        (string) ($checklistRow['final_decision'] ?? '-'),
    ]);

    fputcsv($out, ['']);
    fputcsv($out, ['JAWABAN DISC']);
    fputcsv($out, [
        'Candidate ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Rekomendasi', 'Status',
        'No Soal', 'Role Soal', 'Most', 'Most Text', 'Least', 'Least Text',
    ]);
    foreach ($rows as $row) {
        $mostCode = (string) ($row['most_code'] ?? '');
        $leastCode = (string) ($row['least_code'] ?? '');
        fputcsv($out, [
            $row['candidate_id'],
            $row['full_name'],
            $row['email'],
            $row['whatsapp'],
            $row['selected_role'],
            map_recommendation_label($row['recommendation'] ?? null),
            $row['status'],
            (int) ($row['question_order'] ?? 0),
            $row['question_role'] ?? '-',
            $mostCode !== '' ? $mostCode : '-',
            answer_option_text($row, $mostCode),
            $leastCode !== '' ? $leastCode : '-',
            answer_option_text($row, $leastCode),
        ]);
    }

    fputcsv($out, ['']);
    fputcsv($out, ['JAWABAN ESAI']);
    fputcsv($out, ['No', 'Kelompok', 'Pertanyaan', 'Jawaban']);
    foreach ($essayRows as $row) {
        fputcsv($out, [
            (int) ($row['question_order'] ?? 0),
            (string) ($row['role_group'] ?? '-'),
            (string) ($row['question_text'] ?? ''),
            (string) ($row['answer_text'] ?? ''),
        ]);
    }

    fputcsv($out, ['']);
    fputcsv($out, ['EVENT TIMELINE']);
    fputcsv($out, ['Waktu', 'Fase', 'Event', 'Keterangan']);
    foreach ($integrityEvents as $ev) {
        fputcsv($out, [
            format_date_id((string) ($ev['created_at'] ?? '')),
            (string) ($ev['phase_label'] ?? '-'),
            (string) ($ev['event_type_label'] ?? '-'),
            (string) ($ev['event_value_label'] ?? '-'),
        ]);
    }

    fputcsv($out, ['']);
    fputcsv($out, ['TYPING METRICS']);
    fputcsv($out, ['No', 'Keystrokes', 'Input Events', 'Paste', 'Chars', 'Active (detik)', 'Last Input']);
    foreach ($typingRows as $row) {
        fputcsv($out, [
            (int) ($row['question_order'] ?? 0),
            (int) ($row['keystrokes'] ?? 0),
            (int) ($row['input_events'] ?? 0),
            (int) ($row['paste_count'] ?? 0),
            (int) ($row['total_chars'] ?? 0),
            round(((int) ($row['active_ms'] ?? 0)) / 1000, 1),
            format_date_id((string) ($row['last_input_at'] ?? '')),
        ]);
    }

    fputcsv($out, ['']);
    fputcsv($out, ['KETERANGAN LAINNYA']);
    fputcsv($out, ['Alasan Rekomendasi', (string) ($candidate['reason'] ?? '-')]);
    fputcsv($out, ['Catatan Kekuatan HR', (string) ($checklistRow['strengths_notes'] ?? '-')]);
    fputcsv($out, ['Catatan Risiko HR', (string) ($checklistRow['risk_notes'] ?? '-')]);
    fputcsv($out, ['Saran Penempatan HR', (string) ($checklistRow['placement_notes'] ?? '-')]);
    fputcsv($out, ['Checklist Interview (checked)', !empty($checkedItems) ? implode(' | ', $checkedItems) : '-']);
    fclose($out);
    exit;
}

if ($method === 'GET' && preg_match('#^/hr/candidates/(\d+)/export/answers\.pdf$#', $path, $m)) {
    require_hr_auth($config);

    $candidateId = (int) $m[1];
    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $rows = get_answer_details_for_candidate_export($pdo, $candidateId);
    $essayRows = get_essay_answer_details_for_candidate($pdo, $candidateId);
    $typingRows = get_essay_typing_metrics_for_candidate($pdo, $candidateId);
    $integrityEventsRaw = list_integrity_events($pdo, $candidateId, 300);
    $integrityEvents = array_map(static function (array $ev): array {
        $phase = (string) ($ev['phase'] ?? '');
        $type = (string) ($ev['event_type'] ?? '');
        $value = (string) ($ev['event_value'] ?? '');
        $ev['phase_label'] = integrity_phase_label($phase);
        $ev['event_type_label'] = integrity_event_label($type);
        $ev['event_value_label'] = integrity_event_value_label($value);
        return $ev;
    }, $integrityEventsRaw);
    $roleScores = extract_role_scores_from_candidate($candidate);
    $interviewRecommendation = interview_recommendation_label($candidate, $roleScores);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingRows);
    $checklistRow = get_interview_checklist($pdo, $candidateId);
    $checkedItems = [];
    if ($checklistRow && is_string($checklistRow['checklist_json'] ?? null)) {
        $decodedChecklist = json_decode((string) $checklistRow['checklist_json'], true);
        if (is_array($decodedChecklist)) {
            foreach (interview_checklist_sections() as $items) {
                foreach ($items as $key => $label) {
                    if (!empty($decodedChecklist[$key])) {
                        $checkedItems[] = (string) $label;
                    }
                }
            }
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Jawaban Kandidat #' . h((string) $candidateId) . '</title><style>body{font-family:Arial,sans-serif;padding:20px;}h2{margin:0 0 6px;}h3{margin:18px 0 8px;}p{margin:4px 0 12px;}table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:10px}th,td{border:1px solid #ddd;padding:6px;vertical-align:top;text-align:left;}th{background:#f2f2f2}</style></head><body>';
    echo '<h2>Jawaban Kandidat #' . h((string) $candidateId) . '</h2>';
    echo '<p><strong>Nama:</strong> ' . h((string) ($candidate['full_name'] ?? '-')) . '<br>';
    echo '<strong>Email:</strong> ' . h((string) ($candidate['email'] ?? '-')) . '<br>';
    echo '<strong>Role Dipilih:</strong> ' . h((string) ($candidate['selected_role'] ?? '-')) . '<br>';
    echo '<strong>Rekomendasi:</strong> ' . h(map_recommendation_label($candidate['recommendation'] ?? null)) . '<br>';
    echo '<strong>Kelayakan Wawancara:</strong> ' . h($interviewRecommendation) . '<br>';
    echo '<strong>Status:</strong> ' . h((string) ($candidate['status'] ?? '-')) . '<br>';
    echo '<strong>Integrity Risk:</strong> ' . h((string) ($integrityRisk['level'] ?? 'Low')) . ' (tab=' . h((string) ((int) ($integrityRisk['tab_switches'] ?? 0))) . ', paste=' . h((string) ((int) ($integrityRisk['paste_count'] ?? 0))) . ')<br>';
    echo '<strong>Typing Risk:</strong> ' . h((string) ($typingRisk['level'] ?? 'Low')) . ' (score=' . h((string) ((int) ($typingRisk['score'] ?? 0))) . ')</p>';
    echo '<p>Gunakan menu browser: Print -> Save as PDF.</p>';

    echo '<h3>Jawaban DISC</h3>';
    echo '<table><thead><tr><th>No</th><th>Role Soal</th><th>Most</th><th>Least</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $mostCode = (string) ($row['most_code'] ?? '');
        $leastCode = (string) ($row['least_code'] ?? '');
        echo '<tr>'
            . '<td>' . h((string) ((int) ($row['question_order'] ?? 0))) . '</td>'
            . '<td>' . h((string) ($row['question_role'] ?? '-')) . '</td>'
            . '<td><strong>' . h($mostCode !== '' ? $mostCode : '-') . '</strong><br>' . h(answer_option_text($row, $mostCode)) . '</td>'
            . '<td><strong>' . h($leastCode !== '' ? $leastCode : '-') . '</strong><br>' . h(answer_option_text($row, $leastCode)) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Jawaban Esai</h3>';
    echo '<table><thead><tr><th>No</th><th>Kelompok</th><th>Pertanyaan</th><th>Jawaban</th></tr></thead><tbody>';
    foreach ($essayRows as $row) {
        echo '<tr>'
            . '<td>' . h((string) ((int) ($row['question_order'] ?? 0))) . '</td>'
            . '<td>' . h((string) ($row['role_group'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($row['question_text'] ?? '')) . '</td>'
            . '<td>' . nl2br(h((string) ($row['answer_text'] ?? ''))) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Event Timeline</h3>';
    echo '<table><thead><tr><th>Waktu</th><th>Fase</th><th>Event</th><th>Keterangan</th></tr></thead><tbody>';
    foreach ($integrityEvents as $ev) {
        echo '<tr>'
            . '<td>' . h(format_date_id((string) ($ev['created_at'] ?? ''))) . '</td>'
            . '<td>' . h((string) ($ev['phase_label'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($ev['event_type_label'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($ev['event_value_label'] ?? '-')) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Typing Metrics</h3>';
    echo '<table><thead><tr><th>No</th><th>Keystrokes</th><th>Input</th><th>Paste</th><th>Chars</th><th>Active (detik)</th></tr></thead><tbody>';
    foreach ($typingRows as $row) {
        echo '<tr>'
            . '<td>' . h((string) ((int) ($row['question_order'] ?? 0))) . '</td>'
            . '<td>' . h((string) ((int) ($row['keystrokes'] ?? 0))) . '</td>'
            . '<td>' . h((string) ((int) ($row['input_events'] ?? 0))) . '</td>'
            . '<td>' . h((string) ((int) ($row['paste_count'] ?? 0))) . '</td>'
            . '<td>' . h((string) ((int) ($row['total_chars'] ?? 0))) . '</td>'
            . '<td>' . h((string) round(((int) ($row['active_ms'] ?? 0)) / 1000, 1)) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Keterangan Lainnya</h3>';
    echo '<p><strong>Alasan Rekomendasi:</strong> ' . h((string) ($candidate['reason'] ?? '-')) . '<br>';
    echo '<strong>Final Keputusan HR:</strong> ' . h((string) ($checklistRow['final_decision'] ?? '-')) . '<br>';
    echo '<strong>Catatan Kekuatan HR:</strong> ' . h((string) ($checklistRow['strengths_notes'] ?? '-')) . '<br>';
    echo '<strong>Catatan Risiko HR:</strong> ' . h((string) ($checklistRow['risk_notes'] ?? '-')) . '<br>';
    echo '<strong>Saran Penempatan HR:</strong> ' . h((string) ($checklistRow['placement_notes'] ?? '-')) . '<br>';
    echo '<strong>Checklist Interview (checked):</strong> ' . h(!empty($checkedItems) ? implode(' | ', $checkedItems) : '-') . '</p>';
    echo '</body></html>';
    exit;
}

if ($method === 'GET' && $path === '/hr/essay-questions') {
    require_hr_auth($config);
    $essayGroupOptions = essay_group_options();
    $groupFilter = trim((string) ($_GET['group'] ?? ''));
    if ($groupFilter !== '' && !in_array($groupFilter, $essayGroupOptions, true)) {
        $groupFilter = '';
    }

    $flashMessage = $_SESSION['essay_questions_flash_message'] ?? null;
    $flashType = $_SESSION['essay_questions_flash_type'] ?? 'info';
    unset($_SESSION['essay_questions_flash_message'], $_SESSION['essay_questions_flash_type']);

    render('hr/essay-questions', [
        'page_title' => 'Kelola Soal Esai',
        'essay_questions' => list_essay_questions($pdo, true, $groupFilter !== '' ? $groupFilter : null),
        'essay_group_options' => $essayGroupOptions,
        'group_filter' => $groupFilter,
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'info',
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr/essay-questions/new') {
    require_hr_auth($config);
    $essayGroupOptions = essay_group_options();
    $group = trim((string) ($_GET['group'] ?? 'Manager'));
    if (!in_array($group, $essayGroupOptions, true)) {
        $group = 'Manager';
    }

    render('hr/essay-question-form', [
        'page_title' => 'Tambah Soal Esai',
        'form_title' => 'Tambah Soal Esai',
        'action_url' => route_path('/hr/essay-questions/new'),
        'essay_group_options' => $essayGroupOptions,
        'values' => [
            'role_group' => $group,
            'order' => get_next_essay_question_order($pdo, $group),
            'question_text' => '',
            'guidance_text' => '',
            'is_active' => true,
        ],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/essay-questions/new') {
    require_hr_auth($config);
    $essayGroupOptions = essay_group_options();

    $payload = [
        'role_group' => trim((string) ($_POST['role_group'] ?? '')),
        'order' => (int) ($_POST['order'] ?? 0),
        'question_text' => trim((string) ($_POST['question_text'] ?? '')),
        'guidance_text' => trim((string) ($_POST['guidance_text'] ?? '')),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = in_array($payload['role_group'], $essayGroupOptions, true)
        && $payload['order'] > 0
        && mb_strlen($payload['question_text']) >= 10;

    if (!$valid) {
        render('hr/essay-question-form', [
            'page_title' => 'Tambah Soal Esai',
            'form_title' => 'Tambah Soal Esai',
            'action_url' => route_path('/hr/essay-questions/new'),
            'essay_group_options' => $essayGroupOptions,
            'values' => $payload,
            'error_message' => 'Kelompok role wajib valid, urutan > 0, dan pertanyaan minimal 10 karakter.',
        ]);
        exit;
    }

    create_essay_question($pdo, $payload);
    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil ditambahkan.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'GET' && preg_match('#^/hr/essay-questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $essayGroupOptions = essay_group_options();

    $row = get_essay_question_by_id($pdo, (int) $m[1]);
    if (!$row) {
        http_response_code(404);
        echo 'Essay question not found';
        exit;
    }

    render('hr/essay-question-form', [
        'page_title' => 'Edit Soal Esai #' . $row['id'],
        'form_title' => 'Edit Soal Esai #' . $row['id'],
        'action_url' => route_path('/hr/essay-questions/' . $row['id'] . '/edit'),
        'essay_group_options' => $essayGroupOptions,
        'values' => [
            'role_group' => (string) ($row['role_group'] ?? 'Manager'),
            'order' => (int) ($row['question_order'] ?? 0),
            'question_text' => (string) ($row['question_text'] ?? ''),
            'guidance_text' => (string) ($row['guidance_text'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ],
    ]);
    exit;
}

if ($method === 'POST' && preg_match('#^/hr/essay-questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $essayGroupOptions = essay_group_options();

    $payload = [
        'role_group' => trim((string) ($_POST['role_group'] ?? '')),
        'order' => (int) ($_POST['order'] ?? 0),
        'question_text' => trim((string) ($_POST['question_text'] ?? '')),
        'guidance_text' => trim((string) ($_POST['guidance_text'] ?? '')),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = in_array($payload['role_group'], $essayGroupOptions, true)
        && $payload['order'] > 0
        && mb_strlen($payload['question_text']) >= 10;

    if (!$valid) {
        render('hr/essay-question-form', [
            'page_title' => 'Edit Soal Esai #' . (int) $m[1],
            'form_title' => 'Edit Soal Esai #' . (int) $m[1],
            'action_url' => route_path('/hr/essay-questions/' . (int) $m[1] . '/edit'),
            'essay_group_options' => $essayGroupOptions,
            'values' => $payload,
            'error_message' => 'Kelompok role wajib valid, urutan > 0, dan pertanyaan minimal 10 karakter.',
        ]);
        exit;
    }

    update_essay_question($pdo, (int) $m[1], $payload);
    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil diperbarui.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'POST' && preg_match('#^/hr/essay-questions/(\d+)/toggle-active$#', $path, $m)) {
    require_hr_auth($config);
    toggle_essay_question_active($pdo, (int) $m[1]);
    $_SESSION['essay_questions_flash_message'] = 'Status soal esai berhasil diubah.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'POST' && preg_match('#^/hr/essay-questions/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    delete_essay_question($pdo, (int) $m[1]);
    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil dihapus.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'GET' && $path === '/hr/questions') {
    require_hr_auth($config);
    $questionRoleOptions = question_role_options();

    $flashMessage = $_SESSION['questions_flash_message'] ?? null;
    $flashType = $_SESSION['questions_flash_type'] ?? 'info';
    unset($_SESSION['questions_flash_message'], $_SESSION['questions_flash_type']);

    $bulkPreviewRows = $_SESSION['questions_bulk_preview_rows'] ?? [];
    $bulkPreviewMode = $_SESSION['questions_bulk_preview_mode'] ?? 'append';
    $bulkPreviewSummary = $_SESSION['questions_bulk_preview_summary'] ?? [];
    $bulkPreviewTotal = $_SESSION['questions_bulk_preview_total'] ?? 0;
    $bulkErrorCount = isset($_SESSION['questions_bulk_errors']) && is_array($_SESSION['questions_bulk_errors'])
        ? count($_SESSION['questions_bulk_errors'])
        : 0;

    render('hr/questions', [
        'page_title' => 'Kelola Soal DISC',
        'question_bank' => list_questions($pdo, true, null),
        'role_options' => $questionRoleOptions,
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'info',
        'bulk_preview_rows' => is_array($bulkPreviewRows) ? array_slice($bulkPreviewRows, 0, 10) : [],
        'bulk_preview_mode' => is_string($bulkPreviewMode) ? $bulkPreviewMode : 'append',
        'bulk_preview_summary' => is_array($bulkPreviewSummary) ? $bulkPreviewSummary : [],
        'bulk_preview_total' => (int) $bulkPreviewTotal,
        'bulk_error_count' => $bulkErrorCount,
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr/questions/template.csv') {
    require_hr_auth($config);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template-soal-disc.csv"');
    echo build_bulk_question_template_csv(question_role_options());
    exit;
}

if ($method === 'GET' && $path === '/hr/questions/bulk-errors.csv') {
    require_hr_auth($config);

    $errors = $_SESSION['questions_bulk_errors'] ?? [];
    if (!is_array($errors) || empty($errors)) {
        http_response_code(404);
        echo 'Tidak ada error preview untuk diunduh.';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bulk-import-errors-' . time() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Pesan Error']);
    foreach (array_values($errors) as $idx => $message) {
        fputcsv($out, [$idx + 1, (string) $message]);
    }
    fclose($out);
    exit;
}

if ($method === 'POST' && $path === '/hr/questions/bulk-preview-clear') {
    require_hr_auth($config);
    unset(
        $_SESSION['questions_bulk_preview_rows'],
        $_SESSION['questions_bulk_preview_mode'],
        $_SESSION['questions_bulk_preview_summary'],
        $_SESSION['questions_bulk_preview_total'],
        $_SESSION['questions_bulk_errors']
    );
    $_SESSION['questions_flash_message'] = 'Preview import telah dibersihkan.';
    $_SESSION['questions_flash_type'] = 'success';
    redirect(route_path('/hr/questions'));
}

if ($method === 'POST' && $path === '/hr/questions/bulk-preview') {
    require_hr_auth($config);
    $questionRoleOptions = question_role_options();

    $csvRaw = extract_bulk_csv_input();

    if ($csvRaw === '') {
        unset($_SESSION['questions_bulk_preview_rows'], $_SESSION['questions_bulk_preview_mode'], $_SESSION['questions_bulk_preview_summary'], $_SESSION['questions_bulk_preview_total']);
        $_SESSION['questions_bulk_errors'] = ['Import gagal: isi CSV kosong. Tempel CSV atau upload file .csv.'];
        $_SESSION['questions_flash_message'] = 'Import gagal: isi CSV kosong. Tempel CSV atau upload file .csv.';
        $_SESSION['questions_flash_type'] = 'error';
        redirect(route_path('/hr/questions'));
    }

    $importMode = trim((string) ($_POST['import_mode'] ?? 'append'));
    if (!in_array($importMode, ['append', 'replace'], true)) {
        $importMode = 'append';
    }

    $parsed = parse_bulk_questions_csv($csvRaw, $questionRoleOptions);
    $rows = $parsed['rows'] ?? [];
    $errors = $parsed['errors'] ?? [];
    $existingKeys = get_question_role_order_keys($pdo);
    $errors = array_merge($errors, validate_bulk_questions_rows($rows, $existingKeys, $importMode));

    if (!empty($errors)) {
        unset($_SESSION['questions_bulk_preview_rows'], $_SESSION['questions_bulk_preview_mode'], $_SESSION['questions_bulk_preview_summary'], $_SESSION['questions_bulk_preview_total']);
        $_SESSION['questions_bulk_errors'] = $errors;
        $firstFive = array_slice($errors, 0, 5);
        $_SESSION['questions_flash_message'] = 'Preview gagal: ' . implode(' | ', $firstFive);
        $_SESSION['questions_flash_type'] = 'error';
        redirect(route_path('/hr/questions'));
    }

    $_SESSION['questions_bulk_preview_rows'] = $rows;
    $_SESSION['questions_bulk_preview_mode'] = $importMode;
    $_SESSION['questions_bulk_preview_summary'] = summarize_bulk_questions_by_role($rows);
    $_SESSION['questions_bulk_preview_total'] = count($rows);
    unset($_SESSION['questions_bulk_errors']);
    $_SESSION['questions_flash_message'] = 'Preview siap. Silakan cek data lalu klik Konfirmasi Import.';
    $_SESSION['questions_flash_type'] = 'success';
    redirect(route_path('/hr/questions'));
}

if ($method === 'POST' && $path === '/hr/questions/bulk-import-confirm') {
    require_hr_auth($config);

    $rows = $_SESSION['questions_bulk_preview_rows'] ?? [];
    $importMode = $_SESSION['questions_bulk_preview_mode'] ?? 'append';
    if (!is_array($rows) || empty($rows)) {
        $_SESSION['questions_flash_message'] = 'Tidak ada data preview untuk diimport. Jalankan preview dulu.';
        $_SESSION['questions_flash_type'] = 'error';
        redirect(route_path('/hr/questions'));
    }

    if (!in_array($importMode, ['append', 'replace'], true)) {
        $importMode = 'append';
    }

    $existingKeys = get_question_role_order_keys($pdo);
    $errors = validate_bulk_questions_rows($rows, $existingKeys, $importMode);
    if (!empty($errors)) {
        unset($_SESSION['questions_bulk_preview_rows'], $_SESSION['questions_bulk_preview_mode'], $_SESSION['questions_bulk_preview_summary'], $_SESSION['questions_bulk_preview_total']);
        $_SESSION['questions_bulk_errors'] = $errors;
        $firstFive = array_slice($errors, 0, 5);
        $_SESSION['questions_flash_message'] = 'Import dibatalkan: ' . implode(' | ', $firstFive);
        $_SESSION['questions_flash_type'] = 'error';
        redirect(route_path('/hr/questions'));
    }

    $replaceExisting = ($importMode === 'replace');
    $inserted = create_questions_bulk($pdo, $rows, $replaceExisting);
    $summary = summarize_bulk_questions_by_role($rows);
    $parts = [];
    foreach ($summary as $role => $count) {
        $parts[] = "{$role}: {$count}";
    }

    unset($_SESSION['questions_bulk_preview_rows'], $_SESSION['questions_bulk_preview_mode'], $_SESSION['questions_bulk_preview_summary'], $_SESSION['questions_bulk_preview_total']);
    unset($_SESSION['questions_bulk_errors']);
    $_SESSION['questions_flash_message'] = "Berhasil import {$inserted} soal (" . implode(', ', $parts) . ').'
        . ($replaceExisting ? ' Mode: replace semua soal.' : ' Mode: append.');
    $_SESSION['questions_flash_type'] = 'success';
    redirect(route_path('/hr/questions'));
}

if ($method === 'GET' && $path === '/hr/questions/new') {
    require_hr_auth($config);

    render('hr/question-form', [
        'page_title' => 'Tambah Soal DISC',
        'form_title' => 'Tambah Soal',
        'action_url' => route_path('/hr/questions/new'),
        'role_options' => question_role_options(),
        'values' => [
            'role_key' => 'All',
            'order' => get_next_question_order($pdo, null),
            'disc_a' => 'D',
            'disc_b' => 'I',
            'disc_c' => 'S',
            'disc_d' => 'C',
            'is_active' => true,
        ],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/questions/new') {
    require_hr_auth($config);
    $questionRoleOptions = question_role_options();

    $payload = [
        'role_key' => 'All',
        'order' => (int) ($_POST['order'] ?? 0),
        'option_a' => trim((string) ($_POST['option_a'] ?? '')),
        'option_b' => trim((string) ($_POST['option_b'] ?? '')),
        'option_c' => trim((string) ($_POST['option_c'] ?? '')),
        'option_d' => trim((string) ($_POST['option_d'] ?? '')),
        'disc_a' => strtoupper(trim((string) ($_POST['disc_a'] ?? 'D'))),
        'disc_b' => strtoupper(trim((string) ($_POST['disc_b'] ?? 'I'))),
        'disc_c' => strtoupper(trim((string) ($_POST['disc_c'] ?? 'S'))),
        'disc_d' => strtoupper(trim((string) ($_POST['disc_d'] ?? 'C'))),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = in_array($payload['role_key'], $questionRoleOptions, true)
        && $payload['order'] > 0
        && mb_strlen($payload['option_a']) >= 3
        && mb_strlen($payload['option_b']) >= 3
        && mb_strlen($payload['option_c']) >= 3
        && mb_strlen($payload['option_d']) >= 3
        && in_array($payload['disc_a'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_b'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_c'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_d'], ['D', 'I', 'S', 'C'], true);

    if (!$valid) {
        render('hr/question-form', [
            'page_title' => 'Tambah Soal DISC',
            'form_title' => 'Tambah Soal',
            'action_url' => route_path('/hr/questions/new'),
            'role_options' => $questionRoleOptions,
            'values' => $payload,
            'error_message' => 'Semua opsi wajib minimal 3 karakter dan mapping DISC harus D/I/S/C.',
        ]);
        exit;
    }

    create_question($pdo, $payload);
    redirect(route_path('/hr/questions'));
}

if ($method === 'GET' && preg_match('#^/hr/questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);

    $row = get_question_by_id($pdo, (int) $m[1]);
    if (!$row) {
        http_response_code(404);
        echo 'Question not found';
        exit;
    }

    render('hr/question-form', [
        'page_title' => 'Edit Soal #' . $row['id'],
        'form_title' => 'Edit Soal #' . $row['id'],
        'action_url' => route_path('/hr/questions/' . $row['id'] . '/edit'),
        'role_options' => question_role_options(),
        'values' => [
            'role_key' => 'All',
            'order' => (int) $row['question_order'],
            'option_a' => $row['option_a'],
            'option_b' => $row['option_b'],
            'option_c' => $row['option_c'],
            'option_d' => $row['option_d'],
            'disc_a' => strtoupper((string) ($row['disc_a'] ?? 'D')),
            'disc_b' => strtoupper((string) ($row['disc_b'] ?? 'I')),
            'disc_c' => strtoupper((string) ($row['disc_c'] ?? 'S')),
            'disc_d' => strtoupper((string) ($row['disc_d'] ?? 'C')),
            'is_active' => ((int) $row['is_active']) === 1,
        ],
    ]);
    exit;
}

if ($method === 'POST' && preg_match('#^/hr/questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $questionRoleOptions = question_role_options();

    $payload = [
        'role_key' => 'All',
        'order' => (int) ($_POST['order'] ?? 0),
        'option_a' => trim((string) ($_POST['option_a'] ?? '')),
        'option_b' => trim((string) ($_POST['option_b'] ?? '')),
        'option_c' => trim((string) ($_POST['option_c'] ?? '')),
        'option_d' => trim((string) ($_POST['option_d'] ?? '')),
        'disc_a' => strtoupper(trim((string) ($_POST['disc_a'] ?? 'D'))),
        'disc_b' => strtoupper(trim((string) ($_POST['disc_b'] ?? 'I'))),
        'disc_c' => strtoupper(trim((string) ($_POST['disc_c'] ?? 'S'))),
        'disc_d' => strtoupper(trim((string) ($_POST['disc_d'] ?? 'C'))),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = in_array($payload['role_key'], $questionRoleOptions, true)
        && $payload['order'] > 0
        && mb_strlen($payload['option_a']) >= 3
        && mb_strlen($payload['option_b']) >= 3
        && mb_strlen($payload['option_c']) >= 3
        && mb_strlen($payload['option_d']) >= 3
        && in_array($payload['disc_a'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_b'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_c'], ['D', 'I', 'S', 'C'], true)
        && in_array($payload['disc_d'], ['D', 'I', 'S', 'C'], true);

    if (!$valid) {
        render('hr/question-form', [
            'page_title' => 'Edit Soal #' . (int) $m[1],
            'form_title' => 'Edit Soal #' . (int) $m[1],
            'action_url' => route_path('/hr/questions/' . (int) $m[1] . '/edit'),
            'role_options' => $questionRoleOptions,
            'values' => $payload,
            'error_message' => 'Semua opsi wajib minimal 3 karakter dan mapping DISC harus D/I/S/C.',
        ]);
        exit;
    }

    update_question($pdo, (int) $m[1], $payload);
    redirect(route_path('/hr/questions'));
}

if ($method === 'POST' && preg_match('#^/hr/questions/(\d+)/toggle-active$#', $path, $m)) {
    require_hr_auth($config);
    toggle_question_active($pdo, (int) $m[1]);
    redirect(route_path('/hr/questions'));
}

if ($method === 'POST' && preg_match('#^/hr/questions/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    delete_question($pdo, (int) $m[1]);
    redirect(route_path('/hr/questions'));
}

if ($method === 'GET' && $path === '/hr/export/excel') {
    require_hr_auth($config);

    $rows = list_candidates($pdo, []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="disc-report-' . time() . '.csv"');

    $out = fopen('php://output', 'w');
    $roleScoreColumns = [
        'MANAGER' => 'Skor Manager (1-10)',
        'BACK_OFFICE' => 'Skor Back Office (1-10)',
        'HEAD_KITCHEN' => 'Skor Head Kitchen (1-10)',
        'HEAD_BAR' => 'Skor Head Bar (1-10)',
        'FLOOR_CAPTAIN' => 'Skor Floor Captain (1-10)',
        'COOK' => 'Skor Cook (1-10)',
        'COOK_HELPER' => 'Skor Cook Helper (1-10)',
        'STEWARD' => 'Skor Steward (1-10)',
        'MIXOLOGIST' => 'Skor Mixologist (1-10)',
        'SERVER' => 'Skor Server (1-10)',
        'HOUSEKEEPING' => 'Skor Housekeeping (1-10)',
    ];
    fputcsv($out, array_merge(
        ['ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Rekomendasi', 'Kelayakan Wawancara', 'Status', 'D', 'I', 'S', 'C'],
        array_values($roleScoreColumns),
        ['Alasan']
    ));
    foreach ($rows as $row) {
        $roleScores = extract_role_scores_from_candidate($row);
        $base = [
            $row['id'],
            $row['full_name'],
            $row['email'],
            $row['whatsapp'],
            $row['selected_role'],
            map_recommendation_label($row['recommendation']),
            interview_recommendation_label($row, $roleScores),
            $row['status'],
            $row['disc_d'],
            $row['disc_i'],
            $row['disc_s'],
            $row['disc_c'],
        ];
        $scoreCells = [];
        foreach ($roleScoreColumns as $roleCode => $label) {
            $scoreCells[] = $roleScores[$roleCode] ?? 0;
        }
        fputcsv($out, array_merge($base, $scoreCells, [
            $row['reason'],
        ]));
    }
    fclose($out);
    exit;
}

if ($method === 'GET' && $path === '/hr/export/pdf') {
    require_hr_auth($config);

    $rows = list_candidates($pdo, []);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>DISC Report</title><style>body{font-family:Arial,sans-serif;padding:20px;}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px;text-align:left;}th{background:#f2f2f2}</style></head><body>';
    echo '<h2>DISC Report</h2>';
    echo '<p>Gunakan menu browser: Print -> Save as PDF.</p>';
    echo '<table><thead><tr><th>ID</th><th>Nama</th><th>Role Dipilih</th><th>Rekomendasi</th><th>Status</th><th>DISC</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $disc = 'D ' . (int) $row['disc_d'] . ' / I ' . (int) $row['disc_i'] . ' / S ' . (int) $row['disc_s'] . ' / C ' . (int) $row['disc_c'];
        echo '<tr>'
            . '<td>' . h((string) $row['id']) . '</td>'
            . '<td>' . h($row['full_name']) . '</td>'
            . '<td>' . h($row['selected_role']) . '</td>'
            . '<td>' . h(map_recommendation_label($row['recommendation'])) . '</td>'
            . '<td>' . h($row['status']) . '</td>'
            . '<td>' . h($disc) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

if ($method === 'GET' && $path === '/hr/export/answers.csv') {
    require_hr_auth($config);

    $rows = list_answer_details_for_export($pdo);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="disc-answers-all-' . time() . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Candidate ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Rekomendasi', 'Status',
        'No Soal', 'Role Soal', 'Most', 'Most Text', 'Least', 'Least Text',
    ]);
    foreach ($rows as $row) {
        $mostCode = (string) ($row['most_code'] ?? '');
        $leastCode = (string) ($row['least_code'] ?? '');
        fputcsv($out, [
            $row['candidate_id'],
            $row['full_name'],
            $row['email'],
            $row['whatsapp'],
            $row['selected_role'],
            map_recommendation_label($row['recommendation'] ?? null),
            $row['status'],
            (int) ($row['question_order'] ?? 0),
            $row['question_role'] ?? '-',
            $mostCode !== '' ? $mostCode : '-',
            answer_option_text($row, $mostCode),
            $leastCode !== '' ? $leastCode : '-',
            answer_option_text($row, $leastCode),
        ]);
    }
    fclose($out);
    exit;
}

http_response_code(404);
echo 'Not found';
