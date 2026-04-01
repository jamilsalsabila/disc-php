<?php

declare(strict_types=1);

const OPTION_TO_DISC = [
    'A' => 'D',
    'B' => 'I',
    'C' => 'S',
    'D' => 'C',
];

function decode_csv_escaped_newlines(string $value): string
{
    return str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
}

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
        if (!preg_match('/^(\d+)\.?$/', $maybeNumber, $numMatch)) {
            $idx++;
            continue;
        }

        $id = (int) $numMatch[1];
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

function parse_bulk_questions_csv(string $raw, array $allowedRoles): array
{
    $normalized = str_replace("\r", '', trim($raw));
    if ($normalized === '') {
        return ['rows' => [], 'errors' => ['Konten CSV kosong.']];
    }

    $lines = array_values(array_filter(explode("\n", $normalized), static fn ($line) => trim($line) !== ''));
    if (count($lines) < 2) {
        return ['rows' => [], 'errors' => ['CSV minimal berisi header dan 1 baris data.']];
    }

    $firstLine = $lines[0];
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $headers = array_map(static fn ($h) => strtolower(trim((string) $h)), str_getcsv($firstLine, $delimiter));
    $required = ['role_key', 'order', 'option_a', 'option_b', 'option_c', 'option_d', 'is_active'];

    foreach ($required as $col) {
        if (!in_array($col, $headers, true)) {
            return ['rows' => [], 'errors' => ["Header wajib '{$col}' tidak ditemukan."]];
        }
    }

    $rows = [];
    $errors = [];
    $headerCount = count($headers);

    for ($i = 1; $i < count($lines); $i++) {
        $lineNo = $i + 1;
        $values = str_getcsv($lines[$i], $delimiter);
        if (count($values) < $headerCount) {
            $values = array_pad($values, $headerCount, '');
        }
        $assoc = array_combine($headers, array_slice($values, 0, $headerCount));
        if (!is_array($assoc)) {
            $errors[] = "Baris {$lineNo}: format kolom tidak valid.";
            continue;
        }

        $roleKey = trim((string) ($assoc['role_key'] ?? ''));
        $order = (int) trim((string) ($assoc['order'] ?? '0'));
        $optionA = trim((string) ($assoc['option_a'] ?? ''));
        $optionB = trim((string) ($assoc['option_b'] ?? ''));
        $optionC = trim((string) ($assoc['option_c'] ?? ''));
        $optionD = trim((string) ($assoc['option_d'] ?? ''));
        $isActiveRaw = strtolower(trim((string) ($assoc['is_active'] ?? '1')));
        $isActive = in_array($isActiveRaw, ['1', 'true', 'yes', 'ya', 'aktif'], true);
        $discA = strtoupper(trim((string) ($assoc['disc_a'] ?? 'D')));
        $discB = strtoupper(trim((string) ($assoc['disc_b'] ?? 'I')));
        $discC = strtoupper(trim((string) ($assoc['disc_c'] ?? 'S')));
        $discD = strtoupper(trim((string) ($assoc['disc_d'] ?? 'C')));

        if (!in_array($roleKey, $allowedRoles, true)) {
            $errors[] = "Baris {$lineNo}: role '{$roleKey}' tidak valid.";
            continue;
        }
        if ($order <= 0) {
            $errors[] = "Baris {$lineNo}: kolom order harus angka > 0.";
            continue;
        }
        if (mb_strlen($optionA) < 3 || mb_strlen($optionB) < 3 || mb_strlen($optionC) < 3 || mb_strlen($optionD) < 3) {
            $errors[] = "Baris {$lineNo}: opsi A-D minimal 3 karakter.";
            continue;
        }
        if (!in_array($discA, ['D', 'I', 'S', 'C'], true)
            || !in_array($discB, ['D', 'I', 'S', 'C'], true)
            || !in_array($discC, ['D', 'I', 'S', 'C'], true)
            || !in_array($discD, ['D', 'I', 'S', 'C'], true)) {
            $errors[] = "Baris {$lineNo}: mapping DISC harus D/I/S/C.";
            continue;
        }

        $rows[] = [
            'line_no' => $lineNo,
            'role_key' => $roleKey,
            'order' => $order,
            'option_a' => $optionA,
            'option_b' => $optionB,
            'option_c' => $optionC,
            'option_d' => $optionD,
            'disc_a' => $discA,
            'disc_b' => $discB,
            'disc_c' => $discC,
            'disc_d' => $discD,
            'is_active' => $isActive,
        ];
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function summarize_bulk_questions_by_role(array $rows): array
{
    $summary = [];
    foreach ($rows as $row) {
        $role = (string) ($row['role_key'] ?? '');
        if ($role === '') {
            continue;
        }
        $summary[$role] = ($summary[$role] ?? 0) + 1;
    }
    ksort($summary);
    return $summary;
}

function validate_bulk_questions_rows(array $rows, array $existingKeys, string $importMode): array
{
    $errors = [];
    $seen = [];

    foreach ($rows as $row) {
        $role = (string) ($row['role_key'] ?? '');
        $order = (int) ($row['order'] ?? 0);
        $lineNo = (int) ($row['line_no'] ?? 0);
        $lineLabel = $lineNo > 0 ? "Baris {$lineNo}" : 'Baris CSV';
        $key = $role . '||' . $order;

        if (isset($seen[$key])) {
            $errors[] = "{$lineLabel}: duplikasi role + order ({$role} / {$order}) di file CSV.";
            continue;
        }
        $seen[$key] = true;

        if ($importMode === 'append' && isset($existingKeys[$key])) {
            $errors[] = "{$lineLabel}: role + order ({$role} / {$order}) sudah ada di database (mode append).";
        }
    }

    return $errors;
}

function build_bulk_question_template_csv(array $roleOptions): string
{
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['role_key', 'order', 'option_a', 'option_b', 'option_c', 'option_d', 'disc_a', 'disc_b', 'disc_c', 'disc_d', 'is_active']);

    $sampleRows = [
        [$roleOptions[0] ?? 'Manager', 1, 'Tegas saat mengambil keputusan', 'Ramah pada semua orang', 'Sabar dan konsisten', 'Teliti dan rapi', 'D', 'I', 'S', 'C', 1],
        [$roleOptions[1] ?? 'Back Office', 1, 'Cepat bertindak saat ramai', 'Suka membangun relasi', 'Menjaga ritme kerja stabil', 'Patuh SOP dan detail', 'D', 'I', 'S', 'C', 1],
        [$roleOptions[2] ?? 'Head Kitchen', 1, 'Berani ambil inisiatif', 'Komunikatif dalam tim', 'Tenang di bawah tekanan', 'Fokus kualitas hasil', 'D', 'I', 'S', 'C', 1],
    ];

    foreach ($sampleRows as $row) {
        fputcsv($out, $row);
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return $csv;
}

function parse_bulk_essay_questions_csv(string $raw, array $allowedGroups): array
{
    $normalized = str_replace("\r", '', trim($raw));
    if ($normalized === '') {
        return ['rows' => [], 'errors' => ['Konten CSV kosong.']];
    }

    $lines = array_values(array_filter(explode("\n", $normalized), static fn ($line) => trim($line) !== ''));
    if (count($lines) < 2) {
        return ['rows' => [], 'errors' => ['CSV minimal berisi header dan 1 baris data.']];
    }

    $firstLine = $lines[0];
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $headers = array_map(static fn ($h) => strtolower(trim((string) $h)), str_getcsv($firstLine, $delimiter));
    $required = ['role_group', 'order', 'question_text', 'guidance_text', 'is_active'];

    foreach ($required as $col) {
        if (!in_array($col, $headers, true)) {
            return ['rows' => [], 'errors' => ["Header wajib '{$col}' tidak ditemukan."]];
        }
    }

    $rows = [];
    $errors = [];
    $headerCount = count($headers);

    for ($i = 1; $i < count($lines); $i++) {
        $lineNo = $i + 1;
        $values = str_getcsv($lines[$i], $delimiter);
        if (count($values) < $headerCount) {
            $values = array_pad($values, $headerCount, '');
        }
        $assoc = array_combine($headers, array_slice($values, 0, $headerCount));
        if (!is_array($assoc)) {
            $errors[] = "Baris {$lineNo}: format kolom tidak valid.";
            continue;
        }

        $group = trim((string) ($assoc['role_group'] ?? ''));
        $order = (int) trim((string) ($assoc['order'] ?? '0'));
        $questionText = trim(decode_csv_escaped_newlines((string) ($assoc['question_text'] ?? '')));
        $guidanceText = trim(decode_csv_escaped_newlines((string) ($assoc['guidance_text'] ?? '')));
        $isActiveRaw = strtolower(trim((string) ($assoc['is_active'] ?? '1')));
        $isActive = in_array($isActiveRaw, ['1', 'true', 'yes', 'ya', 'aktif'], true);

        if (!in_array($group, $allowedGroups, true)) {
            $errors[] = "Baris {$lineNo}: kelompok role '{$group}' tidak valid.";
            continue;
        }
        if ($order <= 0) {
            $errors[] = "Baris {$lineNo}: kolom order harus angka > 0.";
            continue;
        }
        if (mb_strlen($questionText) < 10) {
            $errors[] = "Baris {$lineNo}: pertanyaan minimal 10 karakter.";
            continue;
        }

        $rows[] = [
            'line_no' => $lineNo,
            'role_group' => $group,
            'order' => $order,
            'question_text' => $questionText,
            'guidance_text' => $guidanceText,
            'is_active' => $isActive,
        ];
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function summarize_bulk_essay_questions_by_group(array $rows): array
{
    $summary = [];
    foreach ($rows as $row) {
        $group = (string) ($row['role_group'] ?? '');
        if ($group === '') {
            continue;
        }
        $summary[$group] = ($summary[$group] ?? 0) + 1;
    }
    ksort($summary);
    return $summary;
}

function validate_bulk_essay_questions_rows(array $rows, array $existingKeys, string $importMode): array
{
    $errors = [];
    $seen = [];

    foreach ($rows as $row) {
        $group = (string) ($row['role_group'] ?? '');
        $order = (int) ($row['order'] ?? 0);
        $lineNo = (int) ($row['line_no'] ?? 0);
        $lineLabel = $lineNo > 0 ? "Baris {$lineNo}" : 'Baris CSV';
        $key = $group . '||' . $order;

        if (isset($seen[$key])) {
            $errors[] = "{$lineLabel}: duplikasi role_group + order ({$group} / {$order}) di file CSV.";
            continue;
        }
        $seen[$key] = true;

        if ($importMode === 'append' && isset($existingKeys[$key])) {
            $errors[] = "{$lineLabel}: role_group + order ({$group} / {$order}) sudah ada di database (mode append).";
        }
    }

    return $errors;
}

function build_bulk_essay_question_template_csv(array $groupOptions): string
{
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['role_group', 'order', 'question_text', 'guidance_text', 'is_active']);

    $sampleRows = [
        [$groupOptions[0] ?? 'Manager', 1, 'Ceritakan pengalaman memimpin tim saat kondisi operasional padat.', 'Fokus pada konteks, tindakan, dan hasil.', 1],
        [$groupOptions[1] ?? 'Back office', 1, 'Bagaimana cara Anda memastikan data dan laporan harian tetap akurat?', 'Jelaskan langkah kerja dan kontrol kualitas.', 1],
        [$groupOptions[2] ?? 'Kitchen', 1, 'Apa yang Anda lakukan saat pesanan menumpuk dan ada komplain kualitas?', 'Sebut prioritas, koordinasi tim, dan penyelesaian.', 1],
    ];

    foreach ($sampleRows as $row) {
        fputcsv($out, $row);
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return $csv;
}
