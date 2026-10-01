<?php
function openai_request(string $endpoint, array $payload): array {
    global $config;
    if (empty($config['openai_enabled']) || empty($config['openai_api_key']) || $config['openai_api_key'] === 'PUT_OPENAI_API_KEY_HERE') {
        throw new Exception('OpenAI API key is not configured.');
    }
    $ch = curl_init('https://api.openai.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['openai_api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new Exception('OpenAI cURL error: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if ($code >= 400) {
        throw new Exception('OpenAI API error: ' . ($data['error']['message'] ?? $raw));
    }
    return $data;
}

function create_embedding(string $text): array {
    global $config;
    $data = openai_request('embeddings', [
        'model' => $config['embedding_model'] ?? 'text-embedding-3-small',
        'input' => $text,
    ]);
    return $data['data'][0]['embedding'] ?? [];
}

/* embedding with explicit model + dimensions + token usage (for Product Master search) */
function create_embedding_ex(string $text, string $model, int $dimensions = 0): array {
    $payload = ['model' => $model, 'input' => $text];
    if ($dimensions > 0) $payload['dimensions'] = $dimensions;
    $data = openai_request('embeddings', $payload);
    return [
        'vector' => $data['data'][0]['embedding'] ?? [],
        'tokens' => (int)($data['usage']['total_tokens'] ?? 0),
    ];
}

function gpt_answer(string $question, array $contexts): string {
    global $config;
    $contextText = implode("\n\n---\n\n", $contexts);
    $prompt = "You are ZAS Textile export document search assistant. Answer only from the provided shipment records. If not found, say not found.\n\nUser question:\n{$question}\n\nRetrieved shipment records:\n{$contextText}";

    $data = openai_request('responses', [
        'model' => $config['ai_answer_model'] ?? 'gpt-5-mini',
        'input' => $prompt,
        'text' => ['verbosity' => 'low'],
    ]);

    if (!empty($data['output_text'])) return $data['output_text'];

    $out = '';
    foreach (($data['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $c) {
            if (isset($c['text'])) $out .= $c['text'];
        }
    }
    return $out ?: 'No answer returned.';
}

function cosine_similarity(array $a, array $b): float {
    $dot = 0; $na = 0; $nb = 0;
    $len = min(count($a), count($b));
    for ($i=0; $i<$len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na += $a[$i] * $a[$i];
        $nb += $b[$i] * $b[$i];
    }
    if ($na == 0 || $nb == 0) return 0;
    return $dot / (sqrt($na) * sqrt($nb));
}
