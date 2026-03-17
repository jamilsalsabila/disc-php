<?php

declare(strict_types=1);

require_once __DIR__ . '/questions.php';

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbDir = dirname($config['db_path']);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo, $config);

    return $pdo;
}

function migrate(PDO $pdo, array $config): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            browser_token TEXT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            email_key TEXT,
            whatsapp TEXT NOT NULL,
            whatsapp_key TEXT,
            selected_role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'in_progress',
            started_at TEXT NOT NULL,
            deadline_at TEXT NOT NULL,
            submitted_at TEXT,
            duration_seconds INTEGER,
            recommendation TEXT,
            reason TEXT,
            role_scores_json TEXT,
            disc_d INTEGER DEFAULT 0,
            disc_i INTEGER DEFAULT 0,
            disc_s INTEGER DEFAULT 0,
            disc_c INTEGER DEFAULT 0,
            score_server INTEGER DEFAULT 0,
            score_beverage INTEGER DEFAULT 0,
            score_cook INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            answer_type TEXT NOT NULL,
            option_code TEXT,
            disc_value TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(candidate_id) REFERENCES candidates(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS questions_bank (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_key TEXT NOT NULL DEFAULT 'Server Specialist',
            question_order INTEGER NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hr_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            failed_count INTEGER NOT NULL DEFAULT 0,
            window_start INTEGER NOT NULL,
            blocked_until INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL
        );"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_browser_token ON candidates(browser_token);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_email_key ON candidates(email_key);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_whatsapp_key ON candidates(whatsapp_key);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_answers_candidate_id ON answers(candidate_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_order ON questions_bank(question_order);');

    ensure_questions_role_column($pdo);
    ensure_candidate_role_scores_column($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_role_order ON questions_bank(role_key, question_order);');

    seed_questions_by_role_if_missing($pdo, $config);
}

function ensure_questions_role_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(questions_bank)')->fetchAll();
    $hasRole = false;
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === 'role_key') {
            $hasRole = true;
            break;
        }
    }

    if (!$hasRole) {
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN role_key TEXT NOT NULL DEFAULT 'Server Specialist'");
    }

    $pdo->exec("UPDATE questions_bank SET role_key = 'Server Specialist' WHERE role_key IS NULL OR role_key = ''");
}

function ensure_candidate_role_scores_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(candidates)')->fetchAll();
    $hasRoleScores = false;
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === 'role_scores_json') {
            $hasRoleScores = true;
            break;
        }
    }

    if (!$hasRoleScores) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN role_scores_json TEXT');
    }
}

function seed_questions_by_role_if_missing(PDO $pdo, array $config): void
{
    $byRole = isset($config['question_sources_by_role']) && is_array($config['question_sources_by_role'])
        ? $config['question_sources_by_role']
        : [];

    if (empty($byRole)) {
        return;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM questions_bank WHERE role_key = ?');
    $insertStmt = $pdo->prepare('INSERT INTO questions_bank (role_key, question_order, option_a, option_b, option_c, option_d, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');

    foreach ($byRole as $roleKey => $sourcePath) {
        if (!is_file($sourcePath)) {
            continue;
        }

        $countStmt->execute([$roleKey]);
        $existing = (int) $countStmt->fetchColumn();
        if ($existing > 0) {
            continue;
        }

        $questions = parse_questions_from_txt($sourcePath);
        if (empty($questions)) {
            continue;
        }

        $now = now_iso();
        foreach ($questions as $index => $q) {
            $opts = [];
            foreach ($q['options'] as $option) {
                $opts[$option['code']] = $option['text'];
            }

            $insertStmt->execute([
                $roleKey,
                $index + 1,
                $opts['A'] ?? '',
                $opts['B'] ?? '',
                $opts['C'] ?? '',
                $opts['D'] ?? '',
                $now,
                $now,
            ]);
        }
    }
}

function list_questions(PDO $pdo, bool $includeInactive = true, ?string $roleKey = null): array
{
    $conditions = [];
    $params = [];

    if (!$includeInactive) {
        $conditions[] = 'is_active = 1';
    }

    if ($roleKey !== null && $roleKey !== '') {
        $conditions[] = 'role_key = :role_key';
        $params[':role_key'] = $roleKey;
    }

    $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
    $stmt = $pdo->prepare("SELECT * FROM questions_bank {$where} ORDER BY question_order ASC, id ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'role_key' => $row['role_key'],
            'order' => (int) $row['question_order'],
            'is_active' => (int) $row['is_active'] === 1,
            'optionA' => $row['option_a'],
            'optionB' => $row['option_b'],
            'optionC' => $row['option_c'],
            'optionD' => $row['option_d'],
            'options' => [
                ['code' => 'A', 'text' => $row['option_a'], 'disc' => 'D'],
                ['code' => 'B', 'text' => $row['option_b'], 'disc' => 'I'],
                ['code' => 'C', 'text' => $row['option_c'], 'disc' => 'S'],
                ['code' => 'D', 'text' => $row['option_d'], 'disc' => 'C'],
            ],
        ];
    }, $rows);
}

