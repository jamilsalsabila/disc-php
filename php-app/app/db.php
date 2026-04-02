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
            integrity_tab_switches INTEGER DEFAULT 0,
            integrity_paste_count INTEGER DEFAULT 0,
            draft_answers_json TEXT,
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
        "CREATE TABLE IF NOT EXISTS essay_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL,
            essay_question_id INTEGER NOT NULL,
            role_group_snapshot TEXT,
            question_order_snapshot INTEGER,
            question_text_snapshot TEXT,
            answer_text TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(candidate_id) REFERENCES candidates(id),
            FOREIGN KEY(essay_question_id) REFERENCES essay_questions(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS questions_bank (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_key TEXT NOT NULL DEFAULT 'Manager',
            question_order INTEGER NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            disc_a TEXT NOT NULL DEFAULT 'D',
            disc_b TEXT NOT NULL DEFAULT 'I',
            disc_c TEXT NOT NULL DEFAULT 'S',
            disc_d TEXT NOT NULL DEFAULT 'C',
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interview_checklists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL UNIQUE,
            checklist_json TEXT,
            final_decision TEXT,
            strengths_notes TEXT,
            risk_notes TEXT,
            placement_notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(candidate_id) REFERENCES candidates(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS integrity_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL,
            phase TEXT NOT NULL,
            event_type TEXT NOT NULL,
            event_value TEXT,
            meta_json TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(candidate_id) REFERENCES candidates(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS essay_typing_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL,
            essay_question_id INTEGER NOT NULL,
            keystrokes INTEGER NOT NULL DEFAULT 0,
            input_events INTEGER NOT NULL DEFAULT 0,
            paste_count INTEGER NOT NULL DEFAULT 0,
            focus_count INTEGER NOT NULL DEFAULT 0,
            blur_count INTEGER NOT NULL DEFAULT 0,
            active_ms INTEGER NOT NULL DEFAULT 0,
            total_chars INTEGER NOT NULL DEFAULT 0,
            last_input_at TEXT,
            updated_at TEXT NOT NULL,
            UNIQUE(candidate_id, essay_question_id),
            FOREIGN KEY(candidate_id) REFERENCES candidates(id),
            FOREIGN KEY(essay_question_id) REFERENCES essay_questions(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ai_evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            candidate_id INTEGER NOT NULL UNIQUE,
            model TEXT NOT NULL,
            status TEXT NOT NULL,
            score_1_10 INTEGER,
            suggested_position TEXT,
            conclusion TEXT,
            rationale TEXT,
            strengths_json TEXT,
            risks_json TEXT,
            follow_up_json TEXT,
            payload_json TEXT,
            raw_response_json TEXT,
            error_message TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(candidate_id) REFERENCES candidates(id)
        );"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS essay_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_group TEXT NOT NULL DEFAULT 'Manager',
            question_order INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            guidance_text TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );"
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_browser_token ON candidates(browser_token);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_email_key ON candidates(email_key);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_whatsapp_key ON candidates(whatsapp_key);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_answers_candidate_id ON answers(candidate_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_essay_answers_candidate_id ON essay_answers(candidate_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_order ON questions_bank(question_order);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_essay_questions_order ON essay_questions(question_order);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_interview_checklist_candidate ON interview_checklists(candidate_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrity_events_candidate ON integrity_events(candidate_id, created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_typing_metrics_candidate ON essay_typing_metrics(candidate_id, essay_question_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_eval_candidate ON ai_evaluations(candidate_id);');

    ensure_questions_role_column($pdo);
    ensure_questions_disc_columns($pdo);
    if (needs_role_migration($pdo)) {
        migrate_legacy_role_keys($pdo);
        deduplicate_questions_by_role($pdo);
    }
    ensure_candidate_role_scores_column($pdo);
    ensure_candidate_draft_answers_column($pdo);
    ensure_candidate_integrity_columns($pdo);
    ensure_candidate_phase_columns($pdo);
    ensure_candidate_draft_essay_answers_column($pdo);
    ensure_essay_questions_role_group_column($pdo);
    ensure_essay_answers_snapshot_columns($pdo);
    repair_legacy_essay_answer_snapshots($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_role_order ON questions_bank(role_key, question_order);');

    if (!empty($config['auto_seed_questions'])) {
        seed_questions_by_role_if_missing($pdo, $config);
    }
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
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN role_key TEXT NOT NULL DEFAULT 'Manager'");
    }

    $pdo->exec("UPDATE questions_bank SET role_key = 'Manager' WHERE role_key IS NULL OR role_key = ''");
}

function ensure_questions_disc_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(questions_bank)')->fetchAll();
    $names = array_map(static function ($col) {
        return (string) ($col['name'] ?? '');
    }, $columns);

    if (!in_array('disc_a', $names, true)) {
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN disc_a TEXT NOT NULL DEFAULT 'D'");
    }
    if (!in_array('disc_b', $names, true)) {
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN disc_b TEXT NOT NULL DEFAULT 'I'");
    }
    if (!in_array('disc_c', $names, true)) {
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN disc_c TEXT NOT NULL DEFAULT 'S'");
    }
    if (!in_array('disc_d', $names, true)) {
        $pdo->exec("ALTER TABLE questions_bank ADD COLUMN disc_d TEXT NOT NULL DEFAULT 'C'");
    }

    $pdo->exec("UPDATE questions_bank SET disc_a = 'D' WHERE disc_a IS NULL OR disc_a = ''");
    $pdo->exec("UPDATE questions_bank SET disc_b = 'I' WHERE disc_b IS NULL OR disc_b = ''");
    $pdo->exec("UPDATE questions_bank SET disc_c = 'S' WHERE disc_c IS NULL OR disc_c = ''");
    $pdo->exec("UPDATE questions_bank SET disc_d = 'C' WHERE disc_d IS NULL OR disc_d = ''");
}

function migrate_legacy_role_keys(PDO $pdo): void
{
    $roleMap = [
        'Server Specialist' => 'Server',
        'Beverage Specialist' => 'Mixologist',
        'Senior Cook' => 'Cook',
        'Admin Operasional' => 'Back Office',
        'Asisten Manager' => 'Manager',
        'Floor Crew ( Server, Runner, Housekeeping )' => 'Server',
        'Bar Crew' => 'Mixologist',
        'Kitchen Crew ( Cook, Cook Helper, Steward )' => 'Cook',
        'Back Office ( Admin )' => 'Back Office',
    ];

    $stmt = $pdo->prepare('UPDATE questions_bank SET role_key = ? WHERE role_key = ?');
    foreach ($roleMap as $legacy => $next) {
        $stmt->execute([$next, $legacy]);
    }
}

function needs_role_migration(PDO $pdo): bool
{
    $legacyCountStmt = $pdo->query(
        "SELECT COUNT(*) FROM questions_bank
         WHERE role_key IN (
            'Server Specialist',
            'Beverage Specialist',
            'Senior Cook',
            'Asisten Manager',
            'Admin Operasional'
         )"
    );
    $legacyCount = (int) $legacyCountStmt->fetchColumn();
    if ($legacyCount > 0) {
        return true;
    }

    $duplicateStmt = $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT role_key, option_a, option_b, option_c, option_d, COUNT(*) AS c
            FROM questions_bank
            GROUP BY role_key, option_a, option_b, option_c, option_d
            HAVING c > 1
        )"
    );
    $duplicateCount = (int) $duplicateStmt->fetchColumn();
    return $duplicateCount > 0;
}

function deduplicate_questions_by_role(PDO $pdo): void
{
    $roles = $pdo->query('SELECT DISTINCT role_key FROM questions_bank')->fetchAll(PDO::FETCH_COLUMN);
    if (!$roles) {
        return;
    }

    $selectStmt = $pdo->prepare('SELECT id, option_a, option_b, option_c, option_d FROM questions_bank WHERE role_key = ? ORDER BY question_order ASC, id ASC');
    $deleteStmt = $pdo->prepare('DELETE FROM questions_bank WHERE id = ?');
    $reorderStmt = $pdo->prepare('UPDATE questions_bank SET question_order = ?, updated_at = ? WHERE id = ?');

    foreach ($roles as $roleKeyRaw) {
        $roleKey = (string) $roleKeyRaw;
        if ($roleKey === '') {
            continue;
        }

        $selectStmt->execute([$roleKey]);
        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }

        $seen = [];
        $keptIds = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['option_a'] ?? '')))
                . '||' . strtolower(trim((string) ($row['option_b'] ?? '')))
                . '||' . strtolower(trim((string) ($row['option_c'] ?? '')))
                . '||' . strtolower(trim((string) ($row['option_d'] ?? '')));

            if (isset($seen[$key])) {
                $deleteStmt->execute([(int) $row['id']]);
                continue;
            }

            $seen[$key] = true;
            $keptIds[] = (int) $row['id'];
        }

        $order = 1;
        $now = now_iso();
        foreach ($keptIds as $id) {
            $reorderStmt->execute([$order, $now, $id]);
            $order++;
        }
    }
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

