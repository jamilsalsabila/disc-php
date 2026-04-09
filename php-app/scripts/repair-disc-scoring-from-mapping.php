#!/usr/bin/env php
<?php

declare(strict_types=1);

function usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/repair-disc-scoring-from-mapping.php [--db=storage/disc_app.sqlite] [--candidate=ID] [--apply]\n\n";
    echo "Modes:\n";
    echo "  (default) Dry-run only, no DB changes\n";
    echo "  --apply   Execute backup + repair\n";
}

function get_opt_value(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function map_disc_by_option(?string $optionCode, array $row): ?string
{
    $code = strtoupper(trim((string) $optionCode));
    if ($code === 'A') {
        return strtoupper(trim((string) ($row['disc_a'] ?? '')));
    }
    if ($code === 'B') {
        return strtoupper(trim((string) ($row['disc_b'] ?? '')));
    }
    if ($code === 'C') {
        return strtoupper(trim((string) ($row['disc_c'] ?? '')));
    }
    if ($code === 'D') {
        return strtoupper(trim((string) ($row['disc_d'] ?? '')));
    }
    return null;
}

$argv = $_SERVER['argv'] ?? [];
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    usage();
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$dbArg = get_opt_value($argv, '--db=');
$candidateArg = get_opt_value($argv, '--candidate=');
$candidateFilter = ($candidateArg !== null && ctype_digit($candidateArg)) ? (int) $candidateArg : null;

require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/scoring.php';
require_once dirname(__DIR__) . '/app/questions.php';

$config = require dirname(__DIR__) . '/app/config.php';
$defaultDb = (string) ($config['db_path'] ?? (dirname(__DIR__) . '/storage/disc_app.sqlite'));
$dbPath = $dbArg !== null && trim($dbArg) !== '' ? trim($dbArg) : $defaultDb;

if (!is_file($dbPath)) {
    fwrite(STDERR, "DB file not found: {$dbPath}\n");
    exit(1);
}

$config['db_path'] = $dbPath;
$pdo = db($config);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA busy_timeout = 5000');

$sqlCandidates = "SELECT * FROM candidates WHERE status IN ('submitted','timeout_submitted')";
$params = [];
if ($candidateFilter !== null) {
    $sqlCandidates .= ' AND id = :id';
    $params[':id'] = $candidateFilter;
}
$sqlCandidates .= ' ORDER BY id ASC';

$stmtCandidates = $pdo->prepare($sqlCandidates);
$stmtCandidates->execute($params);
$candidates = $stmtCandidates->fetchAll();

$stmtAnswers = $pdo->prepare(
    'SELECT a.id, a.question_id, a.answer_type, a.option_code, a.disc_value, q.disc_a, q.disc_b, q.disc_c, q.disc_d
     FROM answers a
     LEFT JOIN questions_bank q ON q.id = a.question_id
     WHERE a.candidate_id = ?
     ORDER BY a.question_id ASC, a.answer_type ASC, a.id ASC'
);

$plan = [];
$totalQuestions = count(list_questions($pdo, false, null));
$minimumRequired = (int) ceil($totalQuestions * (float) ($config['min_completion_ratio'] ?? 0.8));

foreach ($candidates as $candidate) {
    $candidateId = (int) ($candidate['id'] ?? 0);
    if ($candidateId <= 0) {
        continue;
    }

    $stmtAnswers->execute([$candidateId]);
    $rows = $stmtAnswers->fetchAll();
    if (empty($rows)) {
        continue;
    }

    $answersByQuestion = [];
    $answerDiscFixes = [];
    foreach ($rows as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }

        $answerType = strtolower(trim((string) ($row['answer_type'] ?? '')));
        if ($answerType !== 'most' && $answerType !== 'least') {
            continue;
        }

        $mappedDisc = map_disc_by_option((string) ($row['option_code'] ?? ''), $row);
        $oldDisc = strtoupper(trim((string) ($row['disc_value'] ?? '')));
        if (!in_array((string) $mappedDisc, ['D', 'I', 'S', 'C'], true)) {
            $mappedDisc = in_array($oldDisc, ['D', 'I', 'S', 'C'], true) ? $oldDisc : null;
        }
        if ($mappedDisc === null) {
            continue;
        }

        if (!isset($answersByQuestion[$qid])) {
            $answersByQuestion[$qid] = [];
        }
        $answersByQuestion[$qid][$answerType . 'Disc'] = $mappedDisc;

        if ($oldDisc !== $mappedDisc) {
            $answerDiscFixes[] = [
                'answer_id' => (int) ($row['id'] ?? 0),
                'question_id' => $qid,
                'answer_type' => $answerType,
                'option_code' => (string) ($row['option_code'] ?? ''),
                'old_disc_value' => $oldDisc,
                'new_disc_value' => $mappedDisc,
            ];
        }
    }

    // Keep only complete pairs.
    foreach ($answersByQuestion as $qid => $pair) {
        if (!isset($pair['mostDisc']) || !isset($pair['leastDisc'])) {
            unset($answersByQuestion[$qid]);
        }
    }

    if (empty($answersByQuestion)) {
        continue;
    }

    $baseEvaluation = evaluate_candidate($answersByQuestion, (string) ($candidate['selected_role'] ?? ''));
    $answeredCount = count($answersByQuestion);
    $evaluation = ($totalQuestions > 0 && $answeredCount < $minimumRequired)
        ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
        : $baseEvaluation;

    $newDisc = $evaluation['discCounts'] ?? ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];
    $newRoleScores = $evaluation['roleScores'] ?? [];
    $newRecommendation = (string) ($evaluation['recommendation'] ?? 'TIDAK_DIREKOMENDASIKAN');
    $newReason = (string) ($evaluation['reason'] ?? '');

    $oldDisc = [
        'D' => (int) ($candidate['disc_d'] ?? 0),
        'I' => (int) ($candidate['disc_i'] ?? 0),
        'S' => (int) ($candidate['disc_s'] ?? 0),
        'C' => (int) ($candidate['disc_c'] ?? 0),
    ];

    $candidateNeedsUpdate =
        $oldDisc['D'] !== (int) ($newDisc['D'] ?? 0)
        || $oldDisc['I'] !== (int) ($newDisc['I'] ?? 0)
        || $oldDisc['S'] !== (int) ($newDisc['S'] ?? 0)
        || $oldDisc['C'] !== (int) ($newDisc['C'] ?? 0)
        || (string) ($candidate['recommendation'] ?? '') !== $newRecommendation
        || (string) ($candidate['reason'] ?? '') !== $newReason;

    if (!$candidateNeedsUpdate && empty($answerDiscFixes)) {
        continue;
    }

    $plan[] = [
        'candidate' => $candidate,
        'answers_by_question' => $answersByQuestion,
        'answer_disc_fixes' => $answerDiscFixes,
        'evaluation' => $evaluation,
    ];
}