function get_question_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM questions_bank WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_next_question_order(PDO $pdo, ?string $roleKey = null): int
{
    if ($roleKey !== null && $roleKey !== '') {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(question_order), 0) FROM questions_bank WHERE role_key = ?');
        $stmt->execute([$roleKey]);
        $max = (int) $stmt->fetchColumn();
        return $max + 1;
    }

    $max = (int) $pdo->query('SELECT COALESCE(MAX(question_order), 0) FROM questions_bank')->fetchColumn();
    return $max + 1;
}

function create_question(PDO $pdo, array $payload): void
{
    $stmt = $pdo->prepare('INSERT INTO questions_bank (role_key, question_order, option_a, option_b, option_c, option_d, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = now_iso();
    $stmt->execute([
        trim((string) $payload['role_key']),
        (int) $payload['order'],
        trim($payload['option_a']),
        trim($payload['option_b']),
        trim($payload['option_c']),
        trim($payload['option_d']),
        !empty($payload['is_active']) ? 1 : 0,
        $now,
        $now,
    ]);
}

function update_question(PDO $pdo, int $id, array $payload): bool
{
    $stmt = $pdo->prepare('UPDATE questions_bank SET role_key = ?, question_order = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([
        trim((string) $payload['role_key']),
        (int) $payload['order'],
        trim($payload['option_a']),
        trim($payload['option_b']),
        trim($payload['option_c']),
        trim($payload['option_d']),
        !empty($payload['is_active']) ? 1 : 0,
        now_iso(),
        $id,
    ]);
    return $stmt->rowCount() > 0;
}

function toggle_question_active(PDO $pdo, int $id): bool
{
    $row = get_question_by_id($pdo, $id);
    if (!$row) {
        return false;
    }

    $next = ((int) $row['is_active'] === 1) ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE questions_bank SET is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$next, now_iso(), $id]);
    return $stmt->rowCount() > 0;
}

function delete_question(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM questions_bank WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function create_candidate(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare('INSERT INTO candidates (browser_token, full_name, email, email_key, whatsapp, whatsapp_key, selected_role, status, started_at, deadline_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $payload['browser_token'] ?? null,
        $payload['full_name'],
        $payload['email'],
        $payload['email_key'],
        $payload['whatsapp'],
        $payload['whatsapp_key'],
        $payload['selected_role'],
        'in_progress',
        $payload['started_at'],
        $payload['deadline_at'],
        now_iso(),
    ]);
    return (int) $pdo->lastInsertId();
}