function ensure_candidate_draft_answers_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(candidates)')->fetchAll();
    $hasDraftAnswers = false;
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === 'draft_answers_json') {
            $hasDraftAnswers = true;
            break;
        }
    }

    if (!$hasDraftAnswers) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN draft_answers_json TEXT');
    }
}

function ensure_candidate_integrity_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(candidates)')->fetchAll();
    $names = array_map(static function ($col) {
        return (string) ($col['name'] ?? '');
    }, $columns);

    if (!in_array('integrity_tab_switches', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN integrity_tab_switches INTEGER DEFAULT 0');
    }
    if (!in_array('integrity_paste_count', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN integrity_paste_count INTEGER DEFAULT 0');
    }
}

function ensure_candidate_phase_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(candidates)')->fetchAll();
    $names = array_map(static function ($col) {
        return (string) ($col['name'] ?? '');
    }, $columns);

    if (!in_array('disc_completed_at', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN disc_completed_at TEXT');
    }
    if (!in_array('essay_started_at', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN essay_started_at TEXT');
    }
    if (!in_array('essay_deadline_at', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN essay_deadline_at TEXT');
    }
    if (!in_array('essay_submitted_at', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN essay_submitted_at TEXT');
    }
}

function ensure_candidate_draft_essay_answers_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(candidates)')->fetchAll();
    $names = array_map(static function ($col) {
        return (string) ($col['name'] ?? '');
    }, $columns);

    if (!in_array('draft_essay_answers_json', $names, true)) {
        $pdo->exec('ALTER TABLE candidates ADD COLUMN draft_essay_answers_json TEXT');
    }
}

function ensure_essay_questions_role_group_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(essay_questions)')->fetchAll();
    $hasRoleGroup = false;
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === 'role_group') {
            $hasRoleGroup = true;
            break;
        }
    }

    if (!$hasRoleGroup) {
        $pdo->exec("ALTER TABLE essay_questions ADD COLUMN role_group TEXT NOT NULL DEFAULT 'Manager'");
    }

    $pdo->exec("UPDATE essay_questions SET role_group = 'Manager' WHERE role_group IS NULL OR role_group = ''");
}

