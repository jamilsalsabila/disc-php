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
        $questions = list_questions($pdo, false, null);
        $totalQuestions = count($questions);
        $answeredCount = count($draftAnswers);
        $minimumRequired = (int) ceil($totalQuestions * $minRatio);
        $baseEvaluation = evaluate_candidate(to_disc_payload($draftAnswers), $candidate['selected_role'] ?? null);
        $evaluation = ($answeredCount < $minimumRequired)
            ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
            : $baseEvaluation;

        $saved = save_submission($pdo, [
            'candidate_id' => (int) $candidate['id'],
            'answers' => $draftAnswers,
            'submitted_at' => (string) ($candidate['deadline_at'] ?? now_iso()),
            'duration_seconds' => seconds_between((string) $candidate['started_at'], (string) $candidate['deadline_at']),
            'evaluation' => $evaluation,
            'force_status' => 'timeout_submitted',
        ]);

        if ($saved) {
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
        redirect(route_path('/test'));
    }

    $browserToken = $_COOKIE['disc_browser_token'] ?? '';
    if (is_string($browserToken) && $browserToken !== '') {
        $active = get_in_progress_candidate_by_browser_token($pdo, $browserToken);
        if ($active) {
            $_SESSION['candidate_id'] = (int) $active['id'];
            redirect(route_path('/test'));
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
            redirect(route_path('/test'));
        }
    }

    $emailKey = normalize_email($email);
    $waKey = normalize_whatsapp($whatsapp);
    $existing = find_candidate_by_identity($pdo, $emailKey, $waKey);

    if ($existing) {
        if ($existing['status'] === 'in_progress' && ($existing['browser_token'] ?? '') === $browserToken) {
            $_SESSION['candidate_id'] = (int) $existing['id'];
            redirect(route_path('/test'));
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

    $_SESSION['candidate_id'] = $candidateId;
    redirect(route_path('/test'));
}

if ($method === 'GET' && $path === '/test') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }

    if ($candidate['status'] !== 'in_progress') {
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
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
        $answeredCount = count($draftAnswers);
        $totalQuestions = count($questions);
        $minimumRequired = (int) ceil($totalQuestions * $config['min_completion_ratio']);
        $baseEvaluation = evaluate_candidate(to_disc_payload($draftAnswers), $candidate['selected_role']);
        $evaluation = ($answeredCount < $minimumRequired)
            ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
            : $baseEvaluation;
        save_submission($pdo, [
            'candidate_id' => (int) $candidate['id'],
            'answers' => $draftAnswers,
            'submitted_at' => now_iso(),
            'duration_seconds' => seconds_between((string) $candidate['started_at'], (string) $candidate['deadline_at']),
            'evaluation' => $evaluation,
            'force_status' => 'timeout_submitted',
        ]);

        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
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

if ($method === 'POST' && $path === '/submit') {
    $candidate = ensure_candidate_session($pdo);
    if (!$candidate) {
        redirect(route_path('/'));
    }

    if ($candidate['status'] !== 'in_progress') {
        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
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

    $baseEvaluation = evaluate_candidate(to_disc_payload($answers), $candidate['selected_role']);
    $evaluation = ($answeredCount < $minimumRequired)
        ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
        : $baseEvaluation;

    save_submission($pdo, [
        'candidate_id' => (int) $candidate['id'],
        'answers' => $answers,
        'submitted_at' => $submittedAt,
        'duration_seconds' => seconds_between((string) $candidate['started_at'], $expired ? (string) $candidate['deadline_at'] : $submittedAt),
        'evaluation' => $evaluation,
        'force_status' => $expired ? 'timeout_submitted' : 'submitted',
    ]);

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

    render('hr/profile', [
        'page_title' => 'Profil Kandidat #' . $candidate['id'],
        'candidate' => $candidate,
        'question_rows' => $questionRows,
        'disc_data' => [
            'D' => (int) ($candidate['disc_d'] ?? 0),
            'I' => (int) ($candidate['disc_i'] ?? 0),
            'S' => (int) ($candidate['disc_s'] ?? 0),
            'C' => (int) ($candidate['disc_c'] ?? 0),
        ],
        'role_score_data' => $roleScoreData,
        'interview_recommendation' => $interviewRecommendation,
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
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="candidate-answers-' . $candidateId . '-' . time() . '.csv"');

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
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Jawaban Kandidat #' . h((string) $candidateId) . '</title><style>body{font-family:Arial,sans-serif;padding:20px;}h2{margin:0 0 6px;}p{margin:4px 0 12px;}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ddd;padding:6px;vertical-align:top;text-align:left;}th{background:#f2f2f2}</style></head><body>';
    echo '<h2>Jawaban Kandidat #' . h((string) $candidateId) . '</h2>';
    echo '<p><strong>Nama:</strong> ' . h((string) ($candidate['full_name'] ?? '-')) . '<br>';
    echo '<strong>Email:</strong> ' . h((string) ($candidate['email'] ?? '-')) . '<br>';
    echo '<strong>Role Dipilih:</strong> ' . h((string) ($candidate['selected_role'] ?? '-')) . '<br>';
    echo '<strong>Rekomendasi:</strong> ' . h(map_recommendation_label($candidate['recommendation'] ?? null)) . '<br>';
    echo '<strong>Status:</strong> ' . h((string) ($candidate['status'] ?? '-')) . '</p>';
    echo '<p>Gunakan menu browser: Print -> Save as PDF.</p>';
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
    echo '</tbody></table></body></html>';
    exit;
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
