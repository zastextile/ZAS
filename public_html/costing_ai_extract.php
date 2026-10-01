<?php
/*
  AI-Assisted Costing — single AJAX endpoint for product_costing.php's
  "Describe Product Costing" panel. One request covers ALL sizes (never one
  per size). Only ever returns a PREVIEW — nothing here writes to
  costing_versions / costing_lines. The browser applies the result into the
  existing client-side draft model; the existing Save Costing action (in
  product_costing.php's own AJAX handler) is still the only thing that
  persists anything to the database.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/ai_costing.php';
require_once __DIR__ . '/includes/pm_search.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (is_staff()) { echo json_encode(['success' => false, 'message' => 'Staff cannot access product costing.']); exit; }
if (!costing_perm('create') && !costing_perm('edit')) { echo json_encode(['success' => false, 'message' => 'No permission to create costings.']); exit; }

aic_ensure_schema();

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }
$tok = $data['_csrf'] ?? '';
if (!$tok || !hash_equals($_SESSION['_csrf'] ?? '', $tok)) { echo json_encode(['success' => false, 'message' => 'Session expired, please reload.']); exit; }

$action = $data['action'] ?? '';
$text = trim((string)($data['text'] ?? ''));

/* "Search Existing Costing" — SQL only, no AI call, instant & free. Works off
   whatever product name is typed (either the free text itself, or a hint). */
if ($action === 'search') {
    $hint = trim((string)($data['name_hint'] ?? $text));
    // take the text as-is for a name search; strip a leading "for a/an" style phrase if present
    $hint = preg_replace('/^(create costing for( a| an)?|costing for( a| an)?)/i', '', $hint);
    $hint = trim(mb_substr($hint, 0, 120));
    $matches = aic_search_existing(['product_name' => $hint, 'product_code' => '']);
    echo json_encode(['success' => true, 'matches' => $matches]);
    exit;
}

/* "Compare Versions" — the table/dashboard numbers are SQL + math only, no
   AI call. The short written summary IS one AI call, but it's cached per
   version pair (see aic_get_or_build_ai_summary) so re-opening the same
   comparison never re-bills unless force_refresh is set or a version's
   total_cost actually changed since it was cached. */
if ($action === 'compare_versions') {
    $idA = (int)($data['version_id_a'] ?? 0);
    $idB = (int)($data['version_id_b'] ?? 0);
    $forceRefresh = !empty($data['force_refresh']);
    if (!$idA || !$idB) { echo json_encode(['success' => false, 'message' => 'Pick two versions to compare.']); exit; }
    if ($idA === $idB) { echo json_encode(['success' => false, 'message' => 'Pick two different versions.']); exit; }
    $cmp = aic_compare_versions($idA, $idB);
    if (!$cmp) { echo json_encode(['success' => false, 'message' => 'Could not load one or both versions.']); exit; }

    $summary = ['text' => '', 'cached' => false];
    if (!empty($config['openai_enabled'])) {
        try {
            $summary = aic_get_or_build_ai_summary($cmp, $forceRefresh);
            if (!$summary['cached']) {
                $u = $summary['usage'] ?? null;
                aic_log('compare_ai_summary', $cmp['a']['costing_no'] . ' vs ' . $cmp['b']['costing_no'], $u['model'] ?? ($config['ai_costing_model'] ?? 'gpt-4o-mini'), $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0, $u['estimated_cost'] ?? 0, true);
            }
        } catch (Throwable $e) {
            $summary = ['text' => '', 'cached' => false];
        }
    }

    echo json_encode(['success' => true, 'comparison' => $cmp, 'ai_summary' => $summary['text'], 'ai_summary_cached' => $summary['cached']]);
    exit;
}

/* "Compare Versions" — multi (2 to 6 sizes/versions side by side in one
   table), same cost-control rules as the pairwise compare above: the table
   is pure SQL/math, the written summary is one cached AI call. */
