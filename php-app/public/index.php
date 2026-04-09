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

function default_essay_group_options(): array
{
    return ['Manager', 'Back office', 'Head Kitchen', 'Kitchen', 'Bar', 'Floor'];
}

function load_role_options_for_candidate(PDO $pdo, array $config): array
{
    $rows = list_role_catalog($pdo, false);
    $options = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['role_name'] ?? ''));
        if ($name !== '') {
            $options[] = $name;
        }
    }
    if (!empty($options)) {
        return $options;
    }
    return is_array($config['role_options'] ?? null) ? array_values($config['role_options']) : [];
}

function load_role_options_for_hr(PDO $pdo, array $config): array
{
    $rows = list_role_catalog($pdo, true);
    $options = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['role_name'] ?? ''));
        if ($name !== '') {
            $options[] = $name;
        }
    }
    if (!empty($options)) {
        return $options;
    }
    return is_array($config['role_options'] ?? null) ? array_values($config['role_options']) : [];
}

function load_essay_group_options(PDO $pdo): array
{
    $rows = list_essay_group_catalog($pdo, true);
    $options = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['group_name'] ?? ''));
        if ($name !== '') {
            $options[] = $name;
        }
    }
    if (!empty($options)) {
        return $options;
    }
    return default_essay_group_options();
}

function essay_group_by_selected_role(PDO $pdo, string $selectedRole): string
{
    static $map = null;
    if (!is_array($map)) {
        $map = [];
        foreach (list_role_catalog($pdo, true) as $row) {
            $name = trim((string) ($row['role_name'] ?? ''));
            $group = trim((string) ($row['essay_group'] ?? ''));
            if ($name !== '' && $group !== '') {
                $map[$name] = $group;
            }
        }
    }

    if (isset($map[$selectedRole])) {
        return (string) $map[$selectedRole];
    }

    return 'Floor';
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

function focus_loss_severity_from_events(array $events, array $candidate = []): array
{
    $tabSwitches = 0;
    $beforeUnload = 0;
    foreach ($events as $ev) {
        $type = strtolower(trim((string) ($ev['event_type'] ?? '')));
        if ($type === 'tab_switch') {
            $tabSwitches++;
        } elseif ($type === 'before_unload') {
            $beforeUnload++;
        }
    }

    $durationSec = max(1, (int) ($candidate['duration_seconds'] ?? 0));
    $score = ($tabSwitches * 3) + ($beforeUnload * 2);
    $switchPer10Min = ($tabSwitches * 600) / max(60, $durationSec);

    if ($tabSwitches >= 8) {
        $score += 5;
    } elseif ($tabSwitches >= 4) {
        $score += 2;
    }

    if ($switchPer10Min >= 6) {
        $score += 4;
    } elseif ($switchPer10Min >= 3) {
        $score += 2;
    }

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
        'before_unload' => $beforeUnload,
        'switch_per_10min' => round($switchPer10Min, 2),
    ];
}

