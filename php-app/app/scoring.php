<?php

declare(strict_types=1);

const ROLE_PROFILES = [
    'SERVER_SPECIALIST' => [
        'label' => 'Server Specialist',
        'weights' => ['D' => 0.1, 'I' => 0.45, 'S' => 0.3, 'C' => 0.15],
        'gates' => ['I' => 6, 'S' => 4],
    ],
    'BEVERAGE_SPECIALIST' => [
        'label' => 'Beverage Specialist',
        'weights' => ['D' => 0.2, 'I' => 0.3, 'S' => 0.15, 'C' => 0.35],
        'gates' => ['I' => 5, 'C' => 5],
    ],
    'SENIOR_COOK' => [
        'label' => 'Senior Cook',
        'weights' => ['D' => 0.25, 'I' => 0.1, 'S' => 0.2, 'C' => 0.45],
        'gates' => ['D' => 5, 'C' => 7],
    ],
    'MANAGER' => [
        'label' => 'Manager',
        'weights' => ['D' => 0.4, 'I' => 0.3, 'S' => 0.15, 'C' => 0.15],
        'gates' => ['D' => 6, 'I' => 5],
    ],
    'ASSISTANT_MANAGER' => [
        'label' => 'Asisten Manager',
        'weights' => ['D' => 0.3, 'I' => 0.2, 'S' => 0.25, 'C' => 0.25],
        'gates' => ['D' => 5, 'S' => 5, 'C' => 5],
    ],
    'OPERATIONS_ADMIN' => [
        'label' => 'Admin Operasional',
        'weights' => ['D' => 0.1, 'I' => 0.1, 'S' => 0.3, 'C' => 0.5],
        'gates' => ['S' => 5, 'C' => 7],
    ],
];

const ROLE_LABEL_TO_KEY = [
    'Server Specialist' => 'SERVER_SPECIALIST',
    'Beverage Specialist' => 'BEVERAGE_SPECIALIST',
    'Senior Cook' => 'SENIOR_COOK',
    'Manager' => 'MANAGER',
    'Asisten Manager' => 'ASSISTANT_MANAGER',
    'Admin Operasional' => 'OPERATIONS_ADMIN',
];

const ROLE_GROUPS = [
    'SERVICE' => ['SERVER_SPECIALIST', 'BEVERAGE_SPECIALIST', 'SENIOR_COOK'],
    'MANAGEMENT' => ['MANAGER', 'ASSISTANT_MANAGER', 'OPERATIONS_ADMIN'],
];

const PRIMARY_MIN_FIT = 55;
const CROSS_GROUP_MIN_FIT = 60;

const DISC_LABELS = [
    'D' => 'Dominance',
    'I' => 'Influence',
    'S' => 'Steadiness',
    'C' => 'Conscientiousness',
];

function detect_role_group(?string $preferredRoleLabel): string
{
    $key = normalize_role_key($preferredRoleLabel);
    if ($key && in_array($key, ROLE_GROUPS['SERVICE'], true)) {
        return 'SERVICE';
    }
    if ($key && in_array($key, ROLE_GROUPS['MANAGEMENT'], true)) {
        return 'MANAGEMENT';
    }
    return 'SERVICE';
}

function opposite_role_group(string $group): string
{
    return $group === 'SERVICE' ? 'MANAGEMENT' : 'SERVICE';
}

function role_group_label(string $group): string
{
    if ($group === 'SERVICE') {
        return 'Service (Server Specialist / Beverage Specialist / Senior Cook)';
    }
    return 'Management (Manager / Asisten Manager / Admin Operasional)';
}

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

function calculate_role_fit_percent(array $compositeCounts, array $weights, int $totalQuestions): int
{
    $maxTraitScore = max(1, $totalQuestions * 2);
    $d = ($compositeCounts['D'] ?? 0) / $maxTraitScore;
    $i = ($compositeCounts['I'] ?? 0) / $maxTraitScore;
    $s = ($compositeCounts['S'] ?? 0) / $maxTraitScore;
    $c = ($compositeCounts['C'] ?? 0) / $maxTraitScore;

    $score = ($d * $weights['D']) + ($i * $weights['I']) + ($s * $weights['S']) + ($c * $weights['C']);
    return (int) round($score * 100);
}

function rank_disc_traits(array $discCounts): array
{
    arsort($discCounts);
    return array_keys($discCounts);
}

function passes_gate(array $mostCounts, array $gates): bool
{
    foreach ($gates as $trait => $min) {
        if (($mostCounts[$trait] ?? 0) < $min) {
            return false;
        }
    }
    return true;
}

