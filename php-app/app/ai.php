<?php

declare(strict_types=1);

function ai_can_evaluate(array $config): array
{
    if (empty($config['ai_evaluation_enabled'])) {
        return ['ok' => false, 'message' => 'Fitur evaluasi AI belum diaktifkan (AI_EVALUATION_ENABLED=false).'];
    }
    if (trim((string) ($config['openai_api_key'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'OPENAI_API_KEY belum diisi di .env.'];
    }
    return ['ok' => true, 'message' => 'ready'];
}

function parse_json_object_from_text(string $text): ?array
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return null;
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $trimmed, $m) === 1) {
        $decoded2 = json_decode((string) $m[0], true);
        if (is_array($decoded2)) {
            return $decoded2;
        }
    }

    return null;
}

function extract_response_text_from_openai(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    $texts = [];
    $walker = static function ($node) use (&$walker, &$texts): void {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if ($k === 'text' && is_string($v)) {
                    $texts[] = $v;
                }
                $walker($v);
            }
        }
    };
    $walker($response);
    return trim(implode("\n", $texts));
}

function build_ai_payload_for_candidate(PDO $pdo, array $candidate): array
{
    $candidateId = (int) ($candidate['id'] ?? 0);
    $discRows = get_answer_details_for_candidate_export($pdo, $candidateId);
    $essayRows = get_essay_answer_details_for_candidate($pdo, $candidateId);
    $events = list_integrity_events($pdo, $candidateId, 400);
    $typingRows = get_essay_typing_metrics_for_candidate($pdo, $candidateId);
    $roleScores = extract_role_scores_from_candidate($candidate);
    $integrityRisk = integrity_risk_from_candidate($candidate);
    $typingRisk = typing_risk_from_rows($typingRows);

    $discAnswers = array_map(static function (array $row): array {
        $mostCode = (string) ($row['most_code'] ?? '');
        $leastCode = (string) ($row['least_code'] ?? '');
        return [
            'question_no' => (int) ($row['question_order'] ?? 0),
            'most_code' => $mostCode !== '' ? $mostCode : '-',
            'most_text' => answer_option_text($row, $mostCode),
            'least_code' => $leastCode !== '' ? $leastCode : '-',
            'least_text' => answer_option_text($row, $leastCode),
        ];
    }, $discRows);

    $essayAnswers = array_map(static function (array $row): array {
        return [
            'question_no' => (int) ($row['question_order'] ?? 0),
            'question_text' => (string) ($row['question_text'] ?? ''),
            'answer_text' => (string) ($row['answer_text'] ?? ''),
        ];
    }, $essayRows);

    $timeline = array_map(static function (array $ev): array {
        return [
            'time' => (string) ($ev['created_at'] ?? ''),
            'phase' => integrity_phase_label((string) ($ev['phase'] ?? '')),
            'event' => integrity_event_label((string) ($ev['event_type'] ?? '')),
            'detail' => integrity_event_value_label((string) ($ev['event_value'] ?? '')),
        ];
    }, $events);

    $typingMetrics = array_map(static function (array $row): array {
        return [
            'question_no' => (int) ($row['question_order'] ?? 0),
            'keystrokes' => (int) ($row['keystrokes'] ?? 0),
            'input_events' => (int) ($row['input_events'] ?? 0),
            'paste_count' => (int) ($row['paste_count'] ?? 0),
            'chars' => (int) ($row['total_chars'] ?? 0),
            'active_seconds' => round(((int) ($row['active_ms'] ?? 0)) / 1000, 1),
        ];
    }, $typingRows);

    return [
        'candidate_id' => $candidateId,
        'selected_role' => (string) ($candidate['selected_role'] ?? ''),
        'status' => (string) ($candidate['status'] ?? ''),
        'duration_seconds' => (int) ($candidate['duration_seconds'] ?? 0),
        'disc_scores' => [
            'D' => (int) ($candidate['disc_d'] ?? 0),
            'I' => (int) ($candidate['disc_i'] ?? 0),
            'S' => (int) ($candidate['disc_s'] ?? 0),
            'C' => (int) ($candidate['disc_c'] ?? 0),
        ],
        'role_scores' => $roleScores,
        'internal_recommendation' => map_recommendation_label((string) ($candidate['recommendation'] ?? '')),
        'internal_reason' => (string) ($candidate['reason'] ?? ''),
        'integrity_summary' => $integrityRisk,
        'typing_risk_summary' => $typingRisk,
        'disc_answers' => $discAnswers,
        'essay_answers' => $essayAnswers,
        'event_timeline' => $timeline,
        'typing_metrics' => $typingMetrics,
    ];
}

function run_openai_deep_evaluation(array $config, array $payload): array
{
    $apiKey = trim((string) ($config['openai_api_key'] ?? ''));
    $model = trim((string) ($config['openai_model'] ?? 'gpt-5.4'));
    $timeout = max(15, (int) ($config['openai_timeout_seconds'] ?? 60));
    $maxRetries = max(0, (int) ($config['openai_max_retries'] ?? 2));

    $systemPrompt = "Anda adalah assessor HR F&B senior. Berikan evaluasi kandidat berdasarkan data DISC, esai, event timeline, dan typing metrics. Keluarkan JSON valid saja dengan format: {\"score_1_10\":int,\"suggested_position\":string,\"conclusion\":string,\"rationale\":string,\"strengths\":string[],\"risks\":string[],\"follow_up_questions\":string[]} tanpa markdown.";

    $requestBody = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $systemPrompt],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ],
            ],
        ],
        'temperature' => 0.2,
        'max_output_tokens' => 1200,
    ];

    $attempt = 0;
    $lastError = '';
    $rawResponse = [];

    while ($attempt <= $maxRetries) {
        $attempt++;
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $lastError = 'Curl error: ' . $err;
            continue;
        }

        $decoded = json_decode((string) $resp, true);
        $rawResponse = is_array($decoded) ? $decoded : ['raw' => (string) $resp];

        if ($httpCode < 200 || $httpCode >= 300) {
            $lastError = 'OpenAI HTTP ' . $httpCode;
            continue;
        }

        $text = extract_response_text_from_openai($rawResponse);
        $json = parse_json_object_from_text($text);
        if (!is_array($json)) {
            $lastError = 'Model output bukan JSON valid.';
            continue;
        }

        return [
            'ok' => true,
            'model' => $model,
            'parsed' => $json,
            'raw_response' => $rawResponse,
            'error' => '',
        ];
    }

    return [
        'ok' => false,
        'model' => $model,
        'parsed' => [],
        'raw_response' => $rawResponse,
        'error' => $lastError !== '' ? $lastError : 'Gagal memproses AI evaluation.',
    ];
}
