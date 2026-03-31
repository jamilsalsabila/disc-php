<?php

declare(strict_types=1);

const ROLE_PROFILES = [
    'MANAGER' => ['label' => 'Manager', 'ideal' => ['D', 'I'], 'category' => 'LEADER'],
    'BACK_OFFICE' => ['label' => 'Back Office', 'ideal' => ['C', 'S'], 'category' => 'ADMIN_KITCHEN'],
    'HEAD_KITCHEN' => ['label' => 'Head Kitchen', 'ideal' => ['D', 'C'], 'category' => 'ADMIN_KITCHEN'],
    'HEAD_BAR' => ['label' => 'Head Bar', 'ideal' => ['D', 'I'], 'category' => 'SERVICE'],
    'FLOOR_CAPTAIN' => ['label' => 'Floor Captain', 'ideal' => ['I', 'D'], 'category' => 'SERVICE'],
    'COOK' => ['label' => 'Cook', 'ideal' => ['C', 'S'], 'category' => 'ADMIN_KITCHEN'],
    'COOK_HELPER' => ['label' => 'Cook Helper', 'ideal' => ['S', 'C'], 'category' => 'SUPPORT'],
    'STEWARD' => ['label' => 'Steward', 'ideal' => ['S', 'C'], 'category' => 'SUPPORT'],
    'MIXOLOGIST' => ['label' => 'Mixologist', 'ideal' => ['I', 'D'], 'category' => 'SERVICE'],
    'SERVER' => ['label' => 'Server', 'ideal' => ['I', 'S'], 'category' => 'SERVICE'],
    'HOUSEKEEPING' => ['label' => 'Housekeeping', 'ideal' => ['S', 'C'], 'category' => 'SUPPORT'],
];

const ROLE_LABEL_TO_KEY = [
    'Manager' => 'MANAGER',
    'Back Office' => 'BACK_OFFICE',
    'Head Kitchen' => 'HEAD_KITCHEN',
    'Head Bar' => 'HEAD_BAR',
    'Floor Captain' => 'FLOOR_CAPTAIN',
    'Cook' => 'COOK',
    'Cook Helper' => 'COOK_HELPER',
    'Steward' => 'STEWARD',
    'Mixologist' => 'MIXOLOGIST',
    'Server' => 'SERVER',
    'Housekeeping' => 'HOUSEKEEPING',
    // Legacy aliases
    'Back Office ( Admin )' => 'BACK_OFFICE',
    'Server Specialist' => 'SERVER',
    'Beverage Specialist' => 'MIXOLOGIST',
    'Senior Cook' => 'COOK',
    'Asisten Manager' => 'MANAGER',
    'Admin Operasional' => 'BACK_OFFICE',
    'Floor Crew ( Server, Runner, Housekeeping )' => 'SERVER',
    'Bar Crew' => 'MIXOLOGIST',
    'Kitchen Crew ( Cook, Cook Helper, Steward )' => 'COOK',
];

const ROLE_KEYS = [
    'MANAGER',
    'BACK_OFFICE',
    'HEAD_KITCHEN',
    'HEAD_BAR',
    'FLOOR_CAPTAIN',
    'COOK',
    'COOK_HELPER',
    'STEWARD',
    'MIXOLOGIST',
    'SERVER',
    'HOUSEKEEPING',
];

const PRIMARY_MIN_FIT = 6;

const DISC_LABELS = [
    'D' => 'Dominance',
    'I' => 'Influence',
    'S' => 'Steadiness',
    'C' => 'Conscientiousness',
];

const MIN_DIMENSION_BY_TRAIT = [
    'D' => 14,
    'I' => 16,
    'S' => 14,
    'C' => 16,
];

function normalize_role_key(?string $preferredRoleLabel): ?string
{
    if (!$preferredRoleLabel) {
        return null;
    }

    if (isset(ROLE_PROFILES[$preferredRoleLabel])) {
        return $preferredRoleLabel;
    }

    return ROLE_LABEL_TO_KEY[$preferredRoleLabel] ?? null;
}

function rank_disc_traits(array $discCounts): array
{
    arsort($discCounts);
    return array_keys($discCounts);
}

function role_fit_score_10(string $roleKey, array $discRawScores): int
{
    $profile = ROLE_PROFILES[$roleKey];
    $ideal = $profile['ideal'];
    $primary = $ideal[0] ?? 'D';
    $secondary = $ideal[1] ?? 'I';

    $primaryRaw = (int) ($discRawScores[$primary] ?? 0);
    $secondaryRaw = (int) ($discRawScores[$secondary] ?? 0);
    $base = (int) round(($primaryRaw + $secondaryRaw) / 4);
    $score = max(1, min(10, $base));

    $minPrimary = MIN_DIMENSION_BY_TRAIT[$primary] ?? 14;
    $minSecondary = MIN_DIMENSION_BY_TRAIT[$secondary] ?? 14;
    if ($primaryRaw < $minPrimary) {
        $score -= 1;
    }
    if ($secondaryRaw < $minSecondary) {
        $score -= 1;
    }
    return max(1, min(10, $score));
}