if ($action === 'compare_multi') {
    $ids = is_array($data['version_ids'] ?? null) ? array_map('intval', $data['version_ids']) : [];
    $forceRefresh = !empty($data['force_refresh']);
    $ids = array_values(array_unique(array_filter($ids)));
    if (count($ids) < 2) { echo json_encode(['success' => false, 'message' => 'Pick at least 2 versions to compare.']); exit; }
    if (count($ids) > 6) { echo json_encode(['success' => false, 'message' => 'Compare up to 6 versions at a time.']); exit; }
    $cmp = aic_compare_multi($ids);
    if (!$cmp) { echo json_encode(['success' => false, 'message' => 'Could not load one or more of the selected versions.']); exit; }

    $summary = ['text' => '', 'cached' => false];
    if (!empty($config['openai_enabled'])) {
        try {
            $summary = aic_get_or_build_multi_ai_summary($cmp, $forceRefresh);
            if (!$summary['cached']) {
                $u = $summary['usage'] ?? null;
                aic_log('compare_multi_ai_summary', implode(',', $ids), $u['model'] ?? ($config['ai_costing_model'] ?? 'gpt-4o-mini'), $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0, $u['estimated_cost'] ?? 0, true);
            }
        } catch (Throwable $e) {
            $summary = ['text' => '', 'cached' => false];
        }
    }

    echo json_encode(['success' => true, 'comparison' => $cmp, 'ai_summary' => $summary['text'], 'ai_summary_cached' => $summary['cached']]);
    exit;
}

/* "Explain" — on-demand only (brief section 12), takes already-PHP-calculated
   data, never a raw DB dump. Separate optional AI call, never triggered
   automatically. */
if ($action === 'explain') {
    $context = is_array($data['context'] ?? null) ? $data['context'] : [];
    if (!$context) { echo json_encode(['success' => false, 'message' => 'Nothing to explain.']); exit; }
    try {
        $r = aic_explain($context);
        aic_log('explain', json_encode($context), $r['usage']['model'], $r['usage']['input_tokens'], $r['usage']['output_tokens'], $r['usage']['estimated_cost'], true);
        echo json_encode(['success' => true, 'text' => $r['text'], 'usage' => $r['usage']]);
    } catch (Throwable $e) {
        aic_log('explain', json_encode($context), $config['ai_costing_model'] ?? 'gpt-4o-mini', 0, 0, 0, false, $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Explanation unavailable right now.']);
    }
    exit;
}

/* "Estimate New Size" — no AI call at all, pure SQL + interpolation math
   (brief section 17). Only usable once a real product has been matched by a
   prior "generate" (the client passes back the product_id it was given). */
if ($action === 'estimate_size') {
    $productId = (int)($data['product_id'] ?? 0);
    $sizeName = trim((string)($data['size_name'] ?? ''));
    if (!$productId || $sizeName === '') { echo json_encode(['success' => false, 'message' => 'No matched product to estimate against yet — run Generate Draft first.']); exit; }
    $est = aic_estimate_new_size($productId, $sizeName);
    if (!$est) { echo json_encode(['success' => false, 'message' => 'Not enough approved reference sizes for this product to estimate "' . $sizeName . '".']); exit; }
    echo json_encode(['success' => true, 'estimate' => $est]);
    exit;
}

/* "Ask AI About This Costing" — free-form question, only fired when the user
   asks (brief section 25/26). Same rule as Explain: only already-calculated
   PHP data goes into the prompt, never a raw DB dump. */
if ($action === 'ask') {
    $question = trim((string)($data['question'] ?? ''));
    $context = is_array($data['context'] ?? null) ? $data['context'] : [];
    if ($question === '') { echo json_encode(['success' => false, 'message' => 'Type a question first.']); exit; }
    if (!$context) { echo json_encode(['success' => false, 'message' => 'Generate a draft first, then ask about it.']); exit; }
    try {
        $r = aic_ask($question, $context);
        aic_log('ask', $question, $r['usage']['model'], $r['usage']['input_tokens'], $r['usage']['output_tokens'], $r['usage']['estimated_cost'], true);
        echo json_encode(['success' => true, 'text' => $r['text'], 'usage' => $r['usage']]);
    } catch (Throwable $e) {
        aic_log('ask', $question, $config['ai_costing_model'] ?? 'gpt-4o-mini', 0, 0, 0, false, $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'AI is unavailable right now.']);
    }
    exit;
}

if ($action !== 'generate') { echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit; }

if ($text === '') { echo json_encode(['success' => false, 'message' => 'Please describe the costing first.']); exit; }
if (mb_strlen($text) > 6000) { echo json_encode(['success' => false, 'message' => 'That description is too long — please shorten it.']); exit; }
if (empty($config['openai_enabled'])) { echo json_encode(['success' => false, 'message' => 'AI is not enabled on this system — use the costing form manually.']); exit; }

