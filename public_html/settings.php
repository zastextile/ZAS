<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

/* TEST THE OPENAI KEY — asked for after a key was revoked and replaced:
   "how to verify cross check if it work new correct".
   The AI screens hide OpenAI errors (a bad key looks like "no results"), so
   this asks OpenAI directly: GET /v1/models, which is free and spends no
   tokens. It reports whether the key is accepted and whether every model the
   app is set to use is open to it. The key is never printed — only its last
   four characters, so it can be matched against the one on the OpenAI site. */
$aiTest = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'test_openai') {
    verify_csrf();
    $key = trim((string)($config['openai_api_key'] ?? ''));
    $cfgFile = __DIR__ . '/config/config.php';
    $aiTest = [
        'enabled' => !empty($config['openai_enabled']),
        'has_key' => $key !== '' && $key !== 'PUT_OPENAI_API_KEY_HERE',
        'tail'    => $key !== '' ? substr($key, -4) : '',
        'spaces'  => $key !== (string)($config['openai_api_key'] ?? ''),
        'file_at' => is_file($cfgFile) ? date('d M Y, H:i', (int)filemtime($cfgFile)) : '',
        'code' => 0, 'error' => '', 'models' => [],
    ];
    if ($aiTest['has_key']) {
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $raw = curl_exec($ch);
        $aiTest['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) $aiTest['error'] = 'Could not reach OpenAI: ' . curl_error($ch);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($aiTest['error'] === '' && $aiTest['code'] >= 400) $aiTest['error'] = (string)($data['error']['message'] ?? ('HTTP ' . $aiTest['code']));
        $have = [];
        foreach ((array)($data['data'] ?? []) as $m) $have[(string)($m['id'] ?? '')] = true;
        /* every model setting the app reads, with the default it falls back to */
        foreach ([
            'AI Search answers'      => ['ai_answer_model', 'gpt-5-mini'],
            'AI Search index'        => ['embedding_model', 'text-embedding-3-small'],
            'Product Master search'  => ['pm_embedding_model', 'text-embedding-3-large'],
            'AI costing'             => ['ai_costing_model', 'gpt-4o-mini'],
            'AI Check'               => ['ai_check_model', 'gpt-4o-mini'],
            'AI Check (deeper)'      => ['ai_check_model_hi', 'gpt-4o'],
        ] as $label => [$k, $def]) {
            $id = (string)($config[$k] ?? $def);
            $aiTest['models'][] = ['label' => $label, 'id' => $id, 'ok' => $have ? isset($have[$id]) : null];
        }
    }
}

$fx = fx_get_rates();
$fxSynced = fx_last_synced_at();
$cardCss = 'padding:20px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px)';

page_header('Settings');
flash();
?>
<div class="topbar"><div><h1>Settings</h1><p class="lead">App-wide configuration — admin only.</p></div></div>

<div style="<?= $cardCss ?>;max-width:520px">
  <h2 style="font-size:15px;margin:0 0 4px">Currency / FX Rates</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 18px">Used to fairly compare and convert amounts across currencies on the Dashboard, Costing Print, and AI Check. Enter today's rate for each currency below and save — no external service is called, you're fully in control of these numbers.</p>

  <form method="post" action="fx_refresh.php">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="settings.php">
    <?php foreach ($fx as $cur => $rate): if ($cur === 'PKR') continue; $pkrPerUnit = $rate > 0 ? 1 / $rate : 0; ?>
    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12.5px;color:#33415c;font-weight:600;margin-bottom:6px">1 <?= e($cur) ?> =</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" step="0.01" min="0.01" name="rate_<?= strtolower(e($cur)) ?>" value="<?= number_format($pkrPerUnit, 2, '.', '') ?>" style="width:150px;padding:9px 12px;border-radius:9px;border:1px solid #cbd5e3;font-family:'Space Grotesk',system-ui,sans-serif;font-size:14px">
        <span style="color:#5a6b82;font-size:13px">PKR</span>
      </div>
    </div>
    <?php endforeach; ?>

    <p style="color:#8a97ab;font-size:12px;margin:0 0 16px">
      <?= $fxSynced ? 'Last updated ' . e(date('d M Y, H:i', strtotime($fxSynced))) : 'Not yet set — showing config.php defaults above.' ?>
    </p>

    <button type="submit" style="padding:10px 18px;border-radius:10px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033;font-weight:600;font-size:13px;cursor:pointer">Save Rates</button>
  </form>
</div>

<div style="<?= $cardCss ?>;max-width:520px;margin-top:16px" id="openai">
  <h2 style="font-size:15px;margin:0 0 4px">OpenAI key</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Asks OpenAI whether the key in <b>config/config.php</b> works, and whether the models the app uses are open to it. Free — no tokens are spent. The key itself is never shown.</p>
  <form method="post" action="settings.php#openai">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_openai">
    <button type="submit" style="padding:10px 18px;border-radius:10px;border:1px solid #1d76e2;background:#1d76e2;color:#fff;font-weight:600;font-size:13px;cursor:pointer">Test OpenAI key</button>
  </form>
  <?php if ($aiTest !== null):
    $okKey = $aiTest['has_key'] && $aiTest['error'] === '' && $aiTest['code'] === 200;
    $row = function (bool $ok, string $what, string $note = '') {
        return '<div style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13px"><b style="color:' . ($ok ? '#15803d' : '#b3261e') . ';width:18px;flex:0 0 18px">' . ($ok ? '✓' : '✗') . '</b><span>' . $what
             . ($note !== '' ? '<br><span style="color:#8a97ab;font-size:12px">' . $note . '</span>' : '') . '</span></div>';
    }; ?>
    <div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:<?= $okKey ? '#effaf3' : '#fdeef1' ?>;border:1px solid <?= $okKey ? '#c9ecd7' : '#f6cdd5' ?>">
      <div style="font-weight:800;font-size:14px;margin-bottom:6px;color:<?= $okKey ? '#1c6b40' : '#9c2740' ?>">
        <?= $okKey ? 'The key works.' : 'The key does not work yet.' ?></div>
      <?= $row($aiTest['enabled'], 'AI switched on in config (openai_enabled)', $aiTest['enabled'] ? '' : "Set 'openai_enabled' => true in config/config.php.") ?>
      <?= $row($aiTest['has_key'], $aiTest['has_key'] ? 'Key found, ending in <b>…' . e($aiTest['tail']) . '</b>' : 'No key in config/config.php',
               $aiTest['has_key'] ? 'Compare these last 4 characters with your new key on platform.openai.com → API keys.' : "Put it in 'openai_api_key'. Note: public_html/config.php is NOT read — only config/config.php.") ?>
      <?php if ($aiTest['spaces']): ?><?= $row(false, 'The key has spaces or a line break around it', 'Remove them in config/config.php. They are ignored for this test, but the AI screens send the key as it is.') ?><?php endif; ?>
      <?php if ($aiTest['file_at'] !== ''): ?><div style="font-size:12px;color:#8a97ab;margin:2px 0 6px 26px">config/config.php last changed on the server: <?= e($aiTest['file_at']) ?></div><?php endif; ?>
      <?php if ($aiTest['has_key']): ?>
        <?= $row($okKey, $okKey ? 'OpenAI accepted the key' : 'OpenAI refused the key', $okKey ? '' : e($aiTest['error'])) ?>
        <?php if ($okKey): foreach ($aiTest['models'] as $m): ?>
          <?= $row((bool)$m['ok'], e($m['label']) . ': <code>' . e($m['id']) . '</code>', $m['ok'] ? '' : 'This key cannot use that model. Allow it on the key or project at OpenAI, or set another model in config.') ?>
        <?php endforeach; endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div style="<?= $cardCss ?>;max-width:520px;margin-top:16px">
  <h2 style="font-size:15px;margin:0 0 4px">Export Masters</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 16px">
    The lists the shipment screens pick from — loading ports, shipping lines, container types,
    payment methods, cost types, document types, your banks, and where documents are stored.
    Nothing in those lists is hard-coded in the application.
  </p>
  <a href="exp_settings.php" style="display:inline-block;padding:10px 18px;border-radius:10px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033;font-weight:600;font-size:13px;text-decoration:none">Open Export Masters</a>
</div>

<?php page_footer(); ?>