function get_candidate_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_in_progress_candidate_by_browser_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM candidates WHERE browser_token = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_candidate_by_identity(PDO $pdo, ?string $emailKey, ?string $waKey): ?array
{
    if (!$emailKey && !$waKey) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE (email_key = :email OR whatsapp_key = :wa) ORDER BY id DESC LIMIT 1');
    $stmt->execute([
        ':email' => $emailKey,
        ':wa' => $waKey,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}

function save_submission(PDO $pdo, array $payload): void
{
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM answers WHERE candidate_id = ?');
    $deleteStmt->execute([$payload['candidate_id']]);

    $insertAnswer = $pdo->prepare('INSERT INTO answers (candidate_id, question_id, answer_type, option_code, disc_value, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($payload['answers'] as $questionId => $answer) {
        $insertAnswer->execute([$payload['candidate_id'], (int) $questionId, 'most', $answer['most']['optionCode'], $answer['most']['disc'], now_iso()]);
        $insertAnswer->execute([$payload['candidate_id'], (int) $questionId, 'least', $answer['least']['optionCode'], $answer['least']['disc'], now_iso()]);
    }

    $evaluation = $payload['evaluation'];
    $updateStmt = $pdo->prepare('UPDATE candidates SET status = ?, submitted_at = ?, duration_seconds = ?, recommendation = ?, reason = ?, role_scores_json = ?, disc_d = ?, disc_i = ?, disc_s = ?, disc_c = ?, score_server = ?, score_beverage = ?, score_cook = ? WHERE id = ?');
    $updateStmt->execute([
        $payload['force_status'] ?? 'submitted',
        $payload['submitted_at'],
        $payload['duration_seconds'],
        $evaluation['recommendation'] ?? null,
        $evaluation['reason'] ?? null,
        json_encode($evaluation['roleScores'] ?? [], JSON_UNESCAPED_UNICODE),
        $evaluation['discCounts']['D'] ?? 0,
        $evaluation['discCounts']['I'] ?? 0,
        $evaluation['discCounts']['S'] ?? 0,
        $evaluation['discCounts']['C'] ?? 0,
        $evaluation['roleScores']['SERVER_SPECIALIST'] ?? 0,
        $evaluation['roleScores']['BEVERAGE_SPECIALIST'] ?? 0,
        $evaluation['roleScores']['SENIOR_COOK'] ?? 0,
        $payload['candidate_id'],
    ]);

    $pdo->commit();
}

function list_candidates(PDO $pdo, array $filters = []): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['search'])) {
        $conditions[] = '(full_name LIKE :search OR email LIKE :search OR whatsapp LIKE :search)';
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['role'])) {
        $conditions[] = 'selected_role = :role';
        $params[':role'] = $filters['role'];
    }

    if (!empty($filters['recommendation'])) {
        $conditions[] = 'recommendation = :recommendation';
        $params[':recommendation'] = $filters['recommendation'];
    }

    $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
    $stmt = $pdo->prepare("SELECT * FROM candidates {$where} ORDER BY created_at DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_answers_for_candidate(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare('SELECT * FROM answers WHERE candidate_id = ? ORDER BY question_id ASC, answer_type DESC');
    $stmt->execute([$candidateId]);
    return $stmt->fetchAll();
}

function get_answer_details_for_candidate_export(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            c.id AS candidate_id,
            c.full_name,
            c.email,
            c.whatsapp,
            c.selected_role,
            c.recommendation,
            c.status,
            q.id AS question_id,
            q.question_order,
            q.role_key AS question_role,
            q.option_a,
            q.option_b,
            q.option_c,
            q.option_d,
            MAX(CASE WHEN a.answer_type = 'most' THEN a.option_code END) AS most_code,
            MAX(CASE WHEN a.answer_type = 'least' THEN a.option_code END) AS least_code
        FROM answers a
        INNER JOIN candidates c ON c.id = a.candidate_id
        LEFT JOIN questions_bank q ON q.id = a.question_id
        WHERE c.id = ?
        GROUP BY c.id, q.id
        ORDER BY q.question_order ASC, q.id ASC"
    );
    $stmt->execute([$candidateId]);
    return $stmt->fetchAll();
}