echo "DB: {$dbPath}\n";
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo 'Candidates planned: ' . count($plan) . "\n\n";

foreach ($plan as $item) {
    $c = $item['candidate'];
    $ev = $item['evaluation'];
    $old = 'D=' . (int) ($c['disc_d'] ?? 0) . ',I=' . (int) ($c['disc_i'] ?? 0) . ',S=' . (int) ($c['disc_s'] ?? 0) . ',C=' . (int) ($c['disc_c'] ?? 0);
    $new = 'D=' . (int) (($ev['discCounts']['D'] ?? 0)) . ',I=' . (int) (($ev['discCounts']['I'] ?? 0)) . ',S=' . (int) (($ev['discCounts']['S'] ?? 0)) . ',C=' . (int) (($ev['discCounts']['C'] ?? 0));
    echo '#'.(int) ($c['id'] ?? 0).' '.(string) ($c['full_name'] ?? '')
        .' | role='.(string) ($c['selected_role'] ?? '-')
        .' | old=['.$old.'] -> new=['.$new.']'
        .' | rec='.(string) ($c['recommendation'] ?? '-').' -> '.(string) ($ev['recommendation'] ?? '-')
        .' | answer_disc_fixes='.count($item['answer_disc_fixes'])."\n";
}

if (!$apply) {
    echo "\nDry-run finished. Re-run with --apply to execute backup + repair.\n";
    exit(0);
}

$runId = gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$backedUpAt = gmdate('c');