function latency_paste_anomaly_from_rows(array $rows): array
{
    $score = 0;
    $flaggedRows = 0;

    foreach ($rows as $row) {
        $chars = max(0, (int) ($row['total_chars'] ?? 0));
        $paste = max(0, (int) ($row['paste_count'] ?? 0));
        $keystrokes = max(0, (int) ($row['keystrokes'] ?? 0));
        $inputEvents = max(0, (int) ($row['input_events'] ?? 0));
        $activeSec = max(1.0, ((int) ($row['active_ms'] ?? 0)) / 1000);

        $localScore = 0;
        if ($chars >= 180 && $activeSec <= 20) {
            $localScore += 5;
        }
        if ($chars >= 240 && $activeSec <= 30) {
            $localScore += 4;
        }
        if ($paste >= 1 && $chars >= 120) {
            $localScore += 3;
        }
        if ($paste >= 2) {
            $localScore += 5;
        }
        if ($chars >= 200 && $keystrokes <= 25) {
            $localScore += 4;
        }
        if ($chars >= 160 && $inputEvents <= 5) {
            $localScore += 3;
        }

        if ($localScore > 0) {
            $flaggedRows++;
            $score += $localScore;
        }
    }

    $level = 'Low';
    if ($score >= 16) {
        $level = 'High';
    } elseif ($score >= 8) {
        $level = 'Medium';
    }

    return [
        'level' => $level,
        'score' => $score,
        'flagged_rows' => $flaggedRows,
    ];
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

function journey_event_label(?string $eventKey): string
{
    $map = [
        'candidate_created' => 'Kandidat dibuat',
        'disc_page_open' => 'Halaman DISC dibuka',
        'disc_autosave' => 'Autosave DISC',
        'disc_submit_attempt' => 'Coba submit DISC',
        'disc_submitted' => 'DISC tersimpan',
        'essay_snapshot_created' => 'Snapshot soal esai dibuat',
        'essay_page_open' => 'Halaman esai dibuka',
        'essay_autosave' => 'Autosave esai',
        'typing_metrics_saved' => 'Typing metrics tersimpan',
        'essay_submit_attempt' => 'Coba submit esai',
        'essay_submitted' => 'Esai tersubmit',
        'auto_timeout_submit' => 'Auto-submit timeout',
    ];
    $key = strtolower(trim((string) $eventKey));
    if ($key === '') {
        return '-';
    }
    return $map[$key] ?? (string) $eventKey;
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
        $mostDisc = strtoupper(trim((string) ($entry['most']['disc'] ?? '')));
        $leastDisc = strtoupper(trim((string) ($entry['least']['disc'] ?? '')));
        if (!in_array($mostDisc, ['D', 'I', 'S', 'C'], true)) {
            $mostDisc = (string) (OPTION_TO_DISC[$mostCode] ?? '');
        }
        if (!in_array($leastDisc, ['D', 'I', 'S', 'C'], true)) {
            $leastDisc = (string) (OPTION_TO_DISC[$leastCode] ?? '');
        }
        $answers[$qid] = [
            'most' => ['optionCode' => $mostCode, 'disc' => $mostDisc !== '' ? $mostDisc : null],
            'least' => ['optionCode' => $leastCode, 'disc' => $leastDisc !== '' ? $leastDisc : null],
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

function count_filled_essay_answers(array $answers): int
{
    $count = 0;
    foreach ($answers as $text) {
        if (trim((string) $text) !== '') {
            $count++;
        }
    }
    return $count;
}

function merge_essay_answers_with_fallback(array $primary, array $fallback): array
{
    $merged = [];
    foreach ($fallback as $qidRaw => $text) {
        $qid = (int) $qidRaw;
        $value = trim((string) $text);
        if ($qid > 0 && $value !== '') {
            $merged[$qid] = $value;
        }
    }
    foreach ($primary as $qidRaw => $text) {
        $qid = (int) $qidRaw;
        $value = trim((string) $text);
        if ($qid > 0 && $value !== '') {
            $merged[$qid] = $value;
        }
    }
    ksort($merged);
    return $merged;
}

function remap_draft_essay_answers_to_snapshot(array $draftAnswers, array $essayQuestions): array
{
    if (empty($draftAnswers) || empty($essayQuestions)) {
        return $draftAnswers;
    }

    $snapshotIds = [];
    $sourceToSnapshot = [];
    foreach ($essayQuestions as $q) {
        $snapshotId = (int) ($q['id'] ?? 0);
        if ($snapshotId <= 0) {
            continue;
        }
        $snapshotIds[$snapshotId] = true;
        $sourceId = (int) ($q['source_essay_question_id'] ?? 0);
        if ($sourceId > 0 && !isset($sourceToSnapshot[$sourceId])) {
            $sourceToSnapshot[$sourceId] = $snapshotId;
        }
    }

    $normalized = [];
    foreach ($draftAnswers as $qidRaw => $text) {
        $qid = (int) $qidRaw;
        $answerText = trim((string) $text);
        if ($qid <= 0 || $answerText === '') {
            continue;
        }

        if (isset($snapshotIds[$qid])) {
            $normalized[$qid] = $answerText;
            continue;
        }

        if (isset($sourceToSnapshot[$qid])) {
            $normalized[$sourceToSnapshot[$qid]] = $answerText;
        }
    }

    return $normalized;
}

function format_journey_payload_text(string $eventKey, array $payload): string
{
    if ($eventKey === 'essay_autosave') {
        $filled = isset($payload['filled_count']) ? (int) $payload['filled_count'] : null;
        $total = isset($payload['total_count']) ? (int) $payload['total_count'] : null;
        $saved = isset($payload['saved_count']) ? (int) $payload['saved_count'] : null;
        $parts = [];
        if ($filled !== null && $total !== null && $total > 0) {
            $parts[] = 'terisi=' . $filled . '/' . $total;
        } elseif ($saved !== null) {
            $parts[] = 'tersimpan=' . $saved;
        }
        if (isset($payload['group'])) {
            $parts[] = 'kelompok=' . (string) $payload['group'];
        }
        if (isset($payload['last_autosave_at'])) {
            $parts[] = 'autosave=' . (string) $payload['last_autosave_at'];
        }
        return !empty($parts) ? implode(' | ', $parts) : '-';
    }

    if ($eventKey === 'typing_metrics_saved') {
        $saved = isset($payload['saved_count']) ? (int) $payload['saved_count'] : null;
        return $saved !== null ? ('baris_metrics=' . $saved) : '-';
    }

    $parts = [];
    foreach ($payload as $k => $v) {
        if (is_array($v)) {
            $v = implode(',', array_map('strval', $v));
        } elseif (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        $parts[] = (string) $k . '=' . (string) $v;
    }
    return !empty($parts) ? implode(' | ', $parts) : '-';
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
    $rows = [];
    $fallbackNo = 1;
    foreach ($answers as $answer) {
        $order = (int) ($answer['question_order'] ?? 0);
        $rows[] = [
            'id' => $order > 0 ? $order : $fallbackNo,
            'most' => (string) (($answer['most_code'] ?? '') !== '' ? $answer['most_code'] : '-'),
            'least' => (string) (($answer['least_code'] ?? '') !== '' ? $answer['least_code'] : '-'),
        ];
        $fallbackNo++;
    }

    return $rows;
}

function normalize_essay_profile_rows(array $rows, ?string $expectedGroup = null, array $expectedQuestions = [], bool $includeUnanswered = false): array
{
    $expectedGroupNorm = trim((string) ($expectedGroup ?? ''));
    $map = [];
    $idx = 0;
    $unresolved = [];
    foreach ($rows as $row) {
        $qid = (int) ($row['essay_question_id'] ?? 0);
        $group = trim((string) ($row['role_group'] ?? ''));
        $question = trim((string) ($row['question_text'] ?? ''));
        $answer = trim((string) ($row['answer_text'] ?? ''));

        if ($expectedGroupNorm !== '' && $group !== '' && strcasecmp($group, $expectedGroupNorm) !== 0) {
            continue;
        }

        if ($answer === '') {
            continue;
        }

        $order = (int) ($row['question_order'] ?? 0);
        if ($order <= 0 || $question === '' || $group === '') {
            $unresolved[] = $row;
            continue;
        }

        $key = $qid > 0 ? 'q' . $qid : 'r' . $idx;
        $idx++;
        if (!isset($map[$key])) {
            $map[$key] = $row;
            continue;
        }

        $existingQuestion = trim((string) ($map[$key]['question_text'] ?? ''));
        $incomingQuestion = $question;
        if (mb_strlen($incomingQuestion) > mb_strlen($existingQuestion)) {
            $map[$key] = $row;
        }
    }

    $expectedByOrder = [];
    foreach ($expectedQuestions as $q) {
        $order = (int) ($q['order'] ?? 0);
        if ($order <= 0) {
            continue;
        }
        $expectedByOrder[$order] = [
            'role_group' => (string) ($q['role_group'] ?? $expectedGroup ?? ''),
            'question_order' => $order,
            'question_text' => (string) ($q['question_text'] ?? ''),
        ];
    }

    if (!empty($unresolved) && !empty($expectedByOrder)) {
        $usedOrders = [];
        foreach ($map as $row) {
            $o = (int) ($row['question_order'] ?? 0);
            if ($o > 0) {
                $usedOrders[$o] = true;
            }
        }

        $missingOrders = [];
        foreach (array_keys($expectedByOrder) as $o) {
            if (!isset($usedOrders[$o])) {
                $missingOrders[] = (int) $o;
            }
        }
        sort($missingOrders);

        foreach ($unresolved as $row) {
            if (empty($missingOrders)) {
                break;
            }
            $order = array_shift($missingOrders);
            $qid = (int) ($row['essay_question_id'] ?? 0);
            $row['role_group'] = $expectedByOrder[$order]['role_group'] ?? ($expectedGroup ?? '');
            $row['question_order'] = $order;
            $row['question_text'] = $expectedByOrder[$order]['question_text'] ?? '';

            $key = $qid > 0 ? 'q' . $qid : 'fallback_' . $order;
            if (!isset($map[$key])) {
                $map[$key] = $row;
            }
        }
    }

    if ($includeUnanswered && !empty($expectedByOrder)) {
        $usedOrders = [];
        foreach ($map as $row) {
            $o = (int) ($row['question_order'] ?? 0);
            if ($o > 0) {
                $usedOrders[$o] = true;
            }
        }

        foreach ($expectedByOrder as $order => $q) {
            $order = (int) $order;
            if ($order <= 0 || isset($usedOrders[$order])) {
                continue;
            }
            $map['missing_' . $order] = [
                'essay_question_id' => (int) ($q['id'] ?? 0),
                'role_group' => (string) ($q['role_group'] ?? $expectedGroup ?? ''),
                'question_order' => $order,
                'question_text' => (string) ($q['question_text'] ?? ''),
                'answer_text' => '',
            ];
        }
    }

    $values = array_values($map);
    usort($values, static function (array $a, array $b): int {
        $oa = (int) ($a['question_order'] ?? 0);
        $ob = (int) ($b['question_order'] ?? 0);
        if ($oa !== $ob) {
            if ($oa <= 0) {
                return 1;
            }
            if ($ob <= 0) {
                return -1;
            }
            return $oa <=> $ob;
        }
        $qa = (int) ($a['essay_question_id'] ?? 0);
        $qb = (int) ($b['essay_question_id'] ?? 0);
        return $qa <=> $qb;
    });

    return $values;
}

function build_expected_essay_questions_for_display(PDO $pdo, int $candidateId, ?string $expectedGroup = null): array
{
    $snapshotQuestions = list_candidate_essay_questions($pdo, $candidateId);
    $expectedGroupNorm = trim((string) ($expectedGroup ?? ''));
    if ($expectedGroupNorm !== '') {
        $snapshotQuestions = array_values(array_filter(
            $snapshotQuestions,
            static function (array $q) use ($expectedGroupNorm): bool {
                $g = trim((string) ($q['role_group'] ?? ''));
                return $g !== '' && strcasecmp($g, $expectedGroupNorm) === 0;
            }
        ));
    }
    $activeQuestions = [];
    if ($expectedGroupNorm !== '') {
        $activeQuestions = list_essay_questions($pdo, true, $expectedGroupNorm, 'order', 'asc');
    }

    if (empty($snapshotQuestions) && empty($activeQuestions)) {
        return [];
    }
    if (empty($snapshotQuestions)) {
        return $activeQuestions;
    }
    if (empty($activeQuestions)) {
        return $snapshotQuestions;
    }

    $byOrder = [];
    foreach ($snapshotQuestions as $q) {
        $order = (int) ($q['order'] ?? 0);
        if ($order <= 0) {
            continue;
        }
        $byOrder[$order] = $q;
    }

    foreach ($activeQuestions as $q) {
        $order = (int) ($q['order'] ?? 0);
        if ($order <= 0 || isset($byOrder[$order])) {
            continue;
        }
        $byOrder[$order] = $q;
    }

    if (!empty($byOrder)) {
        ksort($byOrder);
        return array_values($byOrder);
    }

    // Fallback for edge cases with invalid/missing order.
    return !empty($snapshotQuestions) ? $snapshotQuestions : $activeQuestions;
}

function normalize_legacy_essay_data(PDO $pdo): array
{
    $roleRows = list_role_catalog($pdo, true);
    $roleToGroup = [];
    foreach ($roleRows as $row) {
        $roleName = trim((string) ($row['role_name'] ?? ''));
        $essayGroup = trim((string) ($row['essay_group'] ?? ''));
        if ($roleName !== '' && $essayGroup !== '') {
            $roleToGroup[$roleName] = $essayGroup;
        }
    }

    $candidateRows = $pdo->query(
        "SELECT DISTINCT c.id, c.full_name, c.selected_role
         FROM candidates c
         LEFT JOIN essay_answers ea ON ea.candidate_id = c.id
         LEFT JOIN candidate_essay_questions ceq ON ceq.candidate_id = c.id
         WHERE ea.id IS NOT NULL OR ceq.id IS NOT NULL
         ORDER BY c.id ASC"
    )->fetchAll();

    $runId = gmdate('Ymd_His') . '_online_fix_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $now = now_iso();

    $stats = [
        'run_id' => $runId,
        'scanned_candidates' => count($candidateRows),
        'snapshot_fixed_candidates' => 0,
        'answers_fixed_candidates' => 0,
        'snapshot_backup_rows' => 0,
        'answers_backup_rows' => 0,
        'answers_inserted_rows' => 0,
    ];

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS candidate_essay_questions_backup_group_fix (
                backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                backed_up_at TEXT NOT NULL,
                id INTEGER NOT NULL,
                candidate_id INTEGER NOT NULL,
                source_essay_question_id INTEGER,
                role_group TEXT,
                question_order INTEGER,
                question_text TEXT,
                guidance_text TEXT,
                created_at TEXT
            )'
        );
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

        $selectSnapshot = $pdo->prepare(
            'SELECT id, candidate_id, source_essay_question_id, role_group, question_order, question_text, guidance_text, created_at
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
        $insSnapshotBackup = $pdo->prepare(
            'INSERT INTO candidate_essay_questions_backup_group_fix
                (run_id, backed_up_at, id, candidate_id, source_essay_question_id, role_group, question_order, question_text, guidance_text, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateSnapshotGroup = $pdo->prepare('UPDATE candidate_essay_questions SET role_group = ? WHERE id = ?');
        $insAnswerBackup = $pdo->prepare(
            'INSERT INTO essay_answers_backup_legacy_cleanup
                (run_id, backed_up_at, original_id, candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $deleteAnswers = $pdo->prepare('DELETE FROM essay_answers WHERE candidate_id = ?');
        $insertAnswer = $pdo->prepare(
            'INSERT INTO essay_answers
                (candidate_id, essay_question_id, role_group_snapshot, question_order_snapshot, question_text_snapshot, answer_text, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($candidateRows as $cand) {
            $candidateId = (int) ($cand['id'] ?? 0);
            if ($candidateId <= 0) {
                continue;
            }
            $expectedGroup = $roleToGroup[(string) ($cand['selected_role'] ?? '')] ?? 'Floor';
            $expectedGroupNorm = strtolower(trim($expectedGroup));

            $selectSnapshot->execute([$candidateId]);
            $snapshotRows = $selectSnapshot->fetchAll();
            $snapshotByOrder = [];
            $snapshotNeedsFix = false;

            foreach ($snapshotRows as $row) {
                $order = (int) ($row['question_order'] ?? 0);
                if ($order > 0 && !isset($snapshotByOrder[$order])) {
                    $snapshotByOrder[$order] = $row;
                }
                $group = trim((string) ($row['role_group'] ?? ''));
                if ($group !== '' && strtolower($group) !== $expectedGroupNorm) {
                    $snapshotNeedsFix = true;
                }
            }

            if ($snapshotNeedsFix) {
                foreach ($snapshotRows as $row) {
                    $group = trim((string) ($row['role_group'] ?? ''));
                    if ($group === '' || strtolower($group) === $expectedGroupNorm) {
                        continue;
                    }
                    $insSnapshotBackup->execute([
                        $runId,
                        $now,
                        (int) ($row['id'] ?? 0),
                        $candidateId,
                        (int) ($row['source_essay_question_id'] ?? 0),
                        (string) ($row['role_group'] ?? ''),
                        (int) ($row['question_order'] ?? 0),
                        (string) ($row['question_text'] ?? ''),
                        (string) ($row['guidance_text'] ?? ''),
                        (string) ($row['created_at'] ?? ''),
                    ]);
                    $stats['snapshot_backup_rows']++;
                    $updateSnapshotGroup->execute([$expectedGroup, (int) ($row['id'] ?? 0)]);
                }
                $stats['snapshot_fixed_candidates']++;
            }

            $selectAnswers->execute([$candidateId]);
            $answerRows = $selectAnswers->fetchAll();
            if (empty($answerRows) || empty($snapshotByOrder)) {
                continue;
            }

            $byOrder = [];
            $mismatchGroupCount = 0;
            foreach ($answerRows as $row) {
                $order = (int) ($row['question_order_snapshot'] ?? 0);
                if ($order <= 0) {
                    continue;
                }
                if (!isset($byOrder[$order])) {
                    $byOrder[$order] = [];
                }
                $byOrder[$order][] = $row;
                $group = trim((string) ($row['role_group_snapshot'] ?? ''));
                if ($group !== '' && strtolower($group) !== $expectedGroupNorm) {
                    $mismatchGroupCount++;
                }
            }

            $needsAnswerFix = ($mismatchGroupCount > 0) || (count($answerRows) !== count($snapshotByOrder));
            if (!$needsAnswerFix) {
                continue;
            }

            foreach ($answerRows as $row) {
                $insAnswerBackup->execute([
                    $runId,
                    $now,
                    (int) ($row['id'] ?? 0),
                    $candidateId,
                    (int) ($row['essay_question_id'] ?? 0),
                    (string) ($row['role_group_snapshot'] ?? ''),
                    (int) ($row['question_order_snapshot'] ?? 0),
                    (string) ($row['question_text_snapshot'] ?? ''),
                    (string) ($row['answer_text'] ?? ''),
                    (string) ($row['created_at'] ?? ''),
                ]);
                $stats['answers_backup_rows']++;
            }

            $deleteAnswers->execute([$candidateId]);

            ksort($snapshotByOrder);
            foreach ($snapshotByOrder as $order => $snap) {
                $candidatesAtOrder = $byOrder[(int) $order] ?? [];
                $selected = null;
                $selectedScore = -1;
                foreach ($candidatesAtOrder as $row) {
                    $group = trim((string) ($row['role_group_snapshot'] ?? ''));
                    $answer = trim((string) ($row['answer_text'] ?? ''));
                    $groupScore = (strtolower($group) === $expectedGroupNorm) ? 200000 : 0;
                    $score = $groupScore + strlen($answer);
                    if ($score > $selectedScore) {
                        $selectedScore = $score;
                        $selected = $row;
                    }
                }

                $sourceId = (int) ($snap['source_essay_question_id'] ?? 0);
                $essayQuestionId = $sourceId > 0 ? $sourceId : (int) ($snap['id'] ?? 0);
                $insertAnswer->execute([
                    $candidateId,
                    $essayQuestionId,
                    $expectedGroup,
                    (int) $order,
                    (string) ($snap['question_text'] ?? ''),
                    trim((string) ($selected['answer_text'] ?? '')),
                    (string) ($selected['created_at'] ?? $now),
                ]);
                $stats['answers_inserted_rows']++;
            }

            $stats['answers_fixed_candidates']++;
        }

        $pdo->commit();
        return $stats;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function preview_legacy_essay_data(PDO $pdo): array
{
    $roleRows = list_role_catalog($pdo, true);
    $roleToGroup = [];
    foreach ($roleRows as $row) {
        $roleName = trim((string) ($row['role_name'] ?? ''));
        $essayGroup = trim((string) ($row['essay_group'] ?? ''));
        if ($roleName !== '' && $essayGroup !== '') {
            $roleToGroup[$roleName] = $essayGroup;
        }
    }

    $candidateRows = $pdo->query(
        "SELECT DISTINCT c.id, c.selected_role
         FROM candidates c
         LEFT JOIN essay_answers ea ON ea.candidate_id = c.id
         LEFT JOIN candidate_essay_questions ceq ON ceq.candidate_id = c.id
         WHERE ea.id IS NOT NULL OR ceq.id IS NOT NULL
         ORDER BY c.id ASC"
    )->fetchAll();

    $stats = [
        'scanned_candidates' => count($candidateRows),
        'snapshot_fixed_candidates' => 0,
        'answers_fixed_candidates' => 0,
        'snapshot_backup_rows' => 0,
        'answers_backup_rows' => 0,
        'answers_inserted_rows' => 0,
    ];

    $selectSnapshot = $pdo->prepare(
        'SELECT id, role_group, question_order
         FROM candidate_essay_questions
         WHERE candidate_id = ?
         ORDER BY question_order ASC, id ASC'
    );
    $selectAnswers = $pdo->prepare(
        'SELECT id, role_group_snapshot, question_order_snapshot
         FROM essay_answers
         WHERE candidate_id = ?
         ORDER BY question_order_snapshot ASC, id ASC'
    );

    foreach ($candidateRows as $cand) {
        $candidateId = (int) ($cand['id'] ?? 0);
        if ($candidateId <= 0) {
            continue;
        }
        $expectedGroup = $roleToGroup[(string) ($cand['selected_role'] ?? '')] ?? 'Floor';
        $expectedGroupNorm = strtolower(trim($expectedGroup));

        $selectSnapshot->execute([$candidateId]);
        $snapshotRows = $selectSnapshot->fetchAll();
        $snapshotByOrder = [];
        $snapshotNeedsFix = false;
        foreach ($snapshotRows as $row) {
            $order = (int) ($row['question_order'] ?? 0);
            if ($order > 0 && !isset($snapshotByOrder[$order])) {
                $snapshotByOrder[$order] = true;
            }
            $group = trim((string) ($row['role_group'] ?? ''));
            if ($group !== '' && strtolower($group) !== $expectedGroupNorm) {
                $snapshotNeedsFix = true;
            }
        }
        if ($snapshotNeedsFix) {
            $stats['snapshot_fixed_candidates']++;
            $stats['snapshot_backup_rows'] += count($snapshotRows);
        }

        $selectAnswers->execute([$candidateId]);
        $answerRows = $selectAnswers->fetchAll();
        if (empty($answerRows) || empty($snapshotByOrder)) {
            continue;
        }
        $mismatchGroupCount = 0;
        foreach ($answerRows as $row) {
            $group = trim((string) ($row['role_group_snapshot'] ?? ''));
            if ($group !== '' && strtolower($group) !== $expectedGroupNorm) {
                $mismatchGroupCount++;
            }
        }
        $needsAnswerFix = ($mismatchGroupCount > 0) || (count($answerRows) !== count($snapshotByOrder));
        if ($needsAnswerFix) {
            $stats['answers_fixed_candidates']++;
            $stats['answers_backup_rows'] += count($answerRows);
            $stats['answers_inserted_rows'] += count($snapshotByOrder);
        }
    }

    return $stats;
}

function map_disc_by_option_code(string $optionCode, array $row): ?string
{
    $code = strtoupper(trim($optionCode));
    if ($code === 'A') {
        $disc = strtoupper(trim((string) ($row['disc_a'] ?? '')));
        return in_array($disc, ['D', 'I', 'S', 'C'], true) ? $disc : null;
    }
    if ($code === 'B') {
        $disc = strtoupper(trim((string) ($row['disc_b'] ?? '')));
        return in_array($disc, ['D', 'I', 'S', 'C'], true) ? $disc : null;
    }
    if ($code === 'C') {
        $disc = strtoupper(trim((string) ($row['disc_c'] ?? '')));
        return in_array($disc, ['D', 'I', 'S', 'C'], true) ? $disc : null;
    }
    if ($code === 'D') {
        $disc = strtoupper(trim((string) ($row['disc_d'] ?? '')));
        return in_array($disc, ['D', 'I', 'S', 'C'], true) ? $disc : null;
    }
    return null;
}

function collect_disc_scoring_repair_plan(PDO $pdo, array $config, ?int $candidateId = null): array
{
    $sql = "SELECT * FROM candidates WHERE status IN ('submitted','timeout_submitted')";
    $params = [];
    if ($candidateId !== null && $candidateId > 0) {
        $sql .= ' AND id = :id';
        $params[':id'] = $candidateId;
    }
    $sql .= ' ORDER BY id ASC';

    $stmtCandidates = $pdo->prepare($sql);
    $stmtCandidates->execute($params);
    $candidateRows = $stmtCandidates->fetchAll();

    $stmtAnswers = $pdo->prepare(
        'SELECT a.id, a.question_id, a.answer_type, a.option_code, a.disc_value, q.disc_a, q.disc_b, q.disc_c, q.disc_d
         FROM answers a
         LEFT JOIN questions_bank q ON q.id = a.question_id
         WHERE a.candidate_id = ?
         ORDER BY a.question_id ASC, a.answer_type ASC, a.id ASC'
    );

    $totalQuestions = count(list_questions($pdo, false, null));
    $minimumRequired = (int) ceil($totalQuestions * (float) ($config['min_completion_ratio'] ?? 0.8));

    $plan = [];
    foreach ($candidateRows as $cand) {
        $id = (int) ($cand['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $stmtAnswers->execute([$id]);
        $rows = $stmtAnswers->fetchAll();
        if (empty($rows)) {
            continue;
        }

        $answersByQuestion = [];
        $answerDiscFixes = [];
        foreach ($rows as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            $answerType = strtolower(trim((string) ($row['answer_type'] ?? '')));
            if ($questionId <= 0 || ($answerType !== 'most' && $answerType !== 'least')) {
                continue;
            }

            $mappedDisc = map_disc_by_option_code((string) ($row['option_code'] ?? ''), $row);
            $oldDisc = strtoupper(trim((string) ($row['disc_value'] ?? '')));
            if ($mappedDisc === null) {
                if (in_array($oldDisc, ['D', 'I', 'S', 'C'], true)) {
                    $mappedDisc = $oldDisc;
                } else {
                    continue;
                }
            }

            if (!isset($answersByQuestion[$questionId])) {
                $answersByQuestion[$questionId] = [];
            }
            $answersByQuestion[$questionId][$answerType . 'Disc'] = $mappedDisc;

            if ($oldDisc !== $mappedDisc) {
                $answerDiscFixes[] = [
                    'answer_id' => (int) ($row['id'] ?? 0),
                    'question_id' => $questionId,
                    'answer_type' => $answerType,
                    'option_code' => (string) ($row['option_code'] ?? ''),
                    'old_disc_value' => $oldDisc,
                    'new_disc_value' => $mappedDisc,
                ];
            }
        }

        foreach ($answersByQuestion as $questionId => $pair) {
            if (!isset($pair['mostDisc']) || !isset($pair['leastDisc'])) {
                unset($answersByQuestion[$questionId]);
            }
        }
        if (empty($answersByQuestion)) {
            continue;
        }

        $baseEvaluation = evaluate_candidate($answersByQuestion, (string) ($cand['selected_role'] ?? ''));
        $answeredCount = count($answersByQuestion);
        $evaluation = ($totalQuestions > 0 && $answeredCount < $minimumRequired)
            ? build_incomplete_evaluation($baseEvaluation, $answeredCount, $totalQuestions)
            : $baseEvaluation;

        $newDisc = [
            'D' => (int) (($evaluation['discCounts']['D'] ?? 0)),
            'I' => (int) (($evaluation['discCounts']['I'] ?? 0)),
            'S' => (int) (($evaluation['discCounts']['S'] ?? 0)),
            'C' => (int) (($evaluation['discCounts']['C'] ?? 0)),
        ];
        $oldDisc = [
            'D' => (int) ($cand['disc_d'] ?? 0),
            'I' => (int) ($cand['disc_i'] ?? 0),
            'S' => (int) ($cand['disc_s'] ?? 0),
            'C' => (int) ($cand['disc_c'] ?? 0),
        ];

        $candidateNeedsUpdate =
            $oldDisc['D'] !== $newDisc['D']
            || $oldDisc['I'] !== $newDisc['I']
            || $oldDisc['S'] !== $newDisc['S']
            || $oldDisc['C'] !== $newDisc['C']
            || (string) ($cand['recommendation'] ?? '') !== (string) ($evaluation['recommendation'] ?? '')
            || (string) ($cand['reason'] ?? '') !== (string) ($evaluation['reason'] ?? '');

        if (!$candidateNeedsUpdate && empty($answerDiscFixes)) {
            continue;
        }

        $plan[] = [
            'candidate' => $cand,
            'answer_disc_fixes' => $answerDiscFixes,
            'evaluation' => $evaluation,
        ];
    }

    return [
        'scanned_candidates' => count($candidateRows),
        'total_questions' => $totalQuestions,
        'minimum_required' => $minimumRequired,
        'plan' => $plan,
    ];
}

function preview_disc_scoring_repair(PDO $pdo, array $config, ?int $candidateId = null): array
{
    $res = collect_disc_scoring_repair_plan($pdo, $config, $candidateId);
    $plan = $res['plan'];

    $answerFixRows = 0;
    foreach ($plan as $item) {
        $answerFixRows += count($item['answer_disc_fixes'] ?? []);
    }

    return [
        'scanned_candidates' => (int) ($res['scanned_candidates'] ?? 0),
        'planned_candidates' => count($plan),
        'candidate_updates' => count($plan),
        'answer_disc_fixes' => $answerFixRows,
    ];
}

function apply_disc_scoring_repair(PDO $pdo, array $config, ?int $candidateId = null): array
{
    $res = collect_disc_scoring_repair_plan($pdo, $config, $candidateId);
    $plan = $res['plan'];
    $runId = gmdate('Ymd_His') . '_disc_repair_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $now = now_iso();

    $stats = [
        'run_id' => $runId,
        'scanned_candidates' => (int) ($res['scanned_candidates'] ?? 0),
        'planned_candidates' => count($plan),
        'updated_candidates' => 0,
        'candidate_updates' => 0,
        'answer_disc_fixes' => 0,
        'backup_candidate_rows' => 0,
        'backup_answer_rows' => 0,
    ];

    if (empty($plan)) {
        return $stats;
    }

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

        $insCandBak = $pdo->prepare(
            'INSERT INTO disc_repair_candidates_backup
                (run_id, backed_up_at, candidate_id, recommendation, reason, role_scores_json, disc_d, disc_i, disc_s, disc_c, score_server, score_beverage, score_cook)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insAnsBak = $pdo->prepare(
            'INSERT INTO disc_repair_answers_backup
                (run_id, backed_up_at, answer_id, candidate_id, question_id, answer_type, option_code, old_disc_value, new_disc_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updAnswer = $pdo->prepare('UPDATE answers SET disc_value = ? WHERE id = ?');
        $updCand = $pdo->prepare(
            'UPDATE candidates
             SET recommendation = ?, reason = ?, role_scores_json = ?, disc_d = ?, disc_i = ?, disc_s = ?, disc_c = ?, score_server = ?, score_beverage = ?, score_cook = ?
             WHERE id = ?'
        );

        foreach ($plan as $item) {
            $cand = $item['candidate'];
            $ev = $item['evaluation'];
            $candidateId2 = (int) ($cand['id'] ?? 0);

            $insCandBak->execute([
                $runId,
                $now,
                $candidateId2,
                (string) ($cand['recommendation'] ?? ''),
                (string) ($cand['reason'] ?? ''),
                (string) ($cand['role_scores_json'] ?? ''),
                (int) ($cand['disc_d'] ?? 0),
                (int) ($cand['disc_i'] ?? 0),
                (int) ($cand['disc_s'] ?? 0),
                (int) ($cand['disc_c'] ?? 0),
                (int) ($cand['score_server'] ?? 0),
                (int) ($cand['score_beverage'] ?? 0),
                (int) ($cand['score_cook'] ?? 0),
            ]);
            $stats['backup_candidate_rows']++;

            foreach (($item['answer_disc_fixes'] ?? []) as $fix) {
                $insAnsBak->execute([
                    $runId,
                    $now,
                    (int) ($fix['answer_id'] ?? 0),
                    $candidateId2,
                    (int) ($fix['question_id'] ?? 0),
                    (string) ($fix['answer_type'] ?? ''),
                    (string) ($fix['option_code'] ?? ''),
                    (string) ($fix['old_disc_value'] ?? ''),
                    (string) ($fix['new_disc_value'] ?? ''),
                ]);
                $updAnswer->execute([
                    (string) ($fix['new_disc_value'] ?? ''),
                    (int) ($fix['answer_id'] ?? 0),
                ]);
                $stats['answer_disc_fixes']++;
                $stats['backup_answer_rows']++;
            }

            $roleScores = is_array($ev['roleScores'] ?? null) ? $ev['roleScores'] : [];
            $updCand->execute([
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
                $candidateId2,
            ]);
            $stats['candidate_updates']++;
            $stats['updated_candidates']++;
        }

        $pdo->commit();
        return $stats;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function normalize_typing_rows(array $rows, ?string $expectedGroup = null): array
{
    $map = [];
    foreach ($rows as $row) {
        $order = (int) ($row['question_order'] ?? 0);
        $group = trim((string) ($row['role_group'] ?? ''));

        if ($order <= 0) {
            continue;
        }

        $key = $order;
        if (!isset($map[$key])) {
            $map[$key] = $row;
            continue;
        }

        $existingActive = (int) ($map[$key]['active_ms'] ?? 0);
        $incomingActive = (int) ($row['active_ms'] ?? 0);
        if ($incomingActive > $existingActive) {
            $map[$key] = $row;
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
        if (empty($candidate['disc_completed_at'])) {
            $discDeadlineAt = (string) ($candidate['deadline_at'] ?? now_iso());
            $discTs = strtotime($discDeadlineAt);
            if ($discTs === false) {
                $discTs = time();
                $discDeadlineAt = gmdate('c', $discTs);
            }

            $essayDurationMinutes = max(1, (int) ($config['essay_duration_minutes'] ?? 15));
            $essayDeadlineAt = gmdate('c', $discTs + ($essayDurationMinutes * 60));
            $candidateId = (int) ($candidate['id'] ?? 0);
            if ($candidateId > 0) {
                mark_disc_completed($pdo, $candidateId, $discDeadlineAt, $essayDeadlineAt);
                $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
                $essayQuestions = list_essay_questions($pdo, false, $group);
                $essaySnapshotRows = ensure_candidate_essay_question_snapshot($pdo, $candidateId, $essayQuestions);
                log_candidate_journey_event($pdo, $candidateId, 'disc', 'auto_timeout_submit', 'disc', [
                    'draft_count' => count(parse_draft_answers_from_candidate($candidate)),
                    'question_count' => count(list_questions($pdo, false, null)),
                    'essay_deadline_at' => $essayDeadlineAt,
                    'source' => 'timeout_sweep',
                ]);
                log_candidate_journey_event($pdo, $candidateId, 'essay', 'essay_snapshot_created', $group, [
                    'group' => $group,
                    'question_count' => count($essaySnapshotRows),
                    'source' => 'timeout_sweep',
                ]);
                log_integrity_event($pdo, $candidateId, 'disc', 'phase_complete_timeout', 'disc_to_essay');
            }
            continue;
        }

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
            $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
            $essayQuestions = ensure_candidate_essay_question_snapshot(
                $pdo,
                (int) $candidate['id'],
                list_essay_questions($pdo, false, $group)
            );
            $draftEssay = remap_draft_essay_answers_to_snapshot(
                parse_draft_essay_answers_from_candidate($candidate),
                $essayQuestions
            );
            if (!empty($draftEssay)) {
                save_essay_answers($pdo, (int) $candidate['id'], $draftEssay);
            }
            log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'auto_timeout_submit', $group, [
                'source' => 'timeout_sweep',
                'draft_essay_count' => count($draftEssay),
                'snapshot_question_count' => count($essayQuestions),
            ]);
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

function normalize_page_param($raw): int
{
    $page = (int) $raw;
    return $page > 0 ? $page : 1;
}

function normalize_per_page_param($raw): int
{
    $allowed = [10, 20, 50, 100];
    $perPage = (int) $raw;
    return in_array($perPage, $allowed, true) ? $perPage : 20;
}

function normalize_essay_sort_by($raw): string
{
    $allowed = ['default', 'group', 'order', 'status', 'updated', 'id'];
    $sortBy = trim((string) $raw);
    return in_array($sortBy, $allowed, true) ? $sortBy : 'default';
}

function normalize_sort_dir($raw): string
{
    $dir = strtolower(trim((string) $raw));
    return $dir === 'desc' ? 'desc' : 'asc';
}

function build_essay_next_order_map(PDO $pdo, array $groups): array
{
    $map = [];
    foreach ($groups as $group) {
        $map[(string) $group] = get_next_essay_question_order($pdo, (string) $group);
    }
    return $map;
}

function parse_bulk_ids_csv(string $raw): array
{
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
    $ids = array_map('intval', $parts);
    return array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
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
        'role_options' => load_role_options_for_candidate($pdo, $config),
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
            'role_options' => load_role_options_for_candidate($pdo, $config),
            'error_message' => 'Tes belum tersedia. Saat ini belum ada soal aktif dari tim HR.',
            'values' => $values,
        ]);
        exit;
    }

    $candidateRoles = load_role_options_for_candidate($pdo, $config);
    $valid = $fullName !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && strlen($whatsapp) >= 8
        && in_array($selectedRole, $candidateRoles, true);

    if (!$valid) {
        render('candidate/identity', [
            'page_title' => 'Asesmen Kandidat',
            'role_options' => $candidateRoles,
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
            'role_options' => $candidateRoles,
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
    log_candidate_journey_event($pdo, $candidateId, 'candidate', 'candidate_created', 'identity_submitted', [
        'full_name' => $fullName,
        'email' => $email,
        'whatsapp' => $whatsapp,
        'selected_role' => $selectedRole,
        'started_at' => $startedAt,
        'disc_deadline_at' => $deadlineAt,
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
        log_candidate_journey_event($pdo, (int) $candidate['id'], 'disc', 'auto_timeout_submit', 'disc', [
            'draft_count' => count($draftAnswers),
            'question_count' => count($questions),
            'essay_deadline_at' => $essayDeadlineAt,
        ]);
        log_integrity_event($pdo, (int) $candidate['id'], 'disc', 'phase_complete_timeout', 'disc_to_essay');
        redirect(route_path('/essay-test'));
    }

    log_candidate_journey_event($pdo, (int) $candidate['id'], 'disc', 'disc_page_open', 'disc', [
        'deadline_at' => (string) ($candidate['deadline_at'] ?? ''),
        'draft_count' => count($draftAnswers),
        'question_count' => count($questions),
    ]);

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
    $savedAt = now_iso();
    update_candidate_draft_answers($pdo, (int) $candidate['id'], $answers, $savedAt);
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'disc', 'disc_autosave', 'disc', [
        'saved_count' => count($answers),
        'last_autosave_at' => $savedAt,
    ]);

    json_response([
        'ok' => true,
        'saved_count' => count($answers),
        'last_autosave_at' => $savedAt,
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
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'disc', 'disc_submit_attempt', 'disc', [
        'answered_count' => $answeredCount,
        'total_questions' => $totalQuestions,
        'expired' => $expired,
    ]);

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
    $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = list_essay_questions($pdo, false, $group);
    $essaySnapshotRows = ensure_candidate_essay_question_snapshot($pdo, (int) $candidate['id'], $essayQuestions);
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'disc', 'disc_submitted', 'disc', [
        'answered_count' => $answeredCount,
        'total_questions' => $totalQuestions,
        'essay_deadline_at' => $essayDeadlineAt,
    ]);
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'essay_snapshot_created', $group, [
        'group' => $group,
        'question_count' => count($essaySnapshotRows),
    ]);
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

    $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = ensure_candidate_essay_question_snapshot(
        $pdo,
        (int) $candidate['id'],
        list_essay_questions($pdo, false, $group)
    );
    $draftEssayAnswers = remap_draft_essay_answers_to_snapshot(
        parse_draft_essay_answers_from_candidate($candidate),
        $essayQuestions
    );
    $essayDeadlineAt = (string) ($candidate['essay_deadline_at'] ?? '');
    if ($essayDeadlineAt === '') {
        $essayDeadlineAt = gmdate('c', time() + ((int) ($config['essay_duration_minutes'] ?? 15) * 60));
        mark_disc_completed($pdo, (int) $candidate['id'], (string) ($candidate['disc_completed_at'] ?? now_iso()), $essayDeadlineAt);
    }

    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'essay_page_open', $group, [
        'group' => $group,
        'deadline_at' => $essayDeadlineAt,
        'question_count' => count($essayQuestions),
    ]);

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
        log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'auto_timeout_submit', 'essay', [
            'draft_essay_count' => count($draftEssayAnswers),
            'disc_answered_count' => $answeredCount,
            'disc_total_questions' => $totalQuestions,
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

    $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = ensure_candidate_essay_question_snapshot(
        $pdo,
        (int) $candidate['id'],
        list_essay_questions($pdo, false, $group)
    );
    $essayAnswers = build_essay_answers_from_payload($_POST, $essayQuestions);
    $essayAnswers = remap_draft_essay_answers_to_snapshot($essayAnswers, $essayQuestions);
    $savedAt = now_iso();
    $filledCount = count_filled_essay_answers($essayAnswers);
    $totalCount = count($essayQuestions);
    update_candidate_draft_essay_answers($pdo, (int) $candidate['id'], $essayAnswers, $savedAt);
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'essay_autosave', $group, [
        'group' => $group,
        'saved_count' => $filledCount,
        'filled_count' => $filledCount,
        'total_count' => $totalCount,
        'last_autosave_at' => $savedAt,
    ]);
    json_response([
        'ok' => true,
        'saved_count' => $filledCount,
        'filled_count' => $filledCount,
        'total_count' => $totalCount,
        'last_autosave_at' => $savedAt,
    ]);
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
    $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = ensure_candidate_essay_question_snapshot(
        $pdo,
        (int) $candidate['id'],
        list_essay_questions($pdo, false, $group)
    );
    $sourceBySnapshotId = [];
    foreach ($essayQuestions as $q) {
        $sid = (int) ($q['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $sourceBySnapshotId[$sid] = (int) ($q['source_essay_question_id'] ?? 0);
    }

    foreach ($decoded as $questionId => $metric) {
        $qid = (int) $questionId;
        if ($qid <= 0 || !is_array($metric)) {
            continue;
        }
        $sourceId = (int) ($sourceBySnapshotId[$qid] ?? 0);
        upsert_essay_typing_metric($pdo, (int) $candidate['id'], $sourceId > 0 ? $sourceId : $qid, $metric);
        $saved++;
    }

    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'typing_metrics_saved', 'essay', [
        'saved_count' => $saved,
    ]);

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

    $group = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $essayQuestions = ensure_candidate_essay_question_snapshot(
        $pdo,
        (int) $candidate['id'],
        list_essay_questions($pdo, false, $group)
    );
    $postedEssay = remap_draft_essay_answers_to_snapshot(
        build_essay_answers_from_payload($_POST, $essayQuestions),
        $essayQuestions
    );
    $draftEssay = remap_draft_essay_answers_to_snapshot(
        parse_draft_essay_answers_from_candidate($candidate),
        $essayQuestions
    );
    $essayAnswers = $postedEssay;

    $deadlineAt = (string) ($candidate['essay_deadline_at'] ?? '');
    $expired = $deadlineAt !== '' && strtotime($deadlineAt) <= time();
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'essay_submit_attempt', $group, [
        'group' => $group,
        'posted_count' => count($postedEssay),
        'draft_count' => count($draftEssay),
        'posted_filled' => count_filled_essay_answers($postedEssay),
        'draft_filled' => count_filled_essay_answers($draftEssay),
        'question_total' => count($essayQuestions),
        'expired' => $expired,
    ]);
    if (!$expired && count_filled_essay_answers($essayAnswers) < count($essayQuestions)) {
        // Keep latest posted draft for recovery, but manual submit must be complete from current form.
        update_candidate_draft_essay_answers($pdo, (int) $candidate['id'], merge_essay_answers_with_fallback($postedEssay, $draftEssay));
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

    if ($expired) {
        // Timeout fallback can still use latest autosave + posted payload.
        $essayAnswers = merge_essay_answers_with_fallback($postedEssay, $draftEssay);
    }

    if (count_filled_essay_answers($essayAnswers) > 0) {
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
    log_candidate_journey_event($pdo, (int) $candidate['id'], 'essay', 'essay_submitted', $expired ? 'timeout_submitted' : 'submitted', [
        'saved_answer_count' => count_filled_essay_answers($essayAnswers),
        'status' => $expired ? 'timeout_submitted' : 'submitted',
        'submitted_at' => $effectiveEnd,
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
    $flashMessage = $_SESSION['dashboard_flash_message'] ?? null;
    $flashType = $_SESSION['dashboard_flash_type'] ?? 'info';
    unset($_SESSION['dashboard_flash_message'], $_SESSION['dashboard_flash_type']);

    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'role' => trim((string) ($_GET['role'] ?? '')),
        'recommendation' => trim((string) ($_GET['recommendation'] ?? '')),
    ];
    $page = normalize_page_param($_GET['page'] ?? 1);
    $perPage = normalize_per_page_param($_GET['per_page'] ?? 20);
    $total = count_candidates($pdo, $filters);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $candidates = list_candidates_paginated($pdo, $filters, $page, $perPage);
    foreach ($candidates as &$candidateRow) {
        $scores = extract_role_scores_from_candidate($candidateRow);
        $candidateRow['interview_recommendation'] = interview_recommendation_label($candidateRow, $scores);
    }
    unset($candidateRow);
    $stats = get_summary_stats($pdo, $filters);

    render('hr/dashboard', [
        'page_title' => 'Dashboard HR - DISC',
        'filters' => $filters,
        'role_options' => load_role_options_for_hr($pdo, $config),
        'recommendation_options' => recommendation_options(),
        'candidates' => $candidates,
        'stats' => $stats,
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'info',
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
            'to' => $total > 0 ? min($total, $page * $perPage) : 0,
        ],
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/tools/normalize-legacy-essay') {
    require_hr_auth($config);
    try {
        $result = normalize_legacy_essay_data($pdo);
        $_SESSION['dashboard_flash_message'] =
            'Perbaikan data lama selesai. ' .
            'scan=' . (int) ($result['scanned_candidates'] ?? 0) .
            ', snapshot_fix=' . (int) ($result['snapshot_fixed_candidates'] ?? 0) .
            ', answers_fix=' . (int) ($result['answers_fixed_candidates'] ?? 0) .
            ', backup_snapshot=' . (int) ($result['snapshot_backup_rows'] ?? 0) .
            ', backup_answers=' . (int) ($result['answers_backup_rows'] ?? 0) .
            ', inserted_answers=' . (int) ($result['answers_inserted_rows'] ?? 0) .
            ', run_id=' . (string) ($result['run_id'] ?? '-');
        $_SESSION['dashboard_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['dashboard_flash_message'] = 'Perbaikan data lama gagal: ' . $e->getMessage();
        $_SESSION['dashboard_flash_type'] = 'error';
    }
    redirect(route_path('/hr/dashboard'));
}

if ($method === 'POST' && $path === '/hr/tools/normalize-legacy-essay-preview') {
    require_hr_auth($config);
    try {
        $result = preview_legacy_essay_data($pdo);
        $_SESSION['dashboard_flash_message'] =
            'Preview perbaikan data lama. ' .
            'scan=' . (int) ($result['scanned_candidates'] ?? 0) .
            ', snapshot_fix=' . (int) ($result['snapshot_fixed_candidates'] ?? 0) .
            ', answers_fix=' . (int) ($result['answers_fixed_candidates'] ?? 0) .
            ', backup_snapshot=' . (int) ($result['snapshot_backup_rows'] ?? 0) .
            ', backup_answers=' . (int) ($result['answers_backup_rows'] ?? 0) .
            ', inserted_answers=' . (int) ($result['answers_inserted_rows'] ?? 0) .
            '. Belum ada perubahan data.';
        $_SESSION['dashboard_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['dashboard_flash_message'] = 'Preview perbaikan gagal: ' . $e->getMessage();
        $_SESSION['dashboard_flash_type'] = 'error';
    }
    redirect(route_path('/hr/dashboard'));
}

if ($method === 'POST' && $path === '/hr/tools/repair-disc-scoring-preview') {
    require_hr_auth($config);
    try {
        $result = preview_disc_scoring_repair($pdo, $config);
        $_SESSION['dashboard_flash_message'] =
            'Preview repair DISC. ' .
            'scan=' . (int) ($result['scanned_candidates'] ?? 0) .
            ', planned=' . (int) ($result['planned_candidates'] ?? 0) .
            ', candidate_updates=' . (int) ($result['candidate_updates'] ?? 0) .
            ', answer_disc_fixes=' . (int) ($result['answer_disc_fixes'] ?? 0) .
            '. Belum ada perubahan data.';
        $_SESSION['dashboard_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['dashboard_flash_message'] = 'Preview repair DISC gagal: ' . $e->getMessage();
        $_SESSION['dashboard_flash_type'] = 'error';
    }
    redirect(route_path('/hr/dashboard'));
}

if ($method === 'POST' && $path === '/hr/tools/repair-disc-scoring') {
    require_hr_auth($config);
    try {
        $result = apply_disc_scoring_repair($pdo, $config);
        $_SESSION['dashboard_flash_message'] =
            'Repair DISC selesai. ' .
            'scan=' . (int) ($result['scanned_candidates'] ?? 0) .
            ', planned=' . (int) ($result['planned_candidates'] ?? 0) .
            ', updated=' . (int) ($result['updated_candidates'] ?? 0) .
            ', candidate_updates=' . (int) ($result['candidate_updates'] ?? 0) .
            ', answer_disc_fixes=' . (int) ($result['answer_disc_fixes'] ?? 0) .
            ', backup_candidate=' . (int) ($result['backup_candidate_rows'] ?? 0) .
            ', backup_answer=' . (int) ($result['backup_answer_rows'] ?? 0) .
            ', run_id=' . (string) ($result['run_id'] ?? '-');
        $_SESSION['dashboard_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['dashboard_flash_message'] = 'Repair DISC gagal: ' . $e->getMessage();
        $_SESSION['dashboard_flash_type'] = 'error';
    }
    redirect(route_path('/hr/dashboard'));
}

if ($method === 'GET' && $path === '/hr/master-data') {
    require_hr_auth($config);

    $flashMessage = $_SESSION['master_data_flash_message'] ?? null;
    $flashType = $_SESSION['master_data_flash_type'] ?? 'info';
    unset($_SESSION['master_data_flash_message'], $_SESSION['master_data_flash_type']);

    render('hr/master-data', [
        'page_title' => 'Kelola Role & Kelompok Esai',
        'roles' => list_role_catalog($pdo, true),
        'essay_groups' => list_essay_group_catalog($pdo, true),
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'info',
    ]);
    exit;
}

if ($method === 'POST' && $path === '/hr/master-data/roles/new') {
    require_hr_auth($config);
    $roleName = trim((string) ($_POST['role_name'] ?? ''));
    $essayGroup = trim((string) ($_POST['essay_group'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 999);
    $isActive = !empty($_POST['is_active']);
    $groupOptions = array_map(static fn(array $r): string => (string) ($r['group_name'] ?? ''), list_essay_group_catalog($pdo, true));

    if ($roleName === '' || $essayGroup === '' || !in_array($essayGroup, $groupOptions, true)) {
        $_SESSION['master_data_flash_message'] = 'Role gagal ditambahkan: nama role atau kelompok esai tidak valid.';
        $_SESSION['master_data_flash_type'] = 'error';
        redirect(route_path('/hr/master-data'));
    }

    try {
        create_role_catalog($pdo, [
            'role_name' => $roleName,
            'essay_group' => $essayGroup,
            'sort_order' => max(1, $sortOrder),
            'is_active' => $isActive,
        ]);
        $_SESSION['master_data_flash_message'] = 'Role berhasil ditambahkan.';
        $_SESSION['master_data_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['master_data_flash_message'] = 'Role gagal ditambahkan. Pastikan nama role unik.';
        $_SESSION['master_data_flash_type'] = 'error';
    }
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/roles/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $id = (int) $m[1];
    $roleName = trim((string) ($_POST['role_name'] ?? ''));
    $essayGroup = trim((string) ($_POST['essay_group'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 999);
    $isActive = !empty($_POST['is_active']);
    $groupOptions = array_map(static fn(array $r): string => (string) ($r['group_name'] ?? ''), list_essay_group_catalog($pdo, true));

    if ($roleName === '' || $essayGroup === '' || !in_array($essayGroup, $groupOptions, true)) {
        $_SESSION['master_data_flash_message'] = 'Role gagal diupdate: input tidak valid.';
        $_SESSION['master_data_flash_type'] = 'error';
        redirect(route_path('/hr/master-data'));
    }

    try {
        update_role_catalog($pdo, $id, [
            'role_name' => $roleName,
            'essay_group' => $essayGroup,
            'sort_order' => max(1, $sortOrder),
            'is_active' => $isActive,
        ]);
        $_SESSION['master_data_flash_message'] = 'Role berhasil diupdate.';
        $_SESSION['master_data_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['master_data_flash_message'] = 'Role gagal diupdate. Pastikan nama role unik.';
        $_SESSION['master_data_flash_type'] = 'error';
    }
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/roles/(\d+)/toggle-active$#', $path, $m)) {
    require_hr_auth($config);
    $ok = toggle_role_catalog_active($pdo, (int) $m[1]);
    $_SESSION['master_data_flash_message'] = $ok ? 'Status role berhasil diubah.' : 'Role tidak ditemukan.';
    $_SESSION['master_data_flash_type'] = $ok ? 'success' : 'error';
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/roles/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    $ok = delete_role_catalog($pdo, (int) $m[1]);
    $_SESSION['master_data_flash_message'] = $ok
        ? 'Role berhasil dihapus.'
        : 'Role tidak bisa dihapus (masih dipakai kandidat) atau tidak ditemukan.';
    $_SESSION['master_data_flash_type'] = $ok ? 'success' : 'error';
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && $path === '/hr/master-data/essay-groups/new') {
    require_hr_auth($config);
    $groupName = trim((string) ($_POST['group_name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 999);
    $isActive = !empty($_POST['is_active']);

    if ($groupName === '') {
        $_SESSION['master_data_flash_message'] = 'Kelompok esai gagal ditambahkan: nama wajib diisi.';
        $_SESSION['master_data_flash_type'] = 'error';
        redirect(route_path('/hr/master-data'));
    }

    try {
        create_essay_group_catalog($pdo, [
            'group_name' => $groupName,
            'sort_order' => max(1, $sortOrder),
            'is_active' => $isActive,
        ]);
        $_SESSION['master_data_flash_message'] = 'Kelompok esai berhasil ditambahkan.';
        $_SESSION['master_data_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['master_data_flash_message'] = 'Kelompok esai gagal ditambahkan. Pastikan nama unik.';
        $_SESSION['master_data_flash_type'] = 'error';
    }
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/essay-groups/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $id = (int) $m[1];
    $groupName = trim((string) ($_POST['group_name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 999);
    $isActive = !empty($_POST['is_active']);

    if ($groupName === '') {
        $_SESSION['master_data_flash_message'] = 'Kelompok esai gagal diupdate: nama wajib diisi.';
        $_SESSION['master_data_flash_type'] = 'error';
        redirect(route_path('/hr/master-data'));
    }

    try {
        update_essay_group_catalog($pdo, $id, [
            'group_name' => $groupName,
            'sort_order' => max(1, $sortOrder),
            'is_active' => $isActive,
        ]);
        $_SESSION['master_data_flash_message'] = 'Kelompok esai berhasil diupdate.';
        $_SESSION['master_data_flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['master_data_flash_message'] = 'Kelompok esai gagal diupdate. Pastikan nama unik.';
        $_SESSION['master_data_flash_type'] = 'error';
    }
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/essay-groups/(\d+)/toggle-active$#', $path, $m)) {
    require_hr_auth($config);
    $ok = toggle_essay_group_catalog_active($pdo, (int) $m[1]);
    $_SESSION['master_data_flash_message'] = $ok ? 'Status kelompok esai berhasil diubah.' : 'Kelompok esai tidak ditemukan.';
    $_SESSION['master_data_flash_type'] = $ok ? 'success' : 'error';
    redirect(route_path('/hr/master-data'));
}

if ($method === 'POST' && preg_match('#^/hr/master-data/essay-groups/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    $ok = delete_essay_group_catalog($pdo, (int) $m[1]);
    $_SESSION['master_data_flash_message'] = $ok
        ? 'Kelompok esai berhasil dihapus.'
        : 'Kelompok esai tidak bisa dihapus (masih dipakai role/bank soal) atau tidak ditemukan.';
    $_SESSION['master_data_flash_type'] = $ok ? 'success' : 'error';
    redirect(route_path('/hr/master-data'));
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
    $page = normalize_page_param($_GET['page'] ?? 1);
    $perPage = normalize_per_page_param($_GET['per_page'] ?? 20);
    $total = count_candidates($pdo, $filters);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $candidates = list_candidates_paginated($pdo, $filters, $page, $perPage);
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
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
            'to' => $total > 0 ? min($total, $page * $perPage) : 0,
        ],
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
    $discTotalCount = count(list_questions($pdo, false, null));
    $discAnsweredCount = 0;
    foreach ($answers as $row) {
        $mostCode = trim((string) ($row['most_code'] ?? ''));
        $leastCode = trim((string) ($row['least_code'] ?? ''));
        if ($mostCode !== '' && $leastCode !== '' && $mostCode !== $leastCode) {
            $discAnsweredCount++;
        }
    }
    $expectedEssayGroup = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $includeUnansweredEssay = true;
    $expectedEssayQuestions = build_expected_essay_questions_for_display($pdo, (int) $candidate['id'], $expectedEssayGroup);
    $essayRows = normalize_essay_profile_rows(
        get_essay_answer_details_for_candidate($pdo, (int) $candidate['id']),
        $expectedEssayGroup,
        $expectedEssayQuestions,
        $includeUnansweredEssay
    );
    $essayAnsweredCount = 0;
    $essayTotalCount = count($essayRows);
    foreach ($essayRows as $row) {
        if (trim((string) ($row['answer_text'] ?? '')) !== '') {
            $essayAnsweredCount++;
        }
    }
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
    $journeyEventsRaw = list_candidate_journey_events($pdo, (int) $candidate['id'], 600);
    $journeyEvents = array_map(static function (array $ev): array {
        $payloadText = '-';
        if (is_string($ev['payload_json'] ?? null) && trim((string) $ev['payload_json']) !== '') {
            $decoded = json_decode((string) $ev['payload_json'], true);
            if (is_array($decoded)) {
                $payloadText = format_journey_payload_text((string) ($ev['event_key'] ?? ''), $decoded);
            }
        }
        $ev['phase_label'] = integrity_phase_label((string) ($ev['phase'] ?? ''));
        $ev['event_label'] = journey_event_label((string) ($ev['event_key'] ?? ''));
        $ev['value_label'] = integrity_event_value_label((string) ($ev['event_value'] ?? ''));
        $ev['payload_text'] = $payloadText;
        return $ev;
    }, $journeyEventsRaw);
    $typingMetricsRows = normalize_typing_rows(
        get_essay_typing_metrics_for_candidate($pdo, (int) $candidate['id']),
        $expectedEssayGroup
    );
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
    $focusLossSeverity = focus_loss_severity_from_events($integrityEvents, $candidate);
    $latencyPasteAnomaly = latency_paste_anomaly_from_rows($typingMetricsRows);

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
        'disc_answered_count' => $discAnsweredCount,
        'disc_total_count' => $discTotalCount,
        'essay_rows' => $essayRows,
        'essay_answered_count' => $essayAnsweredCount,
        'essay_total_count' => $essayTotalCount,
        'integrity_events' => $integrityEventsDisplay,
        'journey_events' => $journeyEvents,
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
        'focus_loss_severity' => $focusLossSeverity,
        'latency_paste_anomaly' => $latencyPasteAnomaly,
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
    $discTotalCount = count(list_questions($pdo, false, null));
    $discAnsweredCount = 0;
    foreach ($rows as $row) {
        $mostCode = trim((string) ($row['most_code'] ?? ''));
        $leastCode = trim((string) ($row['least_code'] ?? ''));
        if ($mostCode !== '' && $leastCode !== '' && $mostCode !== $leastCode) {
            $discAnsweredCount++;
        }
    }
    $expectedEssayGroup = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $includeUnansweredEssay = true;
    $expectedEssayQuestions = build_expected_essay_questions_for_display($pdo, (int) $candidate['id'], $expectedEssayGroup);
    $essayRows = normalize_essay_profile_rows(
        get_essay_answer_details_for_candidate($pdo, $candidateId),
        $expectedEssayGroup,
        $expectedEssayQuestions,
        $includeUnansweredEssay
    );
    $essayAnsweredCount = 0;
    $essayTotalCount = count($essayRows);
    foreach ($essayRows as $row) {
        if (trim((string) ($row['answer_text'] ?? '')) !== '') {
            $essayAnsweredCount++;
        }
    }
    $typingRows = normalize_typing_rows(get_essay_typing_metrics_for_candidate($pdo, $candidateId), $expectedEssayGroup);
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
    $journeyEventsRaw = list_candidate_journey_events($pdo, $candidateId, 800);
    $journeyEvents = array_map(static function (array $ev): array {
        $payloadText = '-';
        if (is_string($ev['payload_json'] ?? null) && trim((string) $ev['payload_json']) !== '') {
            $decoded = json_decode((string) $ev['payload_json'], true);
            if (is_array($decoded)) {
                $payloadText = format_journey_payload_text((string) ($ev['event_key'] ?? ''), $decoded);
            }
        }
        return [
            'created_at' => (string) ($ev['created_at'] ?? ''),
            'phase_label' => integrity_phase_label((string) ($ev['phase'] ?? '')),
            'event_label' => journey_event_label((string) ($ev['event_key'] ?? '')),
            'value_label' => integrity_event_value_label((string) ($ev['event_value'] ?? '')),
            'payload_text' => $payloadText,
        ];
    }, $journeyEventsRaw);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingRows);
    $focusLossSeverity = focus_loss_severity_from_events($integrityEventsRaw, $candidate);
    $latencyPasteAnomaly = latency_paste_anomaly_from_rows($typingRows);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="candidate-answers-' . $candidateId . '-' . time() . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['RINGKASAN KANDIDAT']);
    fputcsv($out, [
        'Candidate ID', 'Nama', 'Email', 'WA', 'Role Dipilih',
        'Status', 'Mulai', 'Selesai', 'Durasi (detik)', 'DISC D', 'DISC I', 'DISC S', 'DISC C',
        'Jawaban DISC Terisi', 'Jawaban Esai Terisi', 'Integrity Risk', 'Typing Risk', 'Focus-loss Severity', 'Latency+Paste Anomaly',
    ]);
    fputcsv($out, [
        (int) ($candidate['id'] ?? 0),
        (string) ($candidate['full_name'] ?? ''),
        (string) ($candidate['email'] ?? ''),
        (string) ($candidate['whatsapp'] ?? ''),
        (string) ($candidate['selected_role'] ?? ''),
        (string) ($candidate['status'] ?? ''),
        format_date_id((string) ($candidate['started_at'] ?? '')),
        format_date_id((string) ($candidate['submitted_at'] ?? '')),
        (int) ($candidate['duration_seconds'] ?? 0),
        (int) ($candidate['disc_d'] ?? 0),
        (int) ($candidate['disc_i'] ?? 0),
        (int) ($candidate['disc_s'] ?? 0),
        (int) ($candidate['disc_c'] ?? 0),
        $discAnsweredCount . '/' . $discTotalCount,
        $essayAnsweredCount . '/' . $essayTotalCount,
        (string) ($integrityRisk['level'] ?? 'Low') . ' (tab=' . (int) ($integrityRisk['tab_switches'] ?? 0) . ', paste=' . (int) ($integrityRisk['paste_count'] ?? 0) . ')',
        (string) ($typingRisk['level'] ?? 'Low') . ' (score=' . (int) ($typingRisk['score'] ?? 0) . ')',
        (string) ($focusLossSeverity['level'] ?? 'Low') . ' (score=' . (int) ($focusLossSeverity['score'] ?? 0) . ', tab=' . (int) ($focusLossSeverity['tab_switches'] ?? 0) . ')',
        (string) ($latencyPasteAnomaly['level'] ?? 'Low') . ' (score=' . (int) ($latencyPasteAnomaly['score'] ?? 0) . ', flagged=' . (int) ($latencyPasteAnomaly['flagged_rows'] ?? 0) . ')',
    ]);

    fputcsv($out, ['']);
    fputcsv($out, ['JAWABAN DISC']);
    fputcsv($out, [
        'Candidate ID', 'Nama', 'Email', 'WA', 'Role Dipilih', 'Status',
        'No Soal', 'Role Soal', 'Most', 'Most Text', 'Least', 'Least Text',
        'Mapping A->DISC', 'Mapping B->DISC', 'Mapping C->DISC', 'Mapping D->DISC',
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
            $row['status'],
            (int) ($row['question_order'] ?? 0),
            $row['question_role'] ?? '-',
            $mostCode !== '' ? $mostCode : '-',
            answer_option_text($row, $mostCode),
            $leastCode !== '' ? $leastCode : '-',
            answer_option_text($row, $leastCode),
            'A->' . strtoupper((string) ($row['disc_a'] ?? '-')),
            'B->' . strtoupper((string) ($row['disc_b'] ?? '-')),
            'C->' . strtoupper((string) ($row['disc_c'] ?? '-')),
            'D->' . strtoupper((string) ($row['disc_d'] ?? '-')),
        ]);
    }

    fputcsv($out, ['']);
    fputcsv($out, ['JAWABAN ESAI']);
    fputcsv($out, ['No', 'Kelompok', 'Pertanyaan', 'Jawaban']);
    foreach ($essayRows as $row) {
        $questionText = trim((string) ($row['question_text'] ?? ''));
        $answerText = trim((string) ($row['answer_text'] ?? ''));
        fputcsv($out, [
            (int) ($row['question_order'] ?? 0),
            (string) ($row['role_group'] ?? '-'),
            $questionText !== '' ? $questionText : '(Soal sudah tidak tersedia di bank soal)',
            $answerText !== '' ? $answerText : '',
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
    fputcsv($out, ['EVENT TIMELINE LENGKAP (SNAPSHOT JOURNEY)']);
    fputcsv($out, ['Waktu', 'Fase', 'Event', 'Nilai', 'Metadata Snapshot']);
    foreach ($journeyEvents as $ev) {
        fputcsv($out, [
            format_date_id((string) ($ev['created_at'] ?? '')),
            (string) ($ev['phase_label'] ?? '-'),
            (string) ($ev['event_label'] ?? '-'),
            (string) ($ev['value_label'] ?? '-'),
            (string) ($ev['payload_text'] ?? '-'),
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

    fclose($out);
    exit;
}

if ($method === 'GET' && preg_match('#^/hr/candidates/(\d+)/export/answers\.(pdf|doc)$#', $path, $m)) {
    require_hr_auth($config);

    $candidateId = (int) $m[1];
    $exportFormat = strtolower((string) ($m[2] ?? 'pdf'));
    $candidate = get_candidate_by_id($pdo, $candidateId);
    if (!$candidate) {
        http_response_code(404);
        echo 'Candidate not found';
        exit;
    }

    $rows = get_answer_details_for_candidate_export($pdo, $candidateId);
    $discTotalCount = count(list_questions($pdo, false, null));
    $discAnsweredCount = 0;
    foreach ($rows as $row) {
        $mostCode = trim((string) ($row['most_code'] ?? ''));
        $leastCode = trim((string) ($row['least_code'] ?? ''));
        if ($mostCode !== '' && $leastCode !== '' && $mostCode !== $leastCode) {
            $discAnsweredCount++;
        }
    }
    $expectedEssayGroup = essay_group_by_selected_role($pdo, (string) ($candidate['selected_role'] ?? ''));
    $includeUnansweredEssay = true;
    $expectedEssayQuestions = build_expected_essay_questions_for_display($pdo, (int) $candidate['id'], $expectedEssayGroup);
    $essayRows = normalize_essay_profile_rows(
        get_essay_answer_details_for_candidate($pdo, $candidateId),
        $expectedEssayGroup,
        $expectedEssayQuestions,
        $includeUnansweredEssay
    );
    $essayAnsweredCount = 0;
    $essayTotalCount = count($essayRows);
    foreach ($essayRows as $row) {
        if (trim((string) ($row['answer_text'] ?? '')) !== '') {
            $essayAnsweredCount++;
        }
    }
    $typingRows = normalize_typing_rows(get_essay_typing_metrics_for_candidate($pdo, $candidateId), $expectedEssayGroup);
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
    $journeyEventsRaw = list_candidate_journey_events($pdo, $candidateId, 800);
    $journeyEvents = array_map(static function (array $ev): array {
        $payloadText = '-';
        if (is_string($ev['payload_json'] ?? null) && trim((string) $ev['payload_json']) !== '') {
            $decoded = json_decode((string) $ev['payload_json'], true);
            if (is_array($decoded)) {
                $payloadText = format_journey_payload_text((string) ($ev['event_key'] ?? ''), $decoded);
            }
        }
        return [
            'created_at' => (string) ($ev['created_at'] ?? ''),
            'phase_label' => integrity_phase_label((string) ($ev['phase'] ?? '')),
            'event_label' => journey_event_label((string) ($ev['event_key'] ?? '')),
            'value_label' => integrity_event_value_label((string) ($ev['event_value'] ?? '')),
            'payload_text' => $payloadText,
        ];
    }, $journeyEventsRaw);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingRows);
    $focusLossSeverity = focus_loss_severity_from_events($integrityEventsRaw, $candidate);
    $latencyPasteAnomaly = latency_paste_anomaly_from_rows($typingRows);

    if ($exportFormat === 'doc') {
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="candidate-answers-' . $candidateId . '-' . time() . '.doc"');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Jawaban Kandidat #' . h((string) $candidateId) . '</title><style>body{font-family:Arial,sans-serif;padding:20px;}h2{margin:0 0 6px;}h3{margin:18px 0 8px;}p{margin:4px 0 12px;}table{border-collapse:collapse;width:100%;font-size:12px;margin-bottom:10px}th,td{border:1px solid #ddd;padding:6px;vertical-align:top;text-align:left;}th{background:#f2f2f2}</style></head><body>';
    echo '<h2>Jawaban Kandidat #' . h((string) $candidateId) . '</h2>';
    echo '<p><strong>Nama:</strong> ' . h((string) ($candidate['full_name'] ?? '-')) . '<br>';
    echo '<strong>Email:</strong> ' . h((string) ($candidate['email'] ?? '-')) . '<br>';
    echo '<strong>Role Dipilih:</strong> ' . h((string) ($candidate['selected_role'] ?? '-')) . '<br>';
    echo '<strong>Jawaban DISC Terisi:</strong> ' . h((string) $discAnsweredCount) . '/' . h((string) $discTotalCount) . '<br>';
    echo '<strong>Jawaban Esai Terisi:</strong> ' . h((string) $essayAnsweredCount) . '/' . h((string) $essayTotalCount) . '<br>';
    echo '<strong>Status:</strong> ' . h((string) ($candidate['status'] ?? '-')) . '<br>';
    echo '<strong>Integrity Risk:</strong> ' . h((string) ($integrityRisk['level'] ?? 'Low')) . ' (tab=' . h((string) ((int) ($integrityRisk['tab_switches'] ?? 0))) . ', paste=' . h((string) ((int) ($integrityRisk['paste_count'] ?? 0))) . ')<br>';
    echo '<strong>Typing Risk:</strong> ' . h((string) ($typingRisk['level'] ?? 'Low')) . ' (score=' . h((string) ((int) ($typingRisk['score'] ?? 0))) . ')</p>';
    echo '<p><strong>Focus-loss Severity:</strong> ' . h((string) ($focusLossSeverity['level'] ?? 'Low')) . ' (score=' . h((string) ((int) ($focusLossSeverity['score'] ?? 0))) . ', tab=' . h((string) ((int) ($focusLossSeverity['tab_switches'] ?? 0))) . ', per10m=' . h((string) ($focusLossSeverity['switch_per_10min'] ?? 0)) . ')<br>';
    echo '<strong>Latency+Paste Anomaly:</strong> ' . h((string) ($latencyPasteAnomaly['level'] ?? 'Low')) . ' (score=' . h((string) ((int) ($latencyPasteAnomaly['score'] ?? 0))) . ', flagged=' . h((string) ((int) ($latencyPasteAnomaly['flagged_rows'] ?? 0))) . ')</p>';

    echo '<h3>Jawaban DISC</h3>';
    echo '<table><thead><tr><th>No</th><th>Role Soal</th><th>Most</th><th>Least</th><th>Mapping DISC</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $mostCode = (string) ($row['most_code'] ?? '');
        $leastCode = (string) ($row['least_code'] ?? '');
        $mappingText = 'A->' . strtoupper((string) ($row['disc_a'] ?? '-'))
            . ' | B->' . strtoupper((string) ($row['disc_b'] ?? '-'))
            . ' | C->' . strtoupper((string) ($row['disc_c'] ?? '-'))
            . ' | D->' . strtoupper((string) ($row['disc_d'] ?? '-'));
        echo '<tr>'
            . '<td>' . h((string) ((int) ($row['question_order'] ?? 0))) . '</td>'
            . '<td>' . h((string) ($row['question_role'] ?? '-')) . '</td>'
            . '<td><strong>' . h($mostCode !== '' ? $mostCode : '-') . '</strong><br>' . h(answer_option_text($row, $mostCode)) . '</td>'
            . '<td><strong>' . h($leastCode !== '' ? $leastCode : '-') . '</strong><br>' . h(answer_option_text($row, $leastCode)) . '</td>'
            . '<td>' . h($mappingText) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Jawaban Esai</h3>';
    echo '<table><thead><tr><th>No</th><th>Kelompok</th><th>Pertanyaan</th><th>Jawaban</th></tr></thead><tbody>';
    foreach ($essayRows as $row) {
        $questionText = trim((string) ($row['question_text'] ?? ''));
        $answerText = trim((string) ($row['answer_text'] ?? ''));
        echo '<tr>'
            . '<td>' . h((string) ((int) ($row['question_order'] ?? 0))) . '</td>'
            . '<td>' . h((string) ($row['role_group'] ?? '-')) . '</td>'
            . '<td>' . h($questionText !== '' ? $questionText : '(Soal sudah tidak tersedia di bank soal)') . '</td>'
            . '<td>' . nl2br(h($answerText !== '' ? $answerText : '')) . '</td>'
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

    echo '<h3>Event Timeline Lengkap (Snapshot Journey)</h3>';
    echo '<table><thead><tr><th>Waktu</th><th>Fase</th><th>Event</th><th>Nilai</th><th>Metadata Snapshot</th></tr></thead><tbody>';
    foreach ($journeyEvents as $ev) {
        echo '<tr>'
            . '<td>' . h(format_date_id((string) ($ev['created_at'] ?? ''))) . '</td>'
            . '<td>' . h((string) ($ev['phase_label'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($ev['event_label'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($ev['value_label'] ?? '-')) . '</td>'
            . '<td>' . h((string) ($ev['payload_text'] ?? '-')) . '</td>'
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

    echo '</body></html>';
    exit;
}

if ($method === 'GET' && $path === '/hr/essay-questions') {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $groupFilter = trim((string) ($_GET['group'] ?? ''));
    if ($groupFilter !== '' && !in_array($groupFilter, $essayGroupOptions, true)) {
        $groupFilter = '';
    }

    $flashMessage = $_SESSION['essay_questions_flash_message'] ?? null;
    $flashType = $_SESSION['essay_questions_flash_type'] ?? 'info';
    unset($_SESSION['essay_questions_flash_message'], $_SESSION['essay_questions_flash_type']);

    $bulkPreviewRows = $_SESSION['essay_questions_bulk_preview_rows'] ?? [];
    $bulkPreviewMode = $_SESSION['essay_questions_bulk_preview_mode'] ?? 'append';
    $bulkPreviewSummary = $_SESSION['essay_questions_bulk_preview_summary'] ?? [];
    $bulkPreviewTotal = $_SESSION['essay_questions_bulk_preview_total'] ?? 0;
    $bulkErrorCount = isset($_SESSION['essay_questions_bulk_errors']) && is_array($_SESSION['essay_questions_bulk_errors'])
        ? count($_SESSION['essay_questions_bulk_errors'])
        : 0;

    $page = normalize_page_param($_GET['page'] ?? 1);
    $perPage = normalize_per_page_param($_GET['per_page'] ?? 20);
    $sortBy = normalize_essay_sort_by($_GET['sort_by'] ?? 'default');
    $sortDir = normalize_sort_dir($_GET['sort_dir'] ?? 'asc');
    $total = count_essay_questions($pdo, true, $groupFilter !== '' ? $groupFilter : null);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    render('hr/essay-questions', [
        'page_title' => 'Kelola Soal Esai',
        'essay_questions' => list_essay_questions_paginated($pdo, true, $groupFilter !== '' ? $groupFilter : null, $page, $perPage, $sortBy, $sortDir),
        'essay_group_options' => $essayGroupOptions,
        'group_filter' => $groupFilter,
        'sort_by' => $sortBy,
        'sort_dir' => $sortDir,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
            'to' => $total > 0 ? min($total, $page * $perPage) : 0,
        ],
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

if ($method === 'GET' && $path === '/hr/essay-questions/new') {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $group = trim((string) ($_GET['group'] ?? 'Manager'));
    if (!in_array($group, $essayGroupOptions, true)) {
        $group = 'Manager';
    }
    $flashMessage = $_SESSION['essay_question_form_flash_message'] ?? null;
    $flashType = $_SESSION['essay_question_form_flash_type'] ?? 'success';
    unset($_SESSION['essay_question_form_flash_message'], $_SESSION['essay_question_form_flash_type']);

    render('hr/essay-question-form', [
        'page_title' => 'Tambah Soal Esai',
        'form_title' => 'Tambah Soal Esai',
        'action_url' => route_path('/hr/essay-questions/new'),
        'is_create' => true,
        'essay_group_options' => $essayGroupOptions,
        'auto_order_enabled' => true,
        'next_order_map' => build_essay_next_order_map($pdo, $essayGroupOptions),
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'success',
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
    $essayGroupOptions = load_essay_group_options($pdo);

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
            'auto_order_enabled' => true,
            'next_order_map' => build_essay_next_order_map($pdo, $essayGroupOptions),
            'values' => $payload,
            'error_message' => 'Kelompok role wajib valid, urutan > 0, dan pertanyaan minimal 10 karakter.',
        ]);
        exit;
    }

    create_essay_question($pdo, $payload);
    if (!empty($_POST['save_and_add'])) {
        $_SESSION['essay_question_form_flash_message'] = 'Soal esai berhasil ditambahkan. Silakan tambah soal berikutnya.';
        $_SESSION['essay_question_form_flash_type'] = 'success';
        redirect(route_path('/hr/essay-questions/new?group=' . urlencode((string) $payload['role_group'])));
    }

    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil ditambahkan.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'GET' && preg_match('#^/hr/essay-questions/(\d+)/edit$#', $path, $m)) {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $returnQueryParams = [];
    if (array_key_exists('group', $_GET)) {
        $group = trim((string) ($_GET['group'] ?? ''));
        if ($group !== '' && in_array($group, $essayGroupOptions, true)) {
            $returnQueryParams['group'] = $group;
        }
    }
    if (array_key_exists('sort_by', $_GET)) {
        $sortBy = normalize_essay_sort_by($_GET['sort_by'] ?? 'default');
        if ($sortBy !== 'default') {
            $returnQueryParams['sort_by'] = $sortBy;
        }
    }
    if (array_key_exists('sort_dir', $_GET)) {
        $sortDir = normalize_sort_dir($_GET['sort_dir'] ?? 'asc');
        if ($sortDir !== 'asc') {
            $returnQueryParams['sort_dir'] = $sortDir;
        }
    }
    if (array_key_exists('per_page', $_GET)) {
        $perPage = normalize_per_page_param($_GET['per_page'] ?? 20);
        if ($perPage !== 20) {
            $returnQueryParams['per_page'] = $perPage;
        }
    }
    if (array_key_exists('page', $_GET)) {
        $page = normalize_page_param($_GET['page'] ?? 1);
        if ($page > 1) {
            $returnQueryParams['page'] = $page;
        }
    }
    $returnQuery = http_build_query($returnQueryParams);

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
        'is_create' => false,
        'essay_group_options' => $essayGroupOptions,
        'auto_order_enabled' => false,
        'next_order_map' => [],
        'return_query' => $returnQuery,
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
    $essayGroupOptions = load_essay_group_options($pdo);
    $returnQueryRaw = trim((string) ($_POST['return_query'] ?? ''));
    $returnQueryParams = [];
    if ($returnQueryRaw !== '') {
        $parsed = [];
        parse_str($returnQueryRaw, $parsed);
        if (is_array($parsed)) {
            $group = trim((string) ($parsed['group'] ?? ''));
            if ($group !== '' && in_array($group, $essayGroupOptions, true)) {
                $returnQueryParams['group'] = $group;
            }
            if (array_key_exists('sort_by', $parsed)) {
                $sortBy = normalize_essay_sort_by($parsed['sort_by'] ?? 'default');
                if ($sortBy !== 'default') {
                    $returnQueryParams['sort_by'] = $sortBy;
                }
            }
            if (array_key_exists('sort_dir', $parsed)) {
                $sortDir = normalize_sort_dir($parsed['sort_dir'] ?? 'asc');
                if ($sortDir !== 'asc') {
                    $returnQueryParams['sort_dir'] = $sortDir;
                }
            }
            if (array_key_exists('per_page', $parsed)) {
                $perPage = normalize_per_page_param($parsed['per_page'] ?? 20);
                if ($perPage !== 20) {
                    $returnQueryParams['per_page'] = $perPage;
                }
            }
            if (array_key_exists('page', $parsed)) {
                $page = normalize_page_param($parsed['page'] ?? 1);
                if ($page > 1) {
                    $returnQueryParams['page'] = $page;
                }
            }
        }
    }
    $returnQuery = http_build_query($returnQueryParams);

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
            'auto_order_enabled' => false,
            'next_order_map' => [],
            'return_query' => $returnQuery,
            'values' => $payload,
            'error_message' => 'Kelompok role wajib valid, urutan > 0, dan pertanyaan minimal 10 karakter.',
        ]);
        exit;
    }

    update_essay_question($pdo, (int) $m[1], $payload);
    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil diperbarui.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    $redirectPath = '/hr/essay-questions';
    if ($returnQuery !== '') {
        $redirectPath .= '?' . $returnQuery;
    }
    redirect(route_path($redirectPath));
}

if ($method === 'POST' && preg_match('#^/hr/essay-questions/(\d+)/toggle-active$#', $path, $m)) {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $returnQueryRaw = trim((string) ($_POST['return_query'] ?? ''));
    $returnQueryParams = [];
    if ($returnQueryRaw !== '') {
        $parsed = [];
        parse_str($returnQueryRaw, $parsed);
        if (is_array($parsed)) {
            $group = trim((string) ($parsed['group'] ?? ''));
            if ($group !== '' && in_array($group, $essayGroupOptions, true)) {
                $returnQueryParams['group'] = $group;
            }
            if (array_key_exists('sort_by', $parsed)) {
                $sortBy = normalize_essay_sort_by($parsed['sort_by'] ?? 'default');
                if ($sortBy !== 'default') {
                    $returnQueryParams['sort_by'] = $sortBy;
                }
            }
            if (array_key_exists('sort_dir', $parsed)) {
                $sortDir = normalize_sort_dir($parsed['sort_dir'] ?? 'asc');
                if ($sortDir !== 'asc') {
                    $returnQueryParams['sort_dir'] = $sortDir;
                }
            }
            if (array_key_exists('per_page', $parsed)) {
                $perPage = normalize_per_page_param($parsed['per_page'] ?? 20);
                if ($perPage !== 20) {
                    $returnQueryParams['per_page'] = $perPage;
                }
            }
            if (array_key_exists('page', $parsed)) {
                $page = normalize_page_param($parsed['page'] ?? 1);
                if ($page > 1) {
                    $returnQueryParams['page'] = $page;
                }
            }
        }
    }
    $returnQuery = http_build_query($returnQueryParams);

    toggle_essay_question_active($pdo, (int) $m[1]);
    $_SESSION['essay_questions_flash_message'] = 'Status soal esai berhasil diubah.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    $redirectPath = '/hr/essay-questions';
    if ($returnQuery !== '') {
        $redirectPath .= '?' . $returnQuery;
    }
    redirect(route_path($redirectPath));
}

if ($method === 'POST' && preg_match('#^/hr/essay-questions/(\d+)/delete$#', $path, $m)) {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $returnQueryRaw = trim((string) ($_POST['return_query'] ?? ''));
    $returnQueryParams = [];
    if ($returnQueryRaw !== '') {
        $parsed = [];
        parse_str($returnQueryRaw, $parsed);
        if (is_array($parsed)) {
            $group = trim((string) ($parsed['group'] ?? ''));
            if ($group !== '' && in_array($group, $essayGroupOptions, true)) {
                $returnQueryParams['group'] = $group;
            }
            if (array_key_exists('sort_by', $parsed)) {
                $sortBy = normalize_essay_sort_by($parsed['sort_by'] ?? 'default');
                if ($sortBy !== 'default') {
                    $returnQueryParams['sort_by'] = $sortBy;
                }
            }
            if (array_key_exists('sort_dir', $parsed)) {
                $sortDir = normalize_sort_dir($parsed['sort_dir'] ?? 'asc');
                if ($sortDir !== 'asc') {
                    $returnQueryParams['sort_dir'] = $sortDir;
                }
            }
            if (array_key_exists('per_page', $parsed)) {
                $perPage = normalize_per_page_param($parsed['per_page'] ?? 20);
                if ($perPage !== 20) {
                    $returnQueryParams['per_page'] = $perPage;
                }
            }
            if (array_key_exists('page', $parsed)) {
                $page = normalize_page_param($parsed['page'] ?? 1);
                if ($page > 1) {
                    $returnQueryParams['page'] = $page;
                }
            }
        }
    }
    $returnQuery = http_build_query($returnQueryParams);

    delete_essay_question($pdo, (int) $m[1]);
    $_SESSION['essay_questions_flash_message'] = 'Soal esai berhasil dihapus.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    $redirectPath = '/hr/essay-questions';
    if ($returnQuery !== '') {
        $redirectPath .= '?' . $returnQuery;
    }
    redirect(route_path($redirectPath));
}

if ($method === 'POST' && $path === '/hr/essay-questions/bulk-delete') {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);
    $returnQueryRaw = trim((string) ($_POST['return_query'] ?? ''));
    $returnQueryParams = [];
    if ($returnQueryRaw !== '') {
        $parsed = [];
        parse_str($returnQueryRaw, $parsed);
        if (is_array($parsed)) {
            $group = trim((string) ($parsed['group'] ?? ''));
            if ($group !== '' && in_array($group, $essayGroupOptions, true)) {
                $returnQueryParams['group'] = $group;
            }
            if (array_key_exists('sort_by', $parsed)) {
                $sortBy = normalize_essay_sort_by($parsed['sort_by'] ?? 'default');
                if ($sortBy !== 'default') {
                    $returnQueryParams['sort_by'] = $sortBy;
                }
            }
            if (array_key_exists('sort_dir', $parsed)) {
                $sortDir = normalize_sort_dir($parsed['sort_dir'] ?? 'asc');
                if ($sortDir !== 'asc') {
                    $returnQueryParams['sort_dir'] = $sortDir;
                }
            }
            if (array_key_exists('per_page', $parsed)) {
                $perPage = normalize_per_page_param($parsed['per_page'] ?? 20);
                if ($perPage !== 20) {
                    $returnQueryParams['per_page'] = $perPage;
                }
            }
            if (array_key_exists('page', $parsed)) {
                $page = normalize_page_param($parsed['page'] ?? 1);
                if ($page > 1) {
                    $returnQueryParams['page'] = $page;
                }
            }
        }
    }
    $returnQuery = http_build_query($returnQueryParams);

    $ids = parse_bulk_ids_csv((string) ($_POST['ids_csv'] ?? ''));
    if (empty($ids)) {
        $_SESSION['essay_questions_flash_message'] = 'Pilih minimal 1 soal esai.';
        $_SESSION['essay_questions_flash_type'] = 'error';
        $redirectPath = '/hr/essay-questions';
        if ($returnQuery !== '') {
            $redirectPath .= '?' . $returnQuery;
        }
        redirect(route_path($redirectPath));
    }

    $deleted = delete_essay_questions_bulk($pdo, $ids);
    $_SESSION['essay_questions_flash_message'] = "Berhasil menghapus {$deleted} soal esai.";
    $_SESSION['essay_questions_flash_type'] = 'success';
    $redirectPath = '/hr/essay-questions';
    if ($returnQuery !== '') {
        $redirectPath .= '?' . $returnQuery;
    }
    redirect(route_path($redirectPath));
}

if ($method === 'GET' && $path === '/hr/essay-questions/template.csv') {
    require_hr_auth($config);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template-soal-esai.csv"');
    echo build_bulk_essay_question_template_csv(load_essay_group_options($pdo));
    exit;
}

if ($method === 'GET' && $path === '/hr/essay-questions/bulk-errors.csv') {
    require_hr_auth($config);

    $errors = $_SESSION['essay_questions_bulk_errors'] ?? [];
    if (!is_array($errors) || empty($errors)) {
        http_response_code(404);
        echo 'Tidak ada error preview untuk diunduh.';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bulk-import-essay-errors-' . time() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'Pesan Error']);
    foreach (array_values($errors) as $idx => $message) {
        fputcsv($out, [$idx + 1, (string) $message]);
    }
    fclose($out);
    exit;
}

if ($method === 'POST' && $path === '/hr/essay-questions/bulk-preview-clear') {
    require_hr_auth($config);
    unset(
        $_SESSION['essay_questions_bulk_preview_rows'],
        $_SESSION['essay_questions_bulk_preview_mode'],
        $_SESSION['essay_questions_bulk_preview_summary'],
        $_SESSION['essay_questions_bulk_preview_total'],
        $_SESSION['essay_questions_bulk_errors']
    );
    $_SESSION['essay_questions_flash_message'] = 'Preview import esai telah dibersihkan.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'POST' && $path === '/hr/essay-questions/bulk-preview') {
    require_hr_auth($config);
    $essayGroupOptions = load_essay_group_options($pdo);

    $csvRaw = extract_bulk_csv_input();
    if ($csvRaw === '') {
        unset($_SESSION['essay_questions_bulk_preview_rows'], $_SESSION['essay_questions_bulk_preview_mode'], $_SESSION['essay_questions_bulk_preview_summary'], $_SESSION['essay_questions_bulk_preview_total']);
        $_SESSION['essay_questions_bulk_errors'] = ['Import gagal: isi CSV kosong. Tempel CSV atau upload file .csv.'];
        $_SESSION['essay_questions_flash_message'] = 'Import gagal: isi CSV kosong. Tempel CSV atau upload file .csv.';
        $_SESSION['essay_questions_flash_type'] = 'error';
        redirect(route_path('/hr/essay-questions'));
    }

    $importMode = trim((string) ($_POST['import_mode'] ?? 'append'));
    if (!in_array($importMode, ['append', 'replace'], true)) {
        $importMode = 'append';
    }

    $parsed = parse_bulk_essay_questions_csv($csvRaw, $essayGroupOptions);
    $rows = $parsed['rows'] ?? [];
    $errors = $parsed['errors'] ?? [];
    $existingKeys = get_essay_question_group_order_keys($pdo);
    $errors = array_merge($errors, validate_bulk_essay_questions_rows($rows, $existingKeys, $importMode));

    if (!empty($errors)) {
        unset($_SESSION['essay_questions_bulk_preview_rows'], $_SESSION['essay_questions_bulk_preview_mode'], $_SESSION['essay_questions_bulk_preview_summary'], $_SESSION['essay_questions_bulk_preview_total']);
        $_SESSION['essay_questions_bulk_errors'] = $errors;
        $firstFive = array_slice($errors, 0, 5);
        $_SESSION['essay_questions_flash_message'] = 'Preview gagal: ' . implode(' | ', $firstFive);
        $_SESSION['essay_questions_flash_type'] = 'error';
        redirect(route_path('/hr/essay-questions'));
    }

    $_SESSION['essay_questions_bulk_preview_rows'] = $rows;
    $_SESSION['essay_questions_bulk_preview_mode'] = $importMode;
    $_SESSION['essay_questions_bulk_preview_summary'] = summarize_bulk_essay_questions_by_group($rows);
    $_SESSION['essay_questions_bulk_preview_total'] = count($rows);
    unset($_SESSION['essay_questions_bulk_errors']);
    $_SESSION['essay_questions_flash_message'] = 'Preview bulk esai siap. Silakan cek data lalu klik Konfirmasi Import.';
    $_SESSION['essay_questions_flash_type'] = 'success';
    redirect(route_path('/hr/essay-questions'));
}

if ($method === 'POST' && $path === '/hr/essay-questions/bulk-import-confirm') {
    require_hr_auth($config);

    $rows = $_SESSION['essay_questions_bulk_preview_rows'] ?? [];
    $importMode = $_SESSION['essay_questions_bulk_preview_mode'] ?? 'append';
    if (!is_array($rows) || empty($rows)) {
        $_SESSION['essay_questions_flash_message'] = 'Tidak ada data preview untuk diimport. Jalankan preview dulu.';
        $_SESSION['essay_questions_flash_type'] = 'error';
        redirect(route_path('/hr/essay-questions'));
    }

    if (!in_array($importMode, ['append', 'replace'], true)) {
        $importMode = 'append';
    }

    $existingKeys = get_essay_question_group_order_keys($pdo);
    $errors = validate_bulk_essay_questions_rows($rows, $existingKeys, $importMode);
    if (!empty($errors)) {
        unset($_SESSION['essay_questions_bulk_preview_rows'], $_SESSION['essay_questions_bulk_preview_mode'], $_SESSION['essay_questions_bulk_preview_summary'], $_SESSION['essay_questions_bulk_preview_total']);
        $_SESSION['essay_questions_bulk_errors'] = $errors;
        $firstFive = array_slice($errors, 0, 5);
        $_SESSION['essay_questions_flash_message'] = 'Import dibatalkan: ' . implode(' | ', $firstFive);
        $_SESSION['essay_questions_flash_type'] = 'error';
        redirect(route_path('/hr/essay-questions'));
    }

    $replaceExisting = ($importMode === 'replace');
    $inserted = create_essay_questions_bulk($pdo, $rows, $replaceExisting);
    $summary = summarize_bulk_essay_questions_by_group($rows);
    $parts = [];
    foreach ($summary as $group => $count) {
        $parts[] = "{$group}: {$count}";
    }

    unset($_SESSION['essay_questions_bulk_preview_rows'], $_SESSION['essay_questions_bulk_preview_mode'], $_SESSION['essay_questions_bulk_preview_summary'], $_SESSION['essay_questions_bulk_preview_total']);
    unset($_SESSION['essay_questions_bulk_errors']);
    $_SESSION['essay_questions_flash_message'] = "Berhasil import {$inserted} soal esai (" . implode(', ', $parts) . ').'
        . ($replaceExisting ? ' Mode: replace per kelompok role.' : ' Mode: append.');
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
    $flashMessage = $_SESSION['question_form_flash_message'] ?? null;
    $flashType = $_SESSION['question_form_flash_type'] ?? 'success';
    unset($_SESSION['question_form_flash_message'], $_SESSION['question_form_flash_type']);

    render('hr/question-form', [
        'page_title' => 'Tambah Soal DISC',
        'form_title' => 'Tambah Soal',
        'action_url' => route_path('/hr/questions/new'),
        'is_create' => true,
        'role_options' => question_role_options(),
        'flash_message' => is_string($flashMessage) ? $flashMessage : null,
        'flash_type' => is_string($flashType) ? $flashType : 'success',
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
    if (!empty($_POST['save_and_add'])) {
        $_SESSION['question_form_flash_message'] = 'Soal DISC berhasil ditambahkan. Silakan tambah soal berikutnya.';
        $_SESSION['question_form_flash_type'] = 'success';
        redirect(route_path('/hr/questions/new'));
    }

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
        'is_create' => false,
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

if ($method === 'POST' && $path === '/hr/questions/bulk-delete') {
    require_hr_auth($config);
    $ids = parse_bulk_ids_csv((string) ($_POST['ids_csv'] ?? ''));
    if (empty($ids)) {
        $_SESSION['questions_flash_message'] = 'Pilih minimal 1 soal DISC.';
        $_SESSION['questions_flash_type'] = 'error';
        redirect(route_path('/hr/questions'));
    }

    $deleted = delete_questions_bulk($pdo, $ids);
    $_SESSION['questions_flash_message'] = "Berhasil menghapus {$deleted} soal DISC.";
    $_SESSION['questions_flash_type'] = 'success';
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