function generate_reason(string $recommendation, array $mostCounts, array $leastCounts, array $compositeCounts, array $roleScores, ?string $preferredRoleLabel, array $meta = []): string
{
    $rankedTraits = rank_disc_traits($compositeCounts);
    $avoidedTraits = array_slice(rank_disc_traits($leastCounts), 0, 2);

    $topParts = [];
    foreach (array_slice($rankedTraits, 0, 2) as $t) {
        $topParts[] = sprintf('%s (Most %d, Least %d)', DISC_LABELS[$t], $mostCounts[$t] ?? 0, $leastCounts[$t] ?? 0);
    }
    $topTraits = implode(' & ', $topParts);

    if (!empty($meta['cross_group_role']) && !empty($meta['selected_group']) && !empty($meta['cross_group'])) {
        $selectedGroupLabel = role_group_label($meta['selected_group']);
        $crossGroupLabel = role_group_label($meta['cross_group']);
        $crossRoleLabel = ROLE_PROFILES[$meta['cross_group_role']]['label'] ?? $meta['cross_group_role'];
        return "Di grup pilihan {$selectedGroupLabel}, kandidat belum memenuhi ambang rekomendasi. Namun pada evaluasi lintas grup {$crossGroupLabel}, kandidat paling cocok pada role {$crossRoleLabel} berdasarkan kombinasi trait {$topTraits}.";
    }

    if ($recommendation === 'TIDAK_DIREKOMENDASIKAN') {
        $selectedGroupLabel = !empty($meta['selected_group']) ? role_group_label($meta['selected_group']) : 'grup awal';
        $crossGroupLabel = !empty($meta['cross_group']) ? role_group_label($meta['cross_group']) : 'grup alternatif';
        $avoid = implode(' & ', array_map(static function ($t) {
            return DISC_LABELS[$t] ?? $t;
        }, $avoidedTraits));
        return "Profil DISC menunjukkan kekuatan pada {$topTraits}, namun kecocokan minimum belum terpenuhi baik pada {$selectedGroupLabel} maupun {$crossGroupLabel}. Area yang paling sering dihindari: {$avoid}.";
    }

    $recLabel = ROLE_PROFILES[$recommendation]['label'] ?? $recommendation;
    $scoreParts = [];
    foreach ($roleScores as $k => $v) {
        $scoreParts[] = (ROLE_PROFILES[$k]['label'] ?? $k) . ': ' . $v . '%';
    }

    $preferredRoleKey = normalize_role_key($preferredRoleLabel);
    $preferredDisplay = $preferredRoleKey && isset(ROLE_PROFILES[$preferredRoleKey])
        ? ROLE_PROFILES[$preferredRoleKey]['label']
        : $preferredRoleLabel;

    $preferredNote = '';
    if ($preferredDisplay && $preferredDisplay !== $recLabel) {
        $preferredNote = " Role dipilih kandidat: {$preferredDisplay}, namun data menunjukkan kecocokan lebih kuat di {$recLabel}.";
    }

    return "Kandidat direkomendasikan sebagai {$recLabel} karena kombinasi trait utama {$topTraits} paling sesuai dengan profil role.{$preferredNote} Skor per role: " . implode(', ', $scoreParts) . '.';
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

    $compositeCounts = [
        'D' => $mostCounts['D'] + max(0, $totalQuestions - $leastCounts['D']),
        'I' => $mostCounts['I'] + max(0, $totalQuestions - $leastCounts['I']),
        'S' => $mostCounts['S'] + max(0, $totalQuestions - $leastCounts['S']),
        'C' => $mostCounts['C'] + max(0, $totalQuestions - $leastCounts['C']),
    ];

    $group = detect_role_group($preferredRoleLabel);
    $crossGroup = opposite_role_group($group);
    $groupRoleKeys = ROLE_GROUPS[$group];
    $crossRoleKeys = ROLE_GROUPS[$crossGroup];

    $roleScores = [];
    foreach (array_keys(ROLE_PROFILES) as $roleKey) {
        $profile = ROLE_PROFILES[$roleKey];
        $fit = calculate_role_fit_percent($compositeCounts, $profile['weights'], $totalQuestions);
        $roleScores[$roleKey] = $fit;
    }

    $eligibleRoles = [];
    foreach ($groupRoleKeys as $roleKey) {
        $profile = ROLE_PROFILES[$roleKey];
        if (($roleScores[$roleKey] ?? 0) >= PRIMARY_MIN_FIT && passes_gate($mostCounts, $profile['gates'])) {
            $eligibleRoles[] = $roleKey;
        }
    }

    $recommendation = 'TIDAK_DIREKOMENDASIKAN';
    $reasonMeta = [
        'selected_group' => $group,
        'cross_group' => $crossGroup,
    ];

    if (!empty($eligibleRoles)) {
        usort($eligibleRoles, static function ($a, $b) use ($roleScores) {
            return ($roleScores[$b] ?? 0) <=> ($roleScores[$a] ?? 0);
        });
        $recommendation = $eligibleRoles[0];
    } else {
        $crossEligibleRoles = [];
        foreach ($crossRoleKeys as $roleKey) {
            $profile = ROLE_PROFILES[$roleKey];
            if (($roleScores[$roleKey] ?? 0) >= CROSS_GROUP_MIN_FIT && passes_gate($mostCounts, $profile['gates'])) {
                $crossEligibleRoles[] = $roleKey;
            }
        }

        if (!empty($crossEligibleRoles)) {
            usort($crossEligibleRoles, static function ($a, $b) use ($roleScores) {
                return ($roleScores[$b] ?? 0) <=> ($roleScores[$a] ?? 0);
            });
            $recommendation = $crossEligibleRoles[0];
            $reasonMeta['cross_group_role'] = $recommendation;
        }
    }

    return [
        'discCounts' => $compositeCounts,
        'mostCounts' => $mostCounts,
        'leastCounts' => $leastCounts,
        'roleScores' => $roleScores,
        'recommendation' => $recommendation,
        'reason' => generate_reason($recommendation, $mostCounts, $leastCounts, $compositeCounts, $roleScores, $preferredRoleLabel, $reasonMeta),
        'reasonMeta' => $reasonMeta,
    ];
}

function build_incomplete_evaluation(array $base, int $answeredCount, int $totalQuestions): array
{
    $base['recommendation'] = 'INCOMPLETE';
    $base['reason'] = "Jawaban tidak lengkap ({$answeredCount}/{$totalQuestions}). Minimal kelengkapan belum terpenuhi sehingga hasil ditandai Incomplete.";
    return $base;
}