function ensure_essay_answers_snapshot_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(essay_answers)')->fetchAll();
    $names = array_map(static function ($col) {
        return (string) ($col['name'] ?? '');
    }, $columns);

    if (!in_array('role_group_snapshot', $names, true)) {
        $pdo->exec('ALTER TABLE essay_answers ADD COLUMN role_group_snapshot TEXT');
    }
    if (!in_array('question_order_snapshot', $names, true)) {
        $pdo->exec('ALTER TABLE essay_answers ADD COLUMN question_order_snapshot INTEGER');
    }
    if (!in_array('question_text_snapshot', $names, true)) {
        $pdo->exec('ALTER TABLE essay_answers ADD COLUMN question_text_snapshot TEXT');
    }

    $pdo->exec(
        "UPDATE essay_answers
         SET role_group_snapshot = (
               SELECT q.role_group FROM essay_questions q WHERE q.id = essay_answers.essay_question_id
             ),
             question_order_snapshot = (
               SELECT q.question_order FROM essay_questions q WHERE q.id = essay_answers.essay_question_id
             ),
             question_text_snapshot = (
               SELECT q.question_text FROM essay_questions q WHERE q.id = essay_answers.essay_question_id
             )
         WHERE role_group_snapshot IS NULL
            OR question_order_snapshot IS NULL
            OR question_text_snapshot IS NULL"
    );
}

