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
        ['value' => 'SERVER_SPECIALIST', 'label' => 'Server Specialist'],
        ['value' => 'BEVERAGE_SPECIALIST', 'label' => 'Beverage Specialist'],
        ['value' => 'SENIOR_COOK', 'label' => 'Senior Cook'],
        ['value' => 'INCOMPLETE', 'label' => 'Incomplete'],
        ['value' => 'TIDAK_DIREKOMENDASIKAN', 'label' => 'Tidak Direkomendasikan'],
    ];
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

    foreach ($questions as $question) {
        $qid = (int) $question['id'];
        $most = trim((string) ($payload['q_' . $qid . '_most'] ?? ''));
        $least = trim((string) ($payload['q_' . $qid . '_least'] ?? ''));

        if (!in_array($most, ['A', 'B', 'C', 'D'], true) || !in_array($least, ['A', 'B', 'C', 'D'], true) || $most === $least) {
            continue;
        }

        $answers[$qid] = [
            'most' => ['optionCode' => $most, 'disc' => OPTION_TO_DISC[$most] ?? null],
            'least' => ['optionCode' => $least, 'disc' => OPTION_TO_DISC[$least] ?? null],
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
        'page_title' => 'Tes DISC Kandidat',
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

    $activeQuestions = list_questions($pdo, false);
    if (empty($activeQuestions)) {
        render('candidate/identity', [
            'page_title' => 'Tes DISC Kandidat',
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
            'page_title' => 'Tes DISC Kandidat',
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
            'page_title' => 'Tes DISC Kandidat',
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

    $questions = list_questions($pdo, false);
    if (empty($questions)) {
        unset($_SESSION['candidate_id']);
        render('candidate/thank-you', [
            'page_title' => 'Tes Belum Tersedia',
            'candidate' => null,
        ]);
        exit;
    }

    if (strtotime((string) $candidate['deadline_at']) <= time()) {
        $evaluation = evaluate_candidate([], $candidate['selected_role']);
        save_submission($pdo, [
            'candidate_id' => (int) $candidate['id'],
            'answers' => [],
            'submitted_at' => now_iso(),
            'duration_seconds' => seconds_between((string) $candidate['started_at'], (string) $candidate['deadline_at']),
            'evaluation' => $evaluation,
            'force_status' => 'timeout_submitted',
        ]);

        unset($_SESSION['candidate_id']);
        redirect(route_path('/thank-you?id=' . (int) $candidate['id']));
    }

    render('candidate/test', [
        'page_title' => 'Tes DISC',
        'candidate' => $candidate,
        'questions' => $questions,
        'deadline_at' => $candidate['deadline_at'],
    ]);
    exit;
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

    $questions = list_questions($pdo, false);
    $answers = build_answers_from_payload($_POST, $questions);
    $submittedAt = now_iso();
    $expired = strtotime((string) $candidate['deadline_at']) <= time();

    $answeredCount = count($answers);
    $totalQuestions = count($questions);
    $minimumRequired = (int) ceil($totalQuestions * $config['min_completion_ratio']);

    if ($answeredCount !== $totalQuestions && !$expired) {
        render('candidate/test', [
            'page_title' => 'Tes DISC',
            'candidate' => $candidate,
            'questions' => $questions,
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
    $stats = get_summary_stats($pdo, $filters);

    json_response([
        'candidates' => $candidates,
        'stats' => $stats,
        'filters' => $filters,
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
        'role_score_data' => [
            'server' => (int) ($candidate['score_server'] ?? 0),
            'beverage' => (int) ($candidate['score_beverage'] ?? 0),
            'cook' => (int) ($candidate['score_cook'] ?? 0),
        ],
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr/questions') {
    require_hr_auth($config);

    render('hr/questions', [
        'page_title' => 'Kelola Soal DISC',
        'question_bank' => list_questions($pdo, true),
    ]);
    exit;
}

if ($method === 'GET' && $path === '/hr/questions/new') {
    require_hr_auth($config);

    render('hr/question-form', [
        'page_title' => 'Tambah Soal DISC',
        'form_title' => 'Tambah Soal',
        'action_url' => route_path('/hr/questions/new'),
        'values' => [
            'order' => get_next_question_order($pdo),
            'is_active' => true,
        ],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/questions/new') {
    require_hr_auth($config);

    $payload = [
        'order' => (int) ($_POST['order'] ?? 0),
        'option_a' => trim((string) ($_POST['option_a'] ?? '')),
        'option_b' => trim((string) ($_POST['option_b'] ?? '')),
        'option_c' => trim((string) ($_POST['option_c'] ?? '')),
        'option_d' => trim((string) ($_POST['option_d'] ?? '')),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = $payload['order'] > 0
        && mb_strlen($payload['option_a']) >= 3
        && mb_strlen($payload['option_b']) >= 3
        && mb_strlen($payload['option_c']) >= 3
        && mb_strlen($payload['option_d']) >= 3;

    if (!$valid) {
        render('hr/question-form', [
            'page_title' => 'Tambah Soal DISC',
            'form_title' => 'Tambah Soal',
            'action_url' => route_path('/hr/questions/new'),
            'values' => $payload,
            'error_message' => 'Semua field opsi wajib diisi minimal 3 karakter.',
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
        'values' => [
            'order' => (int) $row['question_order'],
            'option_a' => $row['option_a'],
            'option_b' => $row['option_b'],
            'option_c' => $row['option_c'],
            'option_d' => $row['option_d'],
            'is_active' => ((int) $row['is_active']) === 1,
        ],
    ]);
    exit;
}

if ($method === 'POST' && preg_match('#^/hr/questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);

    $payload = [
        'order' => (int) ($_POST['order'] ?? 0),
        'option_a' => trim((string) ($_POST['option_a'] ?? '')),
        'option_b' => trim((string) ($_POST['option_b'] ?? '')),
        'option_c' => trim((string) ($_POST['option_c'] ?? '')),
        'option_d' => trim((string) ($_POST['option_d'] ?? '')),
        'is_active' => !empty($_POST['is_active']),
    ];

    $valid = $payload['order'] > 0
        && mb_strlen($payload['option_a']) >= 3
        && mb_strlen($payload['option_b']) >= 3
        && mb_strlen($payload['option_c']) >= 3
        && mb_strlen($payload['option_d']) >= 3;

    if (!$valid) {
        render('hr/question-form', [
            'page_title' => 'Edit Soal #' . (int) $m[1],
            'form_title' => 'Edit Soal #' . (int) $m[1],
            'action_url' => route_path('/hr/questions/' . (int) $m[1] . '/edit'),
            'values' => $payload,
            'error_message' => 'Semua field opsi wajib diisi minimal 3 karakter.',
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
    fputcsv($out, ['ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Rekomendasi', 'Status', 'D', 'I', 'S', 'C', 'Skor Server', 'Skor Beverage', 'Skor Cook', 'Alasan']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['full_name'],
            $row['email'],
            $row['whatsapp'],
            $row['selected_role'],
            map_recommendation_label($row['recommendation']),
            $row['status'],
            $row['disc_d'],
            $row['disc_i'],
            $row['disc_s'],
            $row['disc_c'],
            $row['score_server'],
            $row['score_beverage'],
            $row['score_cook'],
            $row['reason'],
        ]);
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

http_response_code(404);
echo 'Not found';
