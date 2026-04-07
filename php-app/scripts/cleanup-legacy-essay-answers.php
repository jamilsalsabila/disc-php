#!/usr/bin/env php
<?php

declare(strict_types=1);

function usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/cleanup-legacy-essay-answers.php [--db=storage/disc_app.sqlite] [--candidate=ID] [--apply]\n\n";
    echo "Modes:\n";
    echo "  (default) Dry-run only, no DB changes\n";
    echo "  --apply   Execute backup + normalization\n";
}

function getOptValue(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function toLower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

function pick_best_row_for_order(array $rows, string $expectedGroup): ?array
{
    if (empty($rows)) {
        return null;
    }

    $expectedNorm = toLower(trim($expectedGroup));
    $best = null;
    $bestScore = -1;

    foreach ($rows as $row) {
        $group = trim((string) ($row['role_group_snapshot'] ?? ''));
        $answer = trim((string) ($row['answer_text'] ?? ''));
        $groupScore = (toLower($group) === $expectedNorm) ? 200000 : 0;
        $answerScore = strlen($answer);
        $idScore = max(0, 1000 - (int) ($row['id'] ?? 0));
        $score = $groupScore + $answerScore + $idScore;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    return $best;
}

$argv = $_SERVER['argv'] ?? [];
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    usage();
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$dbArg = getOptValue($argv, '--db=');
$candidateArg = getOptValue($argv, '--candidate=');
$candidateFilter = ($candidateArg !== null && ctype_digit($candidateArg)) ? (int) $candidateArg : null;

$defaultDb = dirname(__DIR__) . '/storage/disc_app.sqlite';
$dbPath = $dbArg !== null && trim($dbArg) !== '' ? trim($dbArg) : $defaultDb;

if (!is_file($dbPath)) {
    fwrite(STDERR, "DB file not found: {$dbPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA busy_timeout = 5000');

$roleMapRows = $pdo->query('SELECT role_name, essay_group FROM role_catalog')->fetchAll();
$roleToGroup = [];
foreach ($roleMapRows as $row) {
    $role = trim((string) ($row['role_name'] ?? ''));
    $group = trim((string) ($row['essay_group'] ?? ''));
    if ($role !== '' && $group !== '') {
        $roleToGroup[$role] = $group;
    }
}

$sqlCandidates = 'SELECT id, full_name, selected_role FROM candidates';
$params = [];
if ($candidateFilter !== null) {
    $sqlCandidates .= ' WHERE id = :id';
    $params[':id'] = $candidateFilter;
}
$sqlCandidates .= ' ORDER BY id ASC';

$stmtCandidates = $pdo->prepare($sqlCandidates);
$stmtCandidates->execute($params);
$candidates = $stmtCandidates->fetchAll();

$selectSnapshot = $pdo->prepare(
    'SELECT id, source_essay_question_id, role_group, question_order, question_text
     FROM candidate_essay_questions
     WHERE candidate_id = ?
     ORDER BY question_order ASC, id ASC'
);
$selectAnswers = $pdo->prepare(
    'SELECT id, candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at
     FROM essay_answers
     WHERE candidate_id = ?
     ORDER BY question_order_snapshot ASC, id ASC'
);

$plan = [];

foreach ($candidates as $cand) {
    $candidateId = (int) ($cand['id'] ?? 0);
    if ($candidateId <= 0) {
        continue;
    }

    $expectedGroup = $roleToGroup[(string) ($cand['selected_role'] ?? '')] ?? 'Floor';

    $selectSnapshot->execute([$candidateId]);
    $snapshotRows = $selectSnapshot->fetchAll();
    if (empty($snapshotRows)) {
        continue;
    }

    $snapshotByOrder = [];
    foreach ($snapshotRows as $row) {
        $order = (int) ($row['question_order'] ?? 0);
        if ($order <= 0 || isset($snapshotByOrder[$order])) {
            continue;
        }
        $snapshotByOrder[$order] = $row;
    }
    if (empty($snapshotByOrder)) {
        continue;
    }

    $selectAnswers->execute([$candidateId]);
    $answerRows = $selectAnswers->fetchAll();
    if (empty($answerRows)) {
        continue;
    }

    $byOrder = [];
    $mismatchedGroups = 0;
    $expectedNorm = toLower($expectedGroup);
    foreach ($answerRows as $row) {
        $order = (int) ($row['question_order_snapshot'] ?? 0);
        if ($order <= 0) {
            continue;
        }
        if (!isset($byOrder[$order])) {
            $byOrder[$order] = [];
        }
        $byOrder[$order][] = $row;

        $g = trim((string) ($row['role_group_snapshot'] ?? ''));
        if ($g !== '' && toLower($g) !== $expectedNorm) {
            $mismatchedGroups++;
        }
    }

    $normalizedRows = [];
    foreach ($snapshotByOrder as $order => $snap) {
        $selected = pick_best_row_for_order($byOrder[$order] ?? [], $expectedGroup);
        $sourceId = (int) ($snap['source_essay_question_id'] ?? 0);
        $essayQuestionId = $sourceId > 0 ? $sourceId : (int) ($snap['id'] ?? 0);
        $normalizedRows[] = [
            'candidate_id' => $candidateId,
            'essay_question_id' => $essayQuestionId,
            'role_group_snapshot' => $expectedGroup,
            'question_order_snapshot' => (int) $order,
            'question_text_snapshot' => (string) ($snap['question_text'] ?? ''),
            'answer_text' => trim((string) ($selected['answer_text'] ?? '')),
            'created_at' => (string) ($selected['created_at'] ?? gmdate('c')),
        ];
    }

    $answerCount = count($answerRows);
    $snapshotCount = count($snapshotByOrder);
    $shouldNormalize = ($mismatchedGroups > 0) || ($answerCount !== $snapshotCount);
    if (!$shouldNormalize) {
        continue;
    }

    $plan[] = [
        'candidate_id' => $candidateId,
        'full_name' => (string) ($cand['full_name'] ?? ''),
        'selected_role' => (string) ($cand['selected_role'] ?? ''),
        'expected_group' => $expectedGroup,
        'answer_count' => $answerCount,
        'snapshot_count' => $snapshotCount,
        'mismatched_groups' => $mismatchedGroups,
        'rows_before' => $answerRows,
        'rows_after' => $normalizedRows,
    ];
}

echo "DB: {$dbPath}\n";
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo 'Candidates planned: ' . count($plan) . "\n\n";

if (empty($plan)) {
    echo "No candidates require normalization.\n";
    exit(0);
}

foreach ($plan as $p) {
    echo '#'.$p['candidate_id'].' '.$p['full_name'].' | Role='.$p['selected_role'].' | Expected='.$p['expected_group']
        .' | answer='.$p['answer_count'].' | snapshot='.$p['snapshot_count'].' | mismatch='.$p['mismatched_groups']."\n";
}

if (!$apply) {
    echo "\nDry-run finished. Re-run with --apply to execute backup + normalization.\n";
    exit(0);
}

$runId = gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$backupAt = gmdate('c');

$pdo->beginTransaction();
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS essay_answers_backup_legacy_cleanup (
            backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT NOT NULL,
            backed_up_at TEXT NOT NULL,
            original_id INTEGER,
            candidate_id INTEGER NOT NULL,
            essay_question_id INTEGER NOT NULL,
            role_group_snapshot TEXT,
            question_order_snapshot INTEGER,
            question_text_snapshot TEXT,
            answer_text TEXT,
            created_at TEXT
        )'
    );

    $insBackup = $pdo->prepare(
        'INSERT INTO essay_answers_backup_legacy_cleanup
            (run_id, backed_up_at, original_id, candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $delAnswers = $pdo->prepare('DELETE FROM essay_answers WHERE candidate_id = ?');
    $insAnswer = $pdo->prepare(
        'INSERT INTO essay_answers
            (candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($plan as $p) {
        foreach ($p['rows_before'] as $row) {
            $insBackup->execute([
                $runId,
                $backupAt,
                (int) ($row['id'] ?? 0),
                (int) ($row['candidate_id'] ?? 0),
                (int) ($row['essay_question_id'] ?? 0),
                (string) ($row['role_group_snapshot'] ?? ''),
                (int) ($row['question_order_snapshot'] ?? 0),
                (string) ($row['question_text_snapshot'] ?? ''),
                (string) ($row['answer_text'] ?? ''),
                (string) ($row['created_at'] ?? ''),
            ]);
        }

        $delAnswers->execute([(int) $p['candidate_id']]);
        foreach ($p['rows_after'] as $row) {
            $insAnswer->execute([
                (int) $row['candidate_id'],
                (int) $row['essay_question_id'],
                (string) $row['role_group_snapshot'],
                (int) $row['question_order_snapshot'],
                (string) $row['question_text_snapshot'],
                (string) $row['answer_text'],
                (string) $row['created_at'],
            ]);
        }
    }

    $pdo->commit();
    echo "\nApply success.\n";
    echo "Backup table: essay_answers_backup_legacy_cleanup\n";
    echo "Run ID: {$runId}\n";
    echo "Backed up rows: " . array_sum(array_map(static fn(array $p): int => count($p['rows_before']), $plan)) . "\n";
    echo "Inserted normalized rows: " . array_sum(array_map(static fn(array $p): int => count($p['rows_after']), $plan)) . "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Apply failed: " . $e->getMessage() . "\n");
    exit(1);
}
