<?php

declare(strict_types=1);

const OPTION_TO_DISC = [
    'A' => 'D',
    'B' => 'I',
    'C' => 'S',
    'D' => 'C',
];

function parse_questions_from_txt(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return [];
    }

    $raw = str_replace("\r", '', $raw);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn ($line) => $line !== ''));

    $questions = [];
    $idx = 0;
    $total = count($lines);

    while ($idx < $total) {
        $maybeNumber = $lines[$idx];
        if (!preg_match('/^\d+$/', $maybeNumber)) {
            $idx++;
            continue;
        }

        $id = (int) $maybeNumber;
        $options = [];
        $idx++;

        while ($idx < $total && count($options) < 4) {
            $line = $lines[$idx];
            if (!preg_match('/^([A-D])\.\s+(.+)$/', $line, $matches)) {
                break;
            }

            $code = $matches[1];
            $options[] = [
                'code' => $code,
                'text' => $matches[2],
                'disc' => OPTION_TO_DISC[$code] ?? null,
            ];
            $idx++;
        }

        if (count($options) === 4) {
            $questions[] = [
                'id' => $id,
                'order' => $id,
                'options' => $options,
            ];
        }
    }

    usort($questions, static fn ($a, $b) => $a['id'] <=> $b['id']);
    return $questions;
}
