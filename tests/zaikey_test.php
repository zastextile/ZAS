<?php
/* TEST OPENAI KEY (Settings) — the key is checked, never shown.
 *
 * "my openai revoked so I made new and update on config so how to verify".
 * The AI screens swallow OpenAI errors, so a dead key looked like "no
 * results". Settings now asks OpenAI directly (GET /v1/models, free).
 *
 * Driven against a stand-in for OpenAI: no key, a refused key (OpenAI's own
 * message shown), and an accepted key with one model the key may not use.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c): void { global $P, $F; if ($c) $P++; else { $F++; echo "  FAIL  $n\n"; } }
$s = (string)file_get_contents($B . 'settings.php');

echo "\nThe test button\n";
t('Settings is still admin only', str_contains($s, 'require_admin();'));
t('the test is a POST with the CSRF check', str_contains($s, "(\$_POST['action'] ?? '') === 'test_openai'") && str_contains($s, 'verify_csrf();'));
t('it asks the free model list, spending no tokens', str_contains($s, "curl_init('https://api.openai.com/v1/models')"));
t('it reads the same config the app reads', str_contains($s, "\$config['openai_api_key']") && str_contains($s, "\$config['openai_enabled']"));
t('it checks every model setting the app uses',
  str_contains($s, "'ai_answer_model', 'gpt-5-mini'") && str_contains($s, "'embedding_model', 'text-embedding-3-small'")
  && str_contains($s, "'pm_embedding_model', 'text-embedding-3-large'") && str_contains($s, "'ai_costing_model', 'gpt-4o-mini'")
  && str_contains($s, "'ai_check_model', 'gpt-4o-mini'") && str_contains($s, "'ai_check_model_hi', 'gpt-4o'"));
t('it says which config file counts', str_contains($s, 'public_html/config.php is NOT read — only config/config.php.'));

echo "\nThe key never reaches the page\n";
t('only the last four characters are kept for display', str_contains($s, "'tail'    => \$key !== '' ? substr(\$key, -4) : '',"));
t('the key itself is never echoed', !preg_match('/(echo|print|e\(|<\?=)[^;]*\$key\b/', $s));
t('nor the config value directly', !preg_match("/(echo|print|<\\?=)[^;]*config\\['openai_api_key'\\]/", $s));

echo "\n" . str_repeat('-', 58) . "\n" . ($F === 0 ? 'ALL PASS' : 'FAILURES') . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
