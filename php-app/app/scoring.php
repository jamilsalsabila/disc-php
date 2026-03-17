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
];

const DISC_LABELS = [
    'D' => 'Dominance',
    'I' => 'Influence',
    'S' => 'Steadiness',
    'C' => 'Conscientiousness',
];

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

function generate_reason(string $recommendation, array $mostCounts, array $leastCounts, array $compositeCounts, array $roleScores, ?string $preferredRoleLabel): string
{
    $rankedTraits = rank_disc_traits($compositeCounts);
    $avoidedTraits = array_slice(rank_disc_traits($leastCounts), 0, 2);

    $topParts = [];
    foreach (array_slice($rankedTraits, 0, 2) as $t) {
        $topParts[] = sprintf('%s (Most %d, Least %d)', DISC_LABELS[$t], $mostCounts[$t] ?? 0, $leastCounts[$t] ?? 0);
    }
    $topTraits = implode(' & ', $topParts);

    if ($recommendation === 'TIDAK_DIREKOMENDASIKAN') {
        $avoid = implode(' & ', array_map(static fn ($t) => DISC_LABELS[$t] ?? $t, $avoidedTraits));
        return "Profil DISC menunjukkan kekuatan pada {$topTraits}, namun kecocokan minimum untuk 3 role utama belum terpenuhi. Area yang paling sering dihindari: {$avoid}.";
    }

    $recLabel = ROLE_PROFILES[$recommendation]['label'] ?? $recommendation;
    $scoreParts = [];
    foreach ($roleScores as $k => $v) {
        $scoreParts[] = (ROLE_PROFILES[$k]['label'] ?? $k) . ': ' . $v . '%';
    }

    $preferredNote = '';
    if ($preferredRoleLabel && $preferredRoleLabel !== $recLabel) {
        $preferredNote = " Role dipilih kandidat: {$preferredRoleLabel}, namun data menunjukkan kecocokan lebih kuat di {$recLabel}.";
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

    $roleScores = [];
    $eligibleRoles = [];

    foreach (ROLE_PROFILES as $roleKey => $profile) {
        $fit = calculate_role_fit_percent($compositeCounts, $profile['weights'], $totalQuestions);
        $roleScores[$roleKey] = $fit;
        if ($fit >= 55 && passes_gate($mostCounts, $profile['gates'])) {
            $eligibleRoles[] = $roleKey;
        }
    }

    $recommendation = 'TIDAK_DIREKOMENDASIKAN';
    if (!empty($eligibleRoles)) {
        usort($eligibleRoles, static fn ($a, $b) => ($roleScores[$b] ?? 0) <=> ($roleScores[$a] ?? 0));
        $recommendation = $eligibleRoles[0];
    }

    return [
        'discCounts' => $compositeCounts,
        'mostCounts' => $mostCounts,
        'leastCounts' => $leastCounts,
        'roleScores' => $roleScores,
        'recommendation' => $recommendation,
        'reason' => generate_reason($recommendation, $mostCounts, $leastCounts, $compositeCounts, $roleScores, $preferredRoleLabel),
    ];
}

function build_incomplete_evaluation(array $base, int $answeredCount, int $totalQuestions): array
{
    $base['recommendation'] = 'INCOMPLETE';
    $base['reason'] = "Jawaban tidak lengkap ({$answeredCount}/{$totalQuestions}). Minimal kelengkapan belum terpenuhi sehingga hasil ditandai Incomplete.";
    return $base;
}