function essay_group_from_selected_role_for_db(string $selectedRole): string
{
    $map = [
        'Manager' => 'Manager',
        'Back Office' => 'Back office',
        'Head Kitchen' => 'Head Kitchen',
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

function repair_legacy_essay_answer_snapshots(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT
            ea.id,
            ea.candidate_id,
            ea.essay_question_id,
            ea.answer_text,
            c.selected_role
         FROM essay_answers ea
         INNER JOIN candidates c ON c.id = ea.candidate_id
         WHERE TRIM(COALESCE(ea.answer_text, '')) <> ''
           AND (
               ea.role_group_snapshot IS NULL OR TRIM(ea.role_group_snapshot) = ''
               OR ea.question_order_snapshot IS NULL OR ea.question_order_snapshot <= 0
               OR ea.question_text_snapshot IS NULL OR TRIM(ea.question_text_snapshot) = ''
           )
         ORDER BY ea.candidate_id ASC, ea.id ASC"
    )->fetchAll();

    if (empty($rows)) {
        return;
    }

    $byCandidate = [];
    foreach ($rows as $row) {
        $cid = (int) ($row['candidate_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        if (!isset($byCandidate[$cid])) {
            $byCandidate[$cid] = [];
        }
        $byCandidate[$cid][] = $row;
    }

    $selectQuestionById = $pdo->prepare('SELECT id, role_group, question_order, question_text FROM essay_questions WHERE id = ?');
    $selectGroupQuestions = $pdo->prepare('SELECT id, role_group, question_order, question_text FROM essay_questions WHERE role_group = ? ORDER BY question_order ASC, id ASC');
    $selectUsedOrders = $pdo->prepare(
        "SELECT DISTINCT question_order_snapshot
         FROM essay_answers
         WHERE candidate_id = ?
           AND question_order_snapshot IS NOT NULL
           AND question_order_snapshot > 0"
    );
    $updateSnapshot = $pdo->prepare(
        'UPDATE essay_answers
         SET role_group_snapshot = ?, question_order_snapshot = ?, question_text_snapshot = ?
         WHERE id = ?'
    );

    $pdo->beginTransaction();
    try {
        foreach ($byCandidate as $candidateId => $candidateRows) {
            $selectedRole = (string) ($candidateRows[0]['selected_role'] ?? '');
            $group = essay_group_from_selected_role_for_db($selectedRole);

            $selectGroupQuestions->execute([$group]);
            $groupQuestions = $selectGroupQuestions->fetchAll();
            if (empty($groupQuestions)) {
                continue;
            }

            $questionById = [];
            $availableByOrder = [];
            foreach ($groupQuestions as $q) {
                $qid = (int) ($q['id'] ?? 0);
                $order = (int) ($q['question_order'] ?? 0);
                if ($qid > 0) {
                    $questionById[$qid] = $q;
                }
                if ($order > 0 && !isset($availableByOrder[$order])) {
                    $availableByOrder[$order] = $q;
                }
            }

            $selectUsedOrders->execute([$candidateId]);
            $usedOrders = [];
            foreach ($selectUsedOrders->fetchAll() as $u) {
                $o = (int) ($u['question_order_snapshot'] ?? 0);
                if ($o > 0) {
                    $usedOrders[$o] = true;
                }
            }

            $missingOrders = [];
            foreach ($availableByOrder as $order => $q) {
                if (!isset($usedOrders[(int) $order])) {
                    $missingOrders[] = (int) $order;
                }
            }
            sort($missingOrders);

            foreach ($candidateRows as $row) {
                $essayAnswerId = (int) ($row['id'] ?? 0);
                if ($essayAnswerId <= 0) {
                    continue;
                }

                $question = null;
                $essayQuestionId = (int) ($row['essay_question_id'] ?? 0);
                if ($essayQuestionId > 0 && isset($questionById[$essayQuestionId])) {
                    $question = $questionById[$essayQuestionId];
                } elseif ($essayQuestionId > 0) {
                    $selectQuestionById->execute([$essayQuestionId]);
                    $q = $selectQuestionById->fetch();
                    if (is_array($q) && trim((string) ($q['question_text'] ?? '')) !== '') {
                        $question = $q;
                    }
                }

                if ($question === null && !empty($missingOrders)) {
                    $nextOrder = array_shift($missingOrders);
                    $question = $availableByOrder[$nextOrder] ?? null;
                }

                if (!is_array($question)) {
                    continue;
                }

                $questionOrder = (int) ($question['question_order'] ?? 0);
                if ($questionOrder > 0) {
                    $usedOrders[$questionOrder] = true;
                }

                $updateSnapshot->execute([
                    (string) ($question['role_group'] ?? $group),
                    $questionOrder > 0 ? $questionOrder : null,
                    (string) ($question['question_text'] ?? ''),
                    $essayAnswerId,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
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
        $insertStmt = $pdo->prepare('INSERT INTO questions_bank (role_key, question_order, option_a, option_b, option_c, option_d, disc_a, disc_b, disc_c, disc_d, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');

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
                OPTION_TO_DISC['A'],
                OPTION_TO_DISC['B'],
                OPTION_TO_DISC['C'],
                OPTION_TO_DISC['D'],
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
            'discA' => $row['disc_a'] ?? 'D',
            'discB' => $row['disc_b'] ?? 'I',
            'discC' => $row['disc_c'] ?? 'S',
            'discD' => $row['disc_d'] ?? 'C',
            'options' => [
                ['code' => 'A', 'text' => $row['option_a'], 'disc' => $row['disc_a'] ?? 'D'],
                ['code' => 'B', 'text' => $row['option_b'], 'disc' => $row['disc_b'] ?? 'I'],
                ['code' => 'C', 'text' => $row['option_c'], 'disc' => $row['disc_c'] ?? 'S'],
                ['code' => 'D', 'text' => $row['option_d'], 'disc' => $row['disc_d'] ?? 'C'],
            ],
        ];
    }, $rows);
}

function get_question_role_order_keys(PDO $pdo): array
{
    $rows = $pdo->query('SELECT role_key, question_order FROM questions_bank')->fetchAll();
    $keys = [];
    foreach ($rows as $row) {
        $key = (string) ($row['role_key'] ?? '') . '||' . (int) ($row['question_order'] ?? 0);
        $keys[$key] = true;
    }
    return $keys;
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
    $stmt = $pdo->prepare('INSERT INTO questions_bank (role_key, question_order, option_a, option_b, option_c, option_d, disc_a, disc_b, disc_c, disc_d, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = now_iso();
    $stmt->execute([
        trim((string) $payload['role_key']),
        (int) $payload['order'],
        trim($payload['option_a']),
        trim($payload['option_b']),
        trim($payload['option_c']),
        trim($payload['option_d']),
        (string) ($payload['disc_a'] ?? 'D'),
        (string) ($payload['disc_b'] ?? 'I'),
        (string) ($payload['disc_c'] ?? 'S'),
        (string) ($payload['disc_d'] ?? 'C'),
        !empty($payload['is_active']) ? 1 : 0,
        $now,
        $now,
    ]);
}

function create_questions_bulk(PDO $pdo, array $rows, bool $replaceExistingPerRole = false): int
{
    if (empty($rows)) {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        if ($replaceExistingPerRole) {
            $roles = [];
            foreach ($rows as $row) {
                $roleKey = trim((string) ($row['role_key'] ?? ''));
                if ($roleKey !== '') {
                    $roles[$roleKey] = true;
                }
            }

            if (!empty($roles)) {
                $deleteStmt = $pdo->prepare('DELETE FROM questions_bank WHERE role_key = ?');
                foreach (array_keys($roles) as $roleKey) {
                    $deleteStmt->execute([$roleKey]);
                }
            }
        }

        $insertStmt = $pdo->prepare('INSERT INTO questions_bank (role_key, question_order, option_a, option_b, option_c, option_d, disc_a, disc_b, disc_c, disc_d, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = now_iso();
        $inserted = 0;
        foreach ($rows as $row) {
            $insertStmt->execute([
                trim((string) $row['role_key']),
                (int) $row['order'],
                trim((string) $row['option_a']),
                trim((string) $row['option_b']),
                trim((string) $row['option_c']),
                trim((string) $row['option_d']),
                (string) ($row['disc_a'] ?? 'D'),
                (string) ($row['disc_b'] ?? 'I'),
                (string) ($row['disc_c'] ?? 'S'),
                (string) ($row['disc_d'] ?? 'C'),
                !empty($row['is_active']) ? 1 : 0,
                $now,
                $now,
            ]);
            $inserted++;
        }

        $pdo->commit();
        return $inserted;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_question(PDO $pdo, int $id, array $payload): bool
{
    $stmt = $pdo->prepare('UPDATE questions_bank SET role_key = ?, question_order = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, disc_a = ?, disc_b = ?, disc_c = ?, disc_d = ?, is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([
        trim((string) $payload['role_key']),
        (int) $payload['order'],
        trim($payload['option_a']),
        trim($payload['option_b']),
        trim($payload['option_c']),
        trim($payload['option_d']),
        (string) ($payload['disc_a'] ?? 'D'),
        (string) ($payload['disc_b'] ?? 'I'),
        (string) ($payload['disc_c'] ?? 'S'),
        (string) ($payload['disc_d'] ?? 'C'),
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

function delete_questions_bulk(PDO $pdo, array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn ($id) => $id > 0));
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM questions_bank WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    return (int) $stmt->rowCount();
}

function build_essay_questions_filter_sql(bool $includeInactive = true, ?string $roleGroup = null): array
{
    $conditions = [];
    $params = [];

    if (!$includeInactive) {
        $conditions[] = 'is_active = 1';
    }

    if ($roleGroup !== null && $roleGroup !== '') {
        $conditions[] = 'role_group = :role_group';
        $params[':role_group'] = $roleGroup;
    }

    $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
    return [$where, $params];
}

function build_essay_questions_order_sql(string $sortBy = 'default', string $sortDir = 'asc'): string
{
    $dir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';

    switch ($sortBy) {
        case 'group':
            return "role_group {$dir}, question_order {$dir}, id {$dir}";
        case 'order':
            return "question_order {$dir}, role_group {$dir}, id {$dir}";
        case 'status':
            return "is_active {$dir}, role_group ASC, question_order ASC, id ASC";
        case 'updated':
            return "updated_at {$dir}, id {$dir}";
        case 'id':
            return "id {$dir}";
        case 'default':
        default:
            if ($dir === 'DESC') {
                return 'role_group DESC, question_order DESC, id DESC';
            }
            return 'role_group ASC, question_order ASC, id ASC';
    }
}

function map_essay_questions_rows(array $rows): array
{
    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'role_group' => (string) ($row['role_group'] ?? 'Manager'),
            'order' => (int) $row['question_order'],
            'question_text' => (string) ($row['question_text'] ?? ''),
            'guidance_text' => (string) ($row['guidance_text'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }, $rows);
}

function count_essay_questions(PDO $pdo, bool $includeInactive = true, ?string $roleGroup = null): int
{
    [$where, $params] = build_essay_questions_filter_sql($includeInactive, $roleGroup);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM essay_questions {$where}");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

function list_essay_questions(PDO $pdo, bool $includeInactive = true, ?string $roleGroup = null, string $sortBy = 'default', string $sortDir = 'asc'): array
{
    [$where, $params] = build_essay_questions_filter_sql($includeInactive, $roleGroup);
    $orderBy = build_essay_questions_order_sql($sortBy, $sortDir);
    $stmt = $pdo->prepare("SELECT * FROM essay_questions {$where} ORDER BY {$orderBy}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return map_essay_questions_rows($rows);
}

function list_essay_questions_paginated(PDO $pdo, bool $includeInactive = true, ?string $roleGroup = null, int $page = 1, int $perPage = 20, string $sortBy = 'default', string $sortDir = 'asc'): array
{
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    [$where, $params] = build_essay_questions_filter_sql($includeInactive, $roleGroup);
    $orderBy = build_essay_questions_order_sql($sortBy, $sortDir);
    $stmt = $pdo->prepare("SELECT * FROM essay_questions {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return map_essay_questions_rows($rows);
}

function get_essay_question_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM essay_questions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_next_essay_question_order(PDO $pdo, ?string $roleGroup = null): int
{
    if ($roleGroup !== null && $roleGroup !== '') {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(question_order), 0) FROM essay_questions WHERE role_group = ?');
        $stmt->execute([$roleGroup]);
        $max = (int) $stmt->fetchColumn();
        return $max + 1;
    }

    $max = (int) $pdo->query('SELECT COALESCE(MAX(question_order), 0) FROM essay_questions')->fetchColumn();
    return $max + 1;
}

function create_essay_question(PDO $pdo, array $payload): void
{
    $stmt = $pdo->prepare('INSERT INTO essay_questions (role_group, question_order, question_text, guidance_text, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $now = now_iso();
    $stmt->execute([
        trim((string) ($payload['role_group'] ?? 'Manager')),
        (int) ($payload['order'] ?? 0),
        trim((string) ($payload['question_text'] ?? '')),
        trim((string) ($payload['guidance_text'] ?? '')),
        !empty($payload['is_active']) ? 1 : 0,
        $now,
        $now,
    ]);
}

function update_essay_question(PDO $pdo, int $id, array $payload): bool
{
    $stmt = $pdo->prepare('UPDATE essay_questions SET role_group = ?, question_order = ?, question_text = ?, guidance_text = ?, is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([
        trim((string) ($payload['role_group'] ?? 'Manager')),
        (int) ($payload['order'] ?? 0),
        trim((string) ($payload['question_text'] ?? '')),
        trim((string) ($payload['guidance_text'] ?? '')),
        !empty($payload['is_active']) ? 1 : 0,
        now_iso(),
        $id,
    ]);
    return $stmt->rowCount() > 0;
}

function toggle_essay_question_active(PDO $pdo, int $id): bool
{
    $row = get_essay_question_by_id($pdo, $id);
    if (!$row) {
        return false;
    }

    $next = ((int) ($row['is_active'] ?? 0) === 1) ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE essay_questions SET is_active = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$next, now_iso(), $id]);
    return $stmt->rowCount() > 0;
}

function delete_essay_question(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM essay_questions WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function delete_essay_questions_bulk(PDO $pdo, array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn ($id) => $id > 0));
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM essay_questions WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    return (int) $stmt->rowCount();
}

function get_essay_question_group_order_keys(PDO $pdo): array
{
    $rows = $pdo->query('SELECT role_group, question_order FROM essay_questions')->fetchAll();
    $keys = [];
    foreach ($rows as $row) {
        $key = (string) ($row['role_group'] ?? '') . '||' . (int) ($row['question_order'] ?? 0);
        $keys[$key] = true;
    }
    return $keys;
}

function create_essay_questions_bulk(PDO $pdo, array $rows, bool $replaceExistingPerGroup = false): int
{
    if (empty($rows)) {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        if ($replaceExistingPerGroup) {
            $groups = [];
            foreach ($rows as $row) {
                $group = trim((string) ($row['role_group'] ?? ''));
                if ($group !== '') {
                    $groups[$group] = true;
                }
            }

            if (!empty($groups)) {
                $deleteStmt = $pdo->prepare('DELETE FROM essay_questions WHERE role_group = ?');
                foreach (array_keys($groups) as $group) {
                    $deleteStmt->execute([$group]);
                }
            }
        }

        $insertStmt = $pdo->prepare('INSERT INTO essay_questions (role_group, question_order, question_text, guidance_text, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $now = now_iso();
        $inserted = 0;

        foreach ($rows as $row) {
            $insertStmt->execute([
                trim((string) ($row['role_group'] ?? 'Manager')),
                (int) ($row['order'] ?? 0),
                trim((string) ($row['question_text'] ?? '')),
                trim((string) ($row['guidance_text'] ?? '')),
                !empty($row['is_active']) ? 1 : 0,
                $now,
                $now,
            ]);
            $inserted++;
        }

        $pdo->commit();
        return $inserted;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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

function save_submission(PDO $pdo, array $payload): bool
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
    $updateStmt = $pdo->prepare('UPDATE candidates SET status = ?, submitted_at = ?, essay_submitted_at = ?, duration_seconds = ?, recommendation = ?, reason = ?, role_scores_json = ?, disc_d = ?, disc_i = ?, disc_s = ?, disc_c = ?, score_server = ?, score_beverage = ?, score_cook = ?, draft_answers_json = NULL, draft_essay_answers_json = NULL WHERE id = ? AND status = ?');
    $updateStmt->execute([
        $payload['force_status'] ?? 'submitted',
        $payload['submitted_at'],
        $payload['submitted_at'],
        $payload['duration_seconds'],
        $evaluation['recommendation'] ?? null,
        $evaluation['reason'] ?? null,
        json_encode($evaluation['roleScores'] ?? [], JSON_UNESCAPED_UNICODE),
        $evaluation['discCounts']['D'] ?? 0,
        $evaluation['discCounts']['I'] ?? 0,
        $evaluation['discCounts']['S'] ?? 0,
        $evaluation['discCounts']['C'] ?? 0,
        $evaluation['roleScores']['SERVER'] ?? $evaluation['roleScores']['FLOOR_CREW'] ?? $evaluation['roleScores']['SERVER_SPECIALIST'] ?? 0,
        $evaluation['roleScores']['MIXOLOGIST'] ?? $evaluation['roleScores']['BAR_CREW'] ?? $evaluation['roleScores']['BEVERAGE_SPECIALIST'] ?? 0,
        $evaluation['roleScores']['COOK'] ?? $evaluation['roleScores']['KITCHEN_CREW'] ?? $evaluation['roleScores']['SENIOR_COOK'] ?? 0,
        $payload['candidate_id'],
        'in_progress',
    ]);

    $updated = $updateStmt->rowCount() > 0;
    $pdo->commit();
    return $updated;
}

function update_candidate_draft_answers(PDO $pdo, int $candidateId, array $answers): bool
{
    $stmt = $pdo->prepare("UPDATE candidates SET draft_answers_json = ? WHERE id = ? AND status = 'in_progress'");
    $stmt->execute([
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $candidateId,
    ]);
    return $stmt->rowCount() > 0;
}

function update_candidate_draft_essay_answers(PDO $pdo, int $candidateId, array $answers): bool
{
    $stmt = $pdo->prepare("UPDATE candidates SET draft_essay_answers_json = ? WHERE id = ? AND status = 'in_progress'");
    $stmt->execute([
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $candidateId,
    ]);
    return $stmt->rowCount() > 0;
}

function mark_disc_completed(PDO $pdo, int $candidateId, string $discCompletedAt, string $essayDeadlineAt): bool
{
    $stmt = $pdo->prepare("UPDATE candidates SET disc_completed_at = COALESCE(disc_completed_at, ?), essay_started_at = COALESCE(essay_started_at, ?), essay_deadline_at = COALESCE(essay_deadline_at, ?) WHERE id = ? AND status = 'in_progress'");
    $stmt->execute([$discCompletedAt, $discCompletedAt, $essayDeadlineAt, $candidateId]);
    return $stmt->rowCount() > 0;
}

function get_essay_answers_for_candidate(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare('SELECT * FROM essay_answers WHERE candidate_id = ? ORDER BY essay_question_id ASC, id ASC');
    $stmt->execute([$candidateId]);
    return $stmt->fetchAll();
}

function get_essay_answer_details_for_candidate(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            ea.essay_question_id,
            MAX(ea.answer_text) AS answer_text,
            COALESCE(MAX(ea.role_group_snapshot), MAX(q.role_group)) AS role_group,
            COALESCE(MAX(ea.question_order_snapshot), MAX(q.question_order)) AS question_order,
            COALESCE(MAX(ea.question_text_snapshot), MAX(q.question_text)) AS question_text
         FROM essay_answers ea
         LEFT JOIN essay_questions q ON q.id = ea.essay_question_id
         WHERE ea.candidate_id = ?
         GROUP BY ea.essay_question_id
         ORDER BY COALESCE(MAX(ea.question_order_snapshot), MAX(q.question_order), 999999) ASC, ea.essay_question_id ASC"
    );
    $stmt->execute([$candidateId]);
    return $stmt->fetchAll();
}

function save_essay_answers(PDO $pdo, int $candidateId, array $answersByQuestionId): void
{
    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM essay_answers WHERE candidate_id = ?');
    $del->execute([$candidateId]);

    $questionMeta = [];
    $questionIds = array_values(array_filter(array_map('intval', array_keys($answersByQuestionId)), static fn ($id) => $id > 0));
    if (!empty($questionIds)) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmtMeta = $pdo->prepare("SELECT id, role_group, question_order, question_text FROM essay_questions WHERE id IN ({$placeholders})");
        $stmtMeta->execute($questionIds);
        foreach ($stmtMeta->fetchAll() as $row) {
            $qid = (int) ($row['id'] ?? 0);
            if ($qid > 0) {
                $questionMeta[$qid] = [
                    'role_group' => (string) ($row['role_group'] ?? ''),
                    'question_order' => (int) ($row['question_order'] ?? 0),
                    'question_text' => (string) ($row['question_text'] ?? ''),
                ];
            }
        }
    }

    $ins = $pdo->prepare('INSERT INTO essay_answers (candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $now = now_iso();
    foreach ($answersByQuestionId as $questionId => $text) {
        $qid = (int) $questionId;
        $meta = $questionMeta[$qid] ?? ['role_group' => null, 'question_order' => null, 'question_text' => null];
        $ins->execute([
            $candidateId,
            $qid,
            $meta['role_group'],
            $meta['question_order'],
            $meta['question_text'],
            trim((string) $text),
            $now,
        ]);
    }

    $pdo->commit();
}

function add_candidate_integrity_signal(PDO $pdo, int $candidateId, string $signal, int $increment = 1): bool
{
    $allowed = [
        'tab_switch' => 'integrity_tab_switches',
        'paste' => 'integrity_paste_count',
    ];

    if (!isset($allowed[$signal])) {
        return false;
    }

    $step = max(1, $increment);
    $column = $allowed[$signal];
    $stmt = $pdo->prepare("UPDATE candidates SET {$column} = COALESCE({$column}, 0) + ? WHERE id = ? AND status = 'in_progress'");
    $stmt->execute([$step, $candidateId]);
    return $stmt->rowCount() > 0;
}

function log_integrity_event(PDO $pdo, int $candidateId, string $phase, string $eventType, string $eventValue = '', array $meta = []): void
{
    $stmt = $pdo->prepare('INSERT INTO integrity_events (candidate_id, phase, event_type, event_value, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $candidateId,
        trim($phase) !== '' ? trim($phase) : 'unknown',
        trim($eventType) !== '' ? trim($eventType) : 'unknown',
        $eventValue,
        !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        now_iso(),
    ]);
}

function list_integrity_events(PDO $pdo, int $candidateId, int $limit = 200): array
{
    $safeLimit = max(20, min($limit, 500));
    $stmt = $pdo->prepare("SELECT * FROM integrity_events WHERE candidate_id = ? ORDER BY id DESC LIMIT {$safeLimit}");
    $stmt->execute([$candidateId]);
    $rows = $stmt->fetchAll();
    return array_reverse($rows);
}

function upsert_essay_typing_metric(PDO $pdo, int $candidateId, int $essayQuestionId, array $metric): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO essay_typing_metrics
            (candidate_id, essay_question_id, keystrokes, input_events, paste_count, focus_count, blur_count, active_ms, total_chars, last_input_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(candidate_id, essay_question_id) DO UPDATE SET
            keystrokes = excluded.keystrokes,
            input_events = excluded.input_events,
            paste_count = excluded.paste_count,
            focus_count = excluded.focus_count,
            blur_count = excluded.blur_count,
            active_ms = excluded.active_ms,
            total_chars = excluded.total_chars,
            last_input_at = excluded.last_input_at,
            updated_at = excluded.updated_at'
    );

    $stmt->execute([
        $candidateId,
        $essayQuestionId,
        max(0, (int) ($metric['keystrokes'] ?? 0)),
        max(0, (int) ($metric['input_events'] ?? 0)),
        max(0, (int) ($metric['paste_count'] ?? 0)),
        max(0, (int) ($metric['focus_count'] ?? 0)),
        max(0, (int) ($metric['blur_count'] ?? 0)),
        max(0, (int) ($metric['active_ms'] ?? 0)),
        max(0, (int) ($metric['total_chars'] ?? 0)),
        isset($metric['last_input_at']) && is_string($metric['last_input_at']) ? $metric['last_input_at'] : null,
        now_iso(),
    ]);
}

function get_essay_typing_metrics_for_candidate(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            m.essay_question_id,
            m.keystrokes,
            m.input_events,
            m.paste_count,
            m.focus_count,
            m.blur_count,
            m.active_ms,
            m.total_chars,
            m.last_input_at,
            q.role_group,
            q.question_order,
            q.question_text
         FROM essay_typing_metrics m
         LEFT JOIN essay_questions q ON q.id = m.essay_question_id
         WHERE m.candidate_id = ?
         ORDER BY q.question_order ASC, m.essay_question_id ASC"
    );
    $stmt->execute([$candidateId]);
    return $stmt->fetchAll();
}

function get_ai_evaluation_by_candidate(PDO $pdo, int $candidateId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM ai_evaluations WHERE candidate_id = ? LIMIT 1');
    $stmt->execute([$candidateId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_ai_evaluation(PDO $pdo, int $candidateId, array $payload): void
{
    $existing = get_ai_evaluation_by_candidate($pdo, $candidateId);
    $now = now_iso();

    $base = [
        'model' => (string) ($payload['model'] ?? ''),
        'status' => (string) ($payload['status'] ?? 'error'),
        'score_1_10' => isset($payload['score_1_10']) ? (int) $payload['score_1_10'] : null,
        'suggested_position' => (string) ($payload['suggested_position'] ?? ''),
        'conclusion' => (string) ($payload['conclusion'] ?? ''),
        'rationale' => (string) ($payload['rationale'] ?? ''),
        'strengths_json' => json_encode($payload['strengths'] ?? [], JSON_UNESCAPED_UNICODE),
        'risks_json' => json_encode($payload['risks'] ?? [], JSON_UNESCAPED_UNICODE),
        'follow_up_json' => json_encode($payload['follow_up_questions'] ?? [], JSON_UNESCAPED_UNICODE),
        'payload_json' => json_encode($payload['payload'] ?? [], JSON_UNESCAPED_UNICODE),
        'raw_response_json' => json_encode($payload['raw_response'] ?? [], JSON_UNESCAPED_UNICODE),
        'error_message' => (string) ($payload['error_message'] ?? ''),
    ];

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE ai_evaluations
             SET model = ?, status = ?, score_1_10 = ?, suggested_position = ?, conclusion = ?, rationale = ?,
                 strengths_json = ?, risks_json = ?, follow_up_json = ?, payload_json = ?, raw_response_json = ?,
                 error_message = ?, updated_at = ?
             WHERE candidate_id = ?'
        );
        $stmt->execute([
            $base['model'],
            $base['status'],
            $base['score_1_10'],
            $base['suggested_position'],
            $base['conclusion'],
            $base['rationale'],
            $base['strengths_json'],
            $base['risks_json'],
            $base['follow_up_json'],
            $base['payload_json'],
            $base['raw_response_json'],
            $base['error_message'],
            $now,
            $candidateId,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ai_evaluations
            (candidate_id, model, status, score_1_10, suggested_position, conclusion, rationale, strengths_json, risks_json, follow_up_json, payload_json, raw_response_json, error_message, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $candidateId,
        $base['model'],
        $base['status'],
        $base['score_1_10'],
        $base['suggested_position'],
        $base['conclusion'],
        $base['rationale'],
        $base['strengths_json'],
        $base['risks_json'],
        $base['follow_up_json'],
        $base['payload_json'],
        $base['raw_response_json'],
        $base['error_message'],
        $now,
        $now,
    ]);
}

function list_overdue_in_progress_candidates(PDO $pdo, int $limit = 200): array
{
    $safeLimit = max(1, min($limit, 1000));
    $stmt = $pdo->prepare(
        "SELECT * FROM candidates
         WHERE status = 'in_progress' AND (
            (disc_completed_at IS NULL AND deadline_at <= ?)
            OR
            (disc_completed_at IS NOT NULL AND essay_deadline_at IS NOT NULL AND essay_deadline_at <= ?)
         )
         ORDER BY created_at ASC
         LIMIT {$safeLimit}"
    );
    $now = now_iso();
    $stmt->execute([$now, $now]);
    return $stmt->fetchAll();
}

function build_candidate_filter_sql(array $filters): array
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
    return [$where, $params];
}

function count_candidates(PDO $pdo, array $filters = []): int
{
    [$where, $params] = build_candidate_filter_sql($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM candidates {$where}");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

function list_candidates(PDO $pdo, array $filters = []): array
{
    [$where, $params] = build_candidate_filter_sql($filters);
    $stmt = $pdo->prepare("SELECT * FROM candidates {$where} ORDER BY created_at DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function list_candidates_paginated(PDO $pdo, array $filters = [], int $page = 1, int $perPage = 20): array
{
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    [$where, $params] = build_candidate_filter_sql($filters);
    $stmt = $pdo->prepare("SELECT * FROM candidates {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_answers_for_candidate(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            a.question_id,
            q.question_order,
            MAX(CASE WHEN a.answer_type = 'most' THEN a.option_code END) AS most_code,
            MAX(CASE WHEN a.answer_type = 'least' THEN a.option_code END) AS least_code
         FROM answers a
         LEFT JOIN questions_bank q ON q.id = a.question_id
         WHERE a.candidate_id = ?
         GROUP BY a.question_id, q.question_order
         ORDER BY COALESCE(q.question_order, 999999) ASC, a.question_id ASC"
    );
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

    $stmtAi = $pdo->prepare('DELETE FROM ai_evaluations WHERE candidate_id = ?');
    $stmtAi->execute([$id]);

    $stmtEvent = $pdo->prepare('DELETE FROM integrity_events WHERE candidate_id = ?');
    $stmtEvent->execute([$id]);

    $stmtTyping = $pdo->prepare('DELETE FROM essay_typing_metrics WHERE candidate_id = ?');
    $stmtTyping->execute([$id]);

    $stmtEssay = $pdo->prepare('DELETE FROM essay_answers WHERE candidate_id = ?');
    $stmtEssay->execute([$id]);

    $stmtChecklist = $pdo->prepare('DELETE FROM interview_checklists WHERE candidate_id = ?');
    $stmtChecklist->execute([$id]);

    $stmtAnswer = $pdo->prepare('DELETE FROM answers WHERE candidate_id = ?');
    $stmtAnswer->execute([$id]);

    $stmt = $pdo->prepare('DELETE FROM candidates WHERE id = ?');
    $stmt->execute([$id]);
    $changed = $stmt->rowCount() > 0;

    $pdo->commit();
    return $changed;
}

function get_interview_checklist(PDO $pdo, int $candidateId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM interview_checklists WHERE candidate_id = ? LIMIT 1');
    $stmt->execute([$candidateId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_interview_checklist(PDO $pdo, int $candidateId, array $payload): void
{
    $existing = get_interview_checklist($pdo, $candidateId);
    $now = now_iso();

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE interview_checklists SET checklist_json = ?, final_decision = ?, strengths_notes = ?, risk_notes = ?, placement_notes = ?, updated_at = ? WHERE candidate_id = ?');
        $stmt->execute([
            json_encode($payload['checklist'] ?? [], JSON_UNESCAPED_UNICODE),
            (string) ($payload['final_decision'] ?? ''),
            (string) ($payload['strengths_notes'] ?? ''),
            (string) ($payload['risk_notes'] ?? ''),
            (string) ($payload['placement_notes'] ?? ''),
            $now,
            $candidateId,
        ]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO interview_checklists (candidate_id, checklist_json, final_decision, strengths_notes, risk_notes, placement_notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $candidateId,
        json_encode($payload['checklist'] ?? [], JSON_UNESCAPED_UNICODE),
        (string) ($payload['final_decision'] ?? ''),
        (string) ($payload['strengths_notes'] ?? ''),
        (string) ($payload['risk_notes'] ?? ''),
        (string) ($payload['placement_notes'] ?? ''),
        $now,
        $now,
    ]);
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
