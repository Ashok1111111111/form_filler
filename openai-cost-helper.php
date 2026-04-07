<?php

function openaiEnvFloat(string $key, ?float $default = null): ?float {
    $value = getenv($key);
    if ($value === false || $value === '') return $default;
    return is_numeric($value) ? (float)$value : $default;
}

function openaiModelEnvSlug(string $model): string {
    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($model)));
    return trim($slug, '_');
}

function openaiDefaultPricing(string $model): array {
    $normalized = strtolower(trim($model));
    return match ($normalized) {
        'gpt-4o' => [
            'inputUsdPer1M' => 2.50,
            'cachedInputUsdPer1M' => 1.25,
            'outputUsdPer1M' => 10.00,
            'pricingSource' => 'default_official_openai_pricing',
        ],
        'gpt-4o-mini' => [
            'inputUsdPer1M' => 0.15,
            'cachedInputUsdPer1M' => 0.075,
            'outputUsdPer1M' => 0.60,
            'pricingSource' => 'default_official_openai_pricing',
        ],
        default => [
            'inputUsdPer1M' => null,
            'cachedInputUsdPer1M' => null,
            'outputUsdPer1M' => null,
            'pricingSource' => 'env_required',
        ],
    };
}

function openaiModelPricing(string $model): array {
    $defaults = openaiDefaultPricing($model);
    $slug = openaiModelEnvSlug($model);

    $inputUsdPer1M = openaiEnvFloat("OPENAI_PRICE_{$slug}_INPUT_USD_PER_1M", $defaults['inputUsdPer1M']);
    $cachedInputUsdPer1M = openaiEnvFloat("OPENAI_PRICE_{$slug}_CACHED_INPUT_USD_PER_1M", $defaults['cachedInputUsdPer1M']);
    $outputUsdPer1M = openaiEnvFloat("OPENAI_PRICE_{$slug}_OUTPUT_USD_PER_1M", $defaults['outputUsdPer1M']);
    $usdToInr = openaiEnvFloat('OPENAI_USD_TO_INR', 86.00);

    $pricingSource = 'env_override';
    if (
        $inputUsdPer1M === $defaults['inputUsdPer1M']
        && $cachedInputUsdPer1M === $defaults['cachedInputUsdPer1M']
        && $outputUsdPer1M === $defaults['outputUsdPer1M']
    ) {
        $pricingSource = $defaults['pricingSource'];
    }

    return [
        'inputUsdPer1M' => $inputUsdPer1M,
        'cachedInputUsdPer1M' => $cachedInputUsdPer1M,
        'outputUsdPer1M' => $outputUsdPer1M,
        'usdToInr' => $usdToInr,
        'pricingSource' => $pricingSource,
    ];
}

function openaiExtractUsage(array $response): array {
    $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

    $promptTokens = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
    $completionTokens = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
    $totalTokens = (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
    $cachedPromptTokens = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

    if ($cachedPromptTokens < 0) $cachedPromptTokens = 0;
    if ($cachedPromptTokens > $promptTokens) $cachedPromptTokens = $promptTokens;

    return [
        'promptTokens' => $promptTokens,
        'completionTokens' => $completionTokens,
        'totalTokens' => $totalTokens,
        'cachedPromptTokens' => $cachedPromptTokens,
        'uncachedPromptTokens' => max(0, $promptTokens - $cachedPromptTokens),
    ];
}

function openaiBuildUsageMeta(string $model, array $response): array {
    $usage = openaiExtractUsage($response);
    $pricing = openaiModelPricing($model);

    $costKnown = $pricing['inputUsdPer1M'] !== null
        && $pricing['cachedInputUsdPer1M'] !== null
        && $pricing['outputUsdPer1M'] !== null
        && $pricing['usdToInr'] !== null;

    $costUsd = null;
    $costRs = null;

    if ($costKnown) {
        $costUsd =
            ($usage['uncachedPromptTokens'] / 1000000) * $pricing['inputUsdPer1M'] +
            ($usage['cachedPromptTokens'] / 1000000) * $pricing['cachedInputUsdPer1M'] +
            ($usage['completionTokens'] / 1000000) * $pricing['outputUsdPer1M'];
        $costRs = $costUsd * $pricing['usdToInr'];
    }

    return [
        'model' => $model,
        'promptTokens' => $usage['promptTokens'],
        'completionTokens' => $usage['completionTokens'],
        'totalTokens' => $usage['totalTokens'],
        'cachedPromptTokens' => $usage['cachedPromptTokens'],
        'uncachedPromptTokens' => $usage['uncachedPromptTokens'],
        'costKnown' => $costKnown,
        'costUsd' => $costUsd !== null ? round($costUsd, 6) : null,
        'costRs' => $costRs !== null ? round($costRs, 4) : null,
        'pricingSource' => $pricing['pricingSource'],
        'pricing' => [
            'inputUsdPer1M' => $pricing['inputUsdPer1M'],
            'cachedInputUsdPer1M' => $pricing['cachedInputUsdPer1M'],
            'outputUsdPer1M' => $pricing['outputUsdPer1M'],
            'usdToInr' => $pricing['usdToInr'],
        ],
    ];
}

function openaiAggregateUsageMeta(array $metas): array {
    $aggregate = [
        'callCount' => 0,
        'promptTokens' => 0,
        'completionTokens' => 0,
        'totalTokens' => 0,
        'cachedPromptTokens' => 0,
        'uncachedPromptTokens' => 0,
        'costKnown' => true,
        'costUsd' => 0.0,
        'costRs' => 0.0,
        'models' => [],
        'pricingSources' => [],
    ];

    foreach ($metas as $meta) {
        if (!is_array($meta) || empty($meta['model'])) continue;

        $aggregate['callCount']++;
        $aggregate['promptTokens'] += (int)($meta['promptTokens'] ?? 0);
        $aggregate['completionTokens'] += (int)($meta['completionTokens'] ?? 0);
        $aggregate['totalTokens'] += (int)($meta['totalTokens'] ?? 0);
        $aggregate['cachedPromptTokens'] += (int)($meta['cachedPromptTokens'] ?? 0);
        $aggregate['uncachedPromptTokens'] += (int)($meta['uncachedPromptTokens'] ?? 0);

        $model = (string)$meta['model'];
        $aggregate['models'][$model] = ($aggregate['models'][$model] ?? 0) + 1;

        $source = (string)($meta['pricingSource'] ?? 'unknown');
        $aggregate['pricingSources'][$source] = ($aggregate['pricingSources'][$source] ?? 0) + 1;

        if (!($meta['costKnown'] ?? false)) {
            $aggregate['costKnown'] = false;
        }

        if ($meta['costKnown'] ?? false) {
            $aggregate['costUsd'] += (float)($meta['costUsd'] ?? 0);
            $aggregate['costRs'] += (float)($meta['costRs'] ?? 0);
        }
    }

    if ($aggregate['callCount'] === 0) {
        $aggregate['costKnown'] = false;
        $aggregate['costUsd'] = null;
        $aggregate['costRs'] = null;
        return $aggregate;
    }

    if ($aggregate['costKnown']) {
        $aggregate['costUsd'] = round($aggregate['costUsd'], 6);
        $aggregate['costRs'] = round($aggregate['costRs'], 4);
    } else {
        $aggregate['costUsd'] = null;
        $aggregate['costRs'] = null;
    }

    return $aggregate;
}