function detect_red_flags(string $roleKey, array $discRawScores): array
{
    $flags = [];
    $d = (int) ($discRawScores['D'] ?? 0);
    $i = (int) ($discRawScores['I'] ?? 0);
    $s = (int) ($discRawScores['S'] ?? 0);
    $c = (int) ($discRawScores['C'] ?? 0);

    if ($d < 10 && $i < 10 && $s < 10 && $c < 10) {
        $flags[] = ['level' => 'reject', 'message' => 'Semua dimensi DISC di bawah 10 (auto reject).'];
    }

    if (in_array($roleKey, ['BACK_OFFICE', 'HEAD_KITCHEN', 'COOK'], true) && $c < 12) {
        $flags[] = ['level' => 'reject', 'message' => 'C < 12 untuk role Admin/Kitchen (risiko ketelitian & SOP).'];
    }
    if ($roleKey === 'MANAGER' && $d < 12) {
        $flags[] = ['level' => 'reject', 'message' => 'D < 12 untuk role Manager (risiko ketegasan & kecepatan keputusan).'];
    }
    if ($roleKey === 'MANAGER' && $i < 12) {
        $flags[] = ['level' => 'reject', 'message' => 'I < 12 untuk role Manager (risiko komunikasi & pengaruh tim).'];
    }
    if (in_array($roleKey, ['SERVER', 'MIXOLOGIST', 'HEAD_BAR', 'FLOOR_CAPTAIN'], true) && $i < 12) {
        $flags[] = ['level' => 'reject', 'message' => 'I < 12 untuk role Service/Bar (risiko komunikasi customer).'];
    }
    if (in_array($roleKey, ['HOUSEKEEPING', 'STEWARD', 'COOK_HELPER'], true) && $s < 12) {
        $flags[] = ['level' => 'reject', 'message' => 'S < 12 untuk role Support (risiko kestabilan rutinitas).'];
    }

    if (in_array($roleKey, ['MANAGER', 'HEAD_KITCHEN', 'HEAD_BAR', 'FLOOR_CAPTAIN'], true) && $d > 22 && $s < 12) {
        $flags[] = ['level' => 'warning', 'message' => 'D > 22 dan S < 12 (risiko terlalu keras/tidak sabar).'];
    }
    if (in_array($roleKey, ['SERVER', 'MIXOLOGIST', 'HEAD_BAR', 'FLOOR_CAPTAIN'], true) && $i > 22 && $c < 12) {
        $flags[] = ['level' => 'warning', 'message' => 'I > 22 dan C < 12 (risiko kurang teliti).'];
    }
    if (in_array($roleKey, ['HEAD_KITCHEN', 'COOK', 'BACK_OFFICE'], true) && $c > 22 && $d < 10) {
        $flags[] = ['level' => 'warning', 'message' => 'C > 22 dan D < 10 (risiko lambat saat pressure).'];
    }
    if (in_array($roleKey, ['HOUSEKEEPING', 'STEWARD', 'COOK_HELPER'], true) && $s > 22 && $d < 10) {
        $flags[] = ['level' => 'warning', 'message' => 'S > 22 dan D < 10 (risiko pasif/kurang inisiatif).'];
    }

    return $flags;
}

function interview_bucket_label(int $score10): string
{
    if ($score10 >= 8) {
        return 'Strong Hire';
    }
    if ($score10 >= 7) {
        return 'Hire';
    }
    if ($score10 >= 6) {
        return 'Consider';
    }
    return 'Reject';
}