function list_answer_details_for_export(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            c.id AS candidate_id,
            c.full_name,
            c.email,
            c.whatsapp,
            c.selected_role,
            c.recommendation,
            c.status,
            q.id AS question_id,
            q.question_order,
            q.role_key AS question_role,
            q.option_a,
            q.option_b,
            q.option_c,
            q.option_d,
            MAX(CASE WHEN a.answer_type = 'most' THEN a.option_code END) AS most_code,
            MAX(CASE WHEN a.answer_type = 'least' THEN a.option_code END) AS least_code
        FROM answers a
        INNER JOIN candidates c ON c.id = a.candidate_id
        LEFT JOIN questions_bank q ON q.id = a.question_id
        GROUP BY c.id, q.id
        ORDER BY c.created_at DESC, c.id DESC, q.question_order ASC, q.id ASC"
    );
    return $stmt->fetchAll();
}

function delete_candidate(PDO $pdo, int $id): bool
{
    $pdo->beginTransaction();

    $stmtAnswer = $pdo->prepare('DELETE FROM answers WHERE candidate_id = ?');
    $stmtAnswer->execute([$id]);

    $stmt = $pdo->prepare('DELETE FROM candidates WHERE id = ?');
    $stmt->execute([$id]);
    $changed = $stmt->rowCount() > 0;

    $pdo->commit();
    return $changed;
}

function get_summary_stats(PDO $pdo, array $filters = []): array
{
    $candidates = list_candidates($pdo, $filters);
    $roleDistributionMap = [];
    $submitted = array_values(array_filter($candidates, static function ($c) {
        return in_array($c['status'], ['submitted', 'timeout_submitted'], true);
    }));

    foreach ($submitted as $row) {
        $key = $row['recommendation'] ?: 'TIDAK_DIREKOMENDASIKAN';
        $roleDistributionMap[$key] = ($roleDistributionMap[$key] ?? 0) + 1;
    }

    $roleDistribution = [];
    foreach ($roleDistributionMap as $recommendation => $total) {
        $roleDistribution[] = ['recommendation' => $recommendation, 'total' => $total];
    }

    $totalSubmitted = count($submitted);
    $sumD = 0;
    $sumI = 0;
    $sumS = 0;
    $sumC = 0;

    foreach ($submitted as $row) {
        $sumD += (int) ($row['disc_d'] ?? 0);
        $sumI += (int) ($row['disc_i'] ?? 0);
        $sumS += (int) ($row['disc_s'] ?? 0);
        $sumC += (int) ($row['disc_c'] ?? 0);
    }

    $avgDisc = [
        'avg_d' => $totalSubmitted > 0 ? round($sumD / $totalSubmitted, 2) : 0,
        'avg_i' => $totalSubmitted > 0 ? round($sumI / $totalSubmitted, 2) : 0,
        'avg_s' => $totalSubmitted > 0 ? round($sumS / $totalSubmitted, 2) : 0,
        'avg_c' => $totalSubmitted > 0 ? round($sumC / $totalSubmitted, 2) : 0,
        'total_submitted' => $totalSubmitted,
    ];

    return [
        'roleDistribution' => $roleDistribution,
        'avgDisc' => $avgDisc,
    ];
}

function get_login_attempt(PDO $pdo, string $ip): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM hr_login_attempts WHERE ip = ? LIMIT 1');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_login_attempt(PDO $pdo, string $ip, int $failedCount, int $windowStart, int $blockedUntil): void
{
    $existing = get_login_attempt($pdo, $ip);
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE hr_login_attempts SET failed_count = ?, window_start = ?, blocked_until = ?, updated_at = ? WHERE ip = ?');
        $stmt->execute([$failedCount, $windowStart, $blockedUntil, time(), $ip]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO hr_login_attempts (ip, failed_count, window_start, blocked_until, updated_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$ip, $failedCount, $windowStart, $blockedUntil, time()]);
}

function clear_login_attempt(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('DELETE FROM hr_login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
}