$pdo->beginTransaction();
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS disc_repair_candidates_backup (
            backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT NOT NULL,
            backed_up_at TEXT NOT NULL,
            candidate_id INTEGER NOT NULL,
            recommendation TEXT,
            reason TEXT,
            role_scores_json TEXT,
            disc_d INTEGER,
            disc_i INTEGER,
            disc_s INTEGER,
            disc_c INTEGER,
            score_server INTEGER,
            score_beverage INTEGER,
            score_cook INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS disc_repair_answers_backup (
            backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT NOT NULL,
            backed_up_at TEXT NOT NULL,
            answer_id INTEGER NOT NULL,
            candidate_id INTEGER NOT NULL,
            question_id INTEGER NOT NULL,
            answer_type TEXT NOT NULL,
            option_code TEXT,
            old_disc_value TEXT,
            new_disc_value TEXT
        )'
    );

    $insCandidateBackup = $pdo->prepare(
        'INSERT INTO disc_repair_candidates_backup
            (run_id, backed_up_at, candidate_id, recommendation, reason, role_scores_json, disc_d, disc_i, disc_s, disc_c, score_server, score_beverage, score_cook)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insAnswerBackup = $pdo->prepare(
        'INSERT INTO disc_repair_answers_backup
            (run_id, backed_up_at, answer_id, candidate_id, question_id, answer_type, option_code, old_disc_value, new_disc_value)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $updAnswerDisc = $pdo->prepare('UPDATE answers SET disc_value = ? WHERE id = ?');
    $updCandidate = $pdo->prepare(
        'UPDATE candidates
         SET recommendation = ?, reason = ?, role_scores_json = ?, disc_d = ?, disc_i = ?, disc_s = ?, disc_c = ?, score_server = ?, score_beverage = ?, score_cook = ?
         WHERE id = ?'
    );

    foreach ($plan as $item) {
        $c = $item['candidate'];
        $ev = $item['evaluation'];
        $candidateId = (int) ($c['id'] ?? 0);

        $insCandidateBackup->execute([
            $runId,
            $backedUpAt,
            $candidateId,
            (string) ($c['recommendation'] ?? ''),
            (string) ($c['reason'] ?? ''),
            (string) ($c['role_scores_json'] ?? ''),
            (int) ($c['disc_d'] ?? 0),
            (int) ($c['disc_i'] ?? 0),
            (int) ($c['disc_s'] ?? 0),
            (int) ($c['disc_c'] ?? 0),
            (int) ($c['score_server'] ?? 0),
            (int) ($c['score_beverage'] ?? 0),
            (int) ($c['score_cook'] ?? 0),
        ]);

        foreach ($item['answer_disc_fixes'] as $fix) {
            $insAnswerBackup->execute([
                $runId,
                $backedUpAt,
                (int) ($fix['answer_id'] ?? 0),
                $candidateId,
                (int) ($fix['question_id'] ?? 0),
                (string) ($fix['answer_type'] ?? ''),
                (string) ($fix['option_code'] ?? ''),
                (string) ($fix['old_disc_value'] ?? ''),
                (string) ($fix['new_disc_value'] ?? ''),
            ]);
            $updAnswerDisc->execute([
                (string) ($fix['new_disc_value'] ?? ''),
                (int) ($fix['answer_id'] ?? 0),
            ]);
        }

        $roleScores = is_array($ev['roleScores'] ?? null) ? $ev['roleScores'] : [];
        $updCandidate->execute([
            (string) ($ev['recommendation'] ?? 'TIDAK_DIREKOMENDASIKAN'),
            (string) ($ev['reason'] ?? ''),
            json_encode($roleScores, JSON_UNESCAPED_UNICODE),
            (int) (($ev['discCounts']['D'] ?? 0)),
            (int) (($ev['discCounts']['I'] ?? 0)),
            (int) (($ev['discCounts']['S'] ?? 0)),
            (int) (($ev['discCounts']['C'] ?? 0)),
            (int) ($roleScores['SERVER'] ?? $roleScores['FLOOR_CREW'] ?? $roleScores['SERVER_SPECIALIST'] ?? 0),
            (int) ($roleScores['MIXOLOGIST'] ?? $roleScores['BAR_CREW'] ?? $roleScores['BEVERAGE_SPECIALIST'] ?? 0),
            (int) ($roleScores['COOK'] ?? $roleScores['KITCHEN_CREW'] ?? $roleScores['SENIOR_COOK'] ?? 0),
            $candidateId,
        ]);
    }

    $pdo->commit();
    echo "\nRepair finished.\n";
    echo 'run_id=' . $runId . "\n";
    echo 'candidates_fixed=' . count($plan) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Repair failed: ' . $e->getMessage() . "\n");
    exit(1);
}