function generate_reason(string $recommendation, array $mostCounts, array $leastCounts, array $discRawScores, array $roleScores, ?string $preferredRoleLabel, array $meta = []): string
{
    $rankedTraits = rank_disc_traits($discRawScores);
    $dominant = $rankedTraits[0] ?? 'D';
    $secondary = $rankedTraits[1] ?? 'I';
    $dominantLabel = DISC_LABELS[$dominant] ?? $dominant;
    $secondaryLabel = DISC_LABELS[$secondary] ?? $secondary;

    $discText = sprintf(
        'Skor DISC (raw): D=%d, I=%d, S=%d, C=%d.',
        (int) ($discRawScores['D'] ?? 0),
        (int) ($discRawScores['I'] ?? 0),
        (int) ($discRawScores['S'] ?? 0),
        (int) ($discRawScores['C'] ?? 0)
    );

    if ($recommendation === 'TIDAK_DIREKOMENDASIKAN') {
        return "Dominan {$dominantLabel} dengan secondary {$secondaryLabel}. {$discText} Kandidat belum memenuhi syarat minimum kelayakan role.";
    }

    $recLabel = ROLE_PROFILES[$recommendation]['label'] ?? $recommendation;
    $recScore = (int) ($roleScores[$recommendation] ?? 0);
    $bucket = interview_bucket_label($recScore);
    $warningText = '';
    $warnings = $meta['warnings'] ?? [];
    if (!empty($warnings)) {
        $warningText = ' Warning: ' . implode(' | ', array_map(static function ($w) {
            return (string) ($w['message'] ?? '');
        }, $warnings)) . '.';
    }

    $preferredRoleKey = normalize_role_key($preferredRoleLabel);
    $preferredDisplay = $preferredRoleKey && isset(ROLE_PROFILES[$preferredRoleKey]) ? ROLE_PROFILES[$preferredRoleKey]['label'] : $preferredRoleLabel;
    $preferredNote = '';
    if ($preferredDisplay && $preferredDisplay !== $recLabel) {
        $preferredNote = " Role dipilih kandidat: {$preferredDisplay}, namun role paling cocok dari DISC adalah {$recLabel}.";
    }

    return "Dominan {$dominantLabel} dengan secondary {$secondaryLabel}. {$discText} Rekomendasi: {$recLabel} (score {$recScore}/10, bucket {$bucket}).{$preferredNote}{$warningText}";
}

function evaluate_candidate(array $answersByQuestion, ?string $preferredRoleLabel): array
{
    $totalQuestions = count($answersByQuestion);
    $mostCounts = ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];
    $leastCounts = ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];

    foreach ($answersByQuestion as $answer) {
        if (!empty($answer['mostDisc']) && array_key_exists($answer['mostDisc'], $mostCounts)) {
            $mostCounts[$answer['mostDisc']]++;
        }
        if (!empty($answer['leastDisc']) && array_key_exists($answer['leastDisc'], $leastCounts)) {
            $leastCounts[$answer['leastDisc']]++;
        }
    }

    $discRawScores = [
        'D' => ($mostCounts['D'] * 2) - $leastCounts['D'],
        'I' => ($mostCounts['I'] * 2) - $leastCounts['I'],
        'S' => ($mostCounts['S'] * 2) - $leastCounts['S'],
        'C' => ($mostCounts['C'] * 2) - $leastCounts['C'],
    ];

    $roleScores = [];
    foreach (ROLE_KEYS as $roleKey) {
        $roleScores[$roleKey] = role_fit_score_10($roleKey, $discRawScores);
    }

    $sortedRoles = ROLE_KEYS;
    usort($sortedRoles, static function ($a, $b) use ($roleScores) {
        return ($roleScores[$b] ?? 0) <=> ($roleScores[$a] ?? 0);
    });

    $topRole = $sortedRoles[0] ?? 'MANAGER';
    $preferredRoleKey = normalize_role_key($preferredRoleLabel);
    $evaluationRole = $preferredRoleKey ?? $topRole;
    $evaluationScore = (int) ($roleScores[$evaluationRole] ?? 0);
    $flags = detect_red_flags($evaluationRole, $discRawScores);

    $rejectReasons = [];
    foreach ($flags as $flag) {
        if (($flag['level'] ?? '') === 'reject') {
            $rejectReasons[] = (string) ($flag['message'] ?? 'Red flag');
        }
    }

    if ($evaluationScore < PRIMARY_MIN_FIT) {
        $rejectReasons[] = 'Skor role dipilih di bawah batas minimum.';
    }

    $recommendation = 'TIDAK_DIREKOMENDASIKAN';
    if ($evaluationScore >= PRIMARY_MIN_FIT && empty($rejectReasons)) {
        $recommendation = $evaluationRole;
    }

    return [
        'discCounts' => $discRawScores,
        'mostCounts' => $mostCounts,
        'leastCounts' => $leastCounts,
        'roleScores' => $roleScores,
        'recommendation' => $recommendation,
        'reason' => generate_reason($recommendation, $mostCounts, $leastCounts, $discRawScores, $roleScores, $preferredRoleLabel, [
            'warnings' => array_values(array_filter($flags, static function ($f) {
                return (($f['level'] ?? '') === 'warning');
            })),
            'reject_reasons' => $rejectReasons,
        ]),
        'reasonMeta' => [
            'reject_reasons' => $rejectReasons,
            'warnings' => array_values(array_filter($flags, static function ($f) {
                return (($f['level'] ?? '') === 'warning');
            })),
        ],
    ];
}

function build_incomplete_evaluation(array $base, int $answeredCount, int $totalQuestions): array
{
    $base['recommendation'] = 'INCOMPLETE';
    $base['reason'] = "Jawaban tidak lengkap ({$answeredCount}/{$totalQuestions}). Minimal kelengkapan belum terpenuhi sehingga hasil ditandai Incomplete.";
    return $base;
}