try {
    $ext = aic_extract($text);
    $v = aic_validate($ext['data']);

    if (!$v['ok']) {
        aic_log('generate', $text, $ext['usage']['model'], $ext['usage']['input_tokens'], $ext['usage']['output_tokens'], $ext['usage']['estimated_cost'], false, implode('; ', $v['errors']));
        echo json_encode(['success' => false, 'message' => implode(' ', $v['errors']), 'usage' => $ext['usage']]);
        exit;
    }

    // Phase 1: exact/LIKE SQL match. Phase 3: embedding-based similar search
    // (reuses Product Master's own embedding infra) only when SQL found nothing.
    $matches = [];
    try { $matches = aic_search_existing($v['data']['product']); } catch (Throwable $e) {}
    $similar = [];
    if (!$matches) { try { $similar = aic_similar_products($v['data']['product']['product_name']); } catch (Throwable $e) {} }

    // A confident match (exact/LIKE, or a high-similarity embedding hit) gives us
    // a real product to check nearest-size / duplicates / comparison against.
    $matchedProductId = 0;
    if ($matches) $matchedProductId = (int)$matches[0]['id'];
    elseif ($similar && $similar[0]['similarity'] >= 0.80) $matchedProductId = (int)$similar[0]['id'];
    $existingSizes = $matchedProductId ? aic_product_sizes($matchedProductId) : [];

    $calculated = aic_calculate($v['data']);

    // Phase 2: nearest-size, duplicate-costing warning, side-by-side comparison.
    // Phase 3: missing-rate suggestions + rate-anomaly flags (both deterministic
    // PHP/SQL against your own approved costing history — no extra AI calls).
    $rateSuggestCache = []; $anomalyCache = [];
    foreach ($calculated as &$sz) {
        $sz['nearest_size'] = null; $sz['duplicate'] = null; $sz['comparison'] = null;
        if ($matchedProductId) {
            $nearest = aic_nearest_size($sz['size_name'], $existingSizes);
            $sz['nearest_size'] = ($nearest && mb_strtolower($nearest) !== mb_strtolower($sz['size_name'])) ? $nearest : null;
            $sz['duplicate'] = aic_duplicate_check($matchedProductId, $sz['size_name']);
            $sz['comparison'] = aic_get_approved_costing($matchedProductId, $nearest ?: $sz['size_name']);
        }
        foreach ($sz['lines'] as &$l) {
            if ((float)$l['rate'] <= 0) {
                $sug = aic_suggest_rate($l['item'], $rateSuggestCache);
                if ($sug) $l['suggested_rate'] = $sug;
            } else {
                $an = aic_check_anomaly($l['item'], (float)$l['rate'], $anomalyCache);
                if ($an) $l['anomaly'] = $an;
            }
        }
        unset($l);
    }
    unset($sz);

    // Diff-vs-previous-size, suggested selling price, and a per-size confidence
    // band — all pure math over totals already calculated above, no new AI call.
    $marginPct = (float)($config['ai_costing_default_margin_pct'] ?? 18);
    $marginType = $config['ai_costing_default_margin_type'] ?? 'gross_margin';
    $calculated = aic_add_diffs_and_pricing($calculated, $marginPct, $marginType);

    // Item standardization (brief section 23) — one suggestion per unique item
    // name across the common lines, never auto-applied.
    $standardize = [];
    $stdCache = [];
    foreach ($v['data']['common_lines'] as $cl) {
        $sug = aic_standardize_item($cl['item'], $stdCache);
        if ($sug) $standardize[] = ['original' => $cl['item'], 'suggested' => $sug];
    }

    // Missing-cost checker (brief section 22) — only meaningful once we have a
    // matched product with real approved history to compare against.
    $missingCostSuggestions = $matchedProductId ? aic_missing_cost_check($matchedProductId, $v['data']['common_lines']) : [];

    // Unusual size progression (brief section 24) — deterministic check, no AI.
    $progressionWarnings = aic_check_size_progression($calculated);
    $allWarnings = array_merge($v['data']['warnings'], $progressionWarnings);

    aic_log('generate', $text, $ext['usage']['model'], $ext['usage']['input_tokens'], $ext['usage']['output_tokens'], $ext['usage']['estimated_cost'], true, '', count($v['data']['sizes']));

    echo json_encode([
        'success' => true,
        'product' => $v['data']['product'],
        'sizes' => $calculated,
        'missing_fields' => $v['data']['missing_fields'],
        'warnings' => $allWarnings,
        'existing_matches' => $matches,
        'similar_products' => $similar,
        'matched_product_id' => $matchedProductId,
        'standardize_suggestions' => $standardize,
        'missing_cost_suggestions' => $missingCostSuggestions,
        'margin_pct' => $marginPct,
        'margin_type' => $marginType,
        'usage' => $ext['usage'],
    ]);
} catch (Throwable $e) {
    aic_log('generate', $text, $config['ai_costing_model'] ?? 'gpt-4o-mini', 0, 0, 0, false, $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'AI is unavailable right now (' . $e->getMessage() . '). You can still use the costing form manually.']);
}
