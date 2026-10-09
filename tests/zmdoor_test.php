<?php
/* THE MOBILE DOOR — one link, the same login, only what that login opens.
 *
 * "can open by user simple using login password but can see just the
 *  mobile page whatever allowed access to him instead using whole app
 *  with sidebar full menu"
 *
 * m.php is a phone user's whole application. They sign in with the
 * account they already have and land on a page holding the phone screens
 * their permissions open — no sidebar, no desktop menu, no dashboard.
 *
 * THE DANGEROUS PART IS THE REDIRECT. For the bookmark to work, login
 * has to be told where the user was going, and a redirect target that
 * arrives on the query string is how open redirects are made. Most of
 * this file is that one function being handed things it must refuse.
 *
 * What it holds shut:
 *   A TILE IS NOT A PERMISSION. Hiding one is convenience; every screen
 *   behind it still refuses a typed URL on its own.
 *   LOGIN CANNOT BE AIMED OFF THIS SITE. Not by host, scheme, slash,
 *   backslash, encoded dot-dot or anything else tried below.
 *   ONLY A PHONE SCREEN IS REMEMBERED. A desktop page still lands on the
 *   dashboard, exactly as it always did.
 *   A CUSTOMER NEVER FOLLOWS ONE. That portal is separate.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $s);
}
/* Pull one function's source out of a file that cannot simply be
   required, because requiring it would run a login page. */
function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $o = strpos($src, '{', $a); if ($o === false) return '';
    $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}

$login  = (string)file_get_contents($B . 'login.php');
$loginN = nocomments($login);
$auth   = (string)file_get_contents($B . 'includes/auth.php');
$authN  = nocomments($auth);
$mob    = (string)file_get_contents($B . 'includes/mobile.php');
$mobN   = nocomments($mob);
$door   = (string)file_get_contents($B . 'm.php');
$doorN  = nocomments($door);

/* ============================================ 1. the door exists and is shut */
head('1. The door is the same login, not a second one');

t('m.php exists',                      $door !== '');
t('it requires a login like every other page', str_contains($doorN, 'require_login()'));
t('it has no password form of its own',
  !preg_match('~type="password"~', $doorN), 'a second password box');
t('it reads no credentials',
  !preg_match('~password_verify|password_hash|SELECT \* FROM users~i', $doorN));
t('it never writes a session identity',
  !preg_match('~\$_SESSION\[\x27user_id\x27\]\s*=~', $doorN), 'the door sets who you are');
t('it grants nothing — no permission column is written',
  !preg_match('~UPDATE users|INSERT INTO users~i', $doorN));

head('2. No desktop furniture on it');

t('it does not pull in the desktop layout',
  !str_contains($doorN, "includes/layout.php"), 'the sidebar layout is required');
t('nor the desktop menu',
  !str_contains($doorN, "includes/menu.php"));
t('it uses the phone shell instead',
  str_contains($doorN, "includes/mobile.php") && str_contains($doorN, 'mob_header('));
t('and its own installable manifest',
  str_contains($doorN, "'manifest_m.json'") && is_file($B . 'manifest_m.json'));
$mf = json_decode((string)file_get_contents($B . 'manifest_m.json'), true);
t('the manifest starts at the door, not at a single screen',
  ($mf['start_url'] ?? '') === 'm.php', $mf['start_url'] ?? null);
t('and offers the screens as shortcuts',
  count($mf['shortcuts'] ?? []) === 3, $mf['shortcuts'] ?? null);

head('3. A tile is a convenience, not a permission');

t('the screen list lives in one place',
  str_contains($mobN, 'function mob_screens'));
t('the door only reads that list, it does not build its own',
  str_contains($doorN, 'mob_screens()') && !str_contains($doorN, "'href' => 'm_gate"),
  'the door hard-codes its own tiles');
t('the gate screen still checks the gate permission for itself',
  str_contains(nocomments((string)file_get_contents($B . 'm_gate.php')), "inv_perm('gate')"));
t('the packing screen still checks for itself',
  str_contains(nocomments((string)file_get_contents($B . 'm_pack.php')), 'is_production_staff()'));
t('the door requires inventory.php, which is what defines inv_perm',
  str_contains($doorN, "includes/inventory.php"),
  'without it the gate tiles vanish and nothing says why');

/* ===================================== 4. the redirect target, in detail */
head('4. Login cannot be aimed anywhere but a phone screen here');

$fn = lift($login, 'function login_next');
t('login_next is defined in login.php', $fn !== '');
if ($fn !== '' && !function_exists('login_next')) eval($fn);

$accept = [
    'm.php',
    'm_gate.php',
    'm_gate.php?dir=in',
    'm_gate.php?dir=out&new=1',
    'm_pack.php',
    'm_pack.php?id=1&t=weight&g=2&s=152x200',
    'm_pack.php?id=1&t=mix&g=101',
    'm_pack.php?id=1&s=Small%20size',
];
foreach ($accept as $a) {
    t("accepts $a", login_next($a) === $a, login_next($a));
}

/* Each of these would be a real fault if it came back unchanged. */
$reject = [
    'http://evil.example/x'      => 'an absolute http url',
    'https://evil.example'       => 'an absolute https url',
    '//evil.example/m.php'       => 'a protocol-relative host',
    '///evil.example'            => 'three slashes',
    '\\\\evil.example'           => 'a windows unc path',
    'javascript:alert(1)'        => 'a javascript url',
    'data:text/html,<b>x'        => 'a data url',
    '/dashboard.php'             => 'an absolute path on this site',
    'dashboard.php'              => 'a desktop page',
    'users.php'                  => 'the user admin screen',
    '../m.php'                   => 'a dot-dot',
    '..%2Fm.php'                 => 'an encoded dot-dot',
    'm.php/../users.php'         => 'a traversal after a good start',
    'm.php#x'                    => 'a fragment',
    'M_GATE.PHP'                 => 'an upper-case spelling',
    'm_gate.PHP'                 => 'a mixed-case extension',
    'm_gate.php ?dir=in'         => 'a space before the query',
    "m_gate.php\n?dir=in"        => 'a newline, which would split a header',
    "m_gate.php\r\nLocation: x"  => 'a crlf header injection',
    'm_gate.php?x=<script>'      => 'a tag in the query',
    'm_gate.php?x="y'            => 'a quote in the query',
    'mgate.php'                  => 'a page that is not one of ours',
    'malicious.php'              => 'a page that merely starts with m',
    'm-gate.php'                 => 'a hyphen, which the pattern does not allow',
    'm_gate.phpx'                => 'a longer extension',
    ''                           => 'nothing at all',
    'm.php?' . str_repeat('a', 300) => 'an absurdly long target',
];
foreach ($reject as $bad => $what) {
    t("refuses $what", login_next($bad) === '', [$bad, login_next($bad)]);
}

head('5. And the refusal is a drop, not a repair');

t('nothing is rewritten, stripped or escaped into a usable value',
  !preg_match('~str_replace|ltrim|rtrim|preg_replace|urldecode~', nocomments($fn)),
  'login_next tries to fix a bad target instead of dropping it');
t('a length cap comes before the pattern',
  preg_match('~strlen\(\$raw\) > \d+~', nocomments($fn)) === 1);

/* ======================================== 6. where login actually sends */
head('6. Login obeys it, and only it');

t('the target is read from both the link and the form',
  preg_match('~login_next\(\(string\)\(\$_GET\[\x27next\x27\] \?\? \$_POST\[\x27next\x27\]~', $loginN) === 1);
t('it is carried through the POST as a hidden field',
  str_contains($loginN, 'name="next"'));
t('and escaped on the way into the page',
  preg_match('~name="next" value="<\?= e\(\$next\) \?>"~', $loginN) === 1,
  'the target is printed raw');
t('a successful login goes to the target when there is one',
  preg_match('~redirect\(\$next !== \x27\x27 \? \$next : \x27dashboard\.php\x27\);~', $loginN) === 1);
t('and to the dashboard when there is not',
  substr_count($loginN, "\$next : 'dashboard.php'") === 2,
  substr_count($loginN, "\$next : 'dashboard.php'"));
/* TWO places send a customer away: the already-signed-in check at the
   top of the file, and the sign-in itself. preg_match finds either one
   and reports success, so losing one would pass unnoticed — count them. */
t('a customer is sent to their own portal, in both places',
  preg_match_all('~=== \x27customer\x27\) redirect\(\x27customer_dashboard\.php\x27\);~', $loginN) === 2,
  preg_match_all('~=== \x27customer\x27\) redirect\(\x27customer_dashboard\.php\x27\);~', $loginN));
t('a customer redirect never carries the target',
  !preg_match('~customer_dashboard\.php\x27 : \$next~', $loginN));
t('the login page offers the mobile door',
  str_contains($loginN, 'href="m.php"'));

head('7. Only a phone screen is remembered on the way out');

t('require_login builds the target from the script it is on',
  str_contains($authN, "SCRIPT_NAME") && str_contains($authN, "\$q['next']"));
/* A plain substring, not a regex about a regex — the delimiter
   fight is not worth it and the literal is what matters. */
t('and only when that script is an m page',
  str_contains($authN, '^m(?:_[a-z0-9_]+)?\\.php$~'),
  'the m-page test is missing from require_login');
t('and only for a GET, because a POST cannot be replayed',
  str_contains($authN, "REQUEST_METHOD'] ?? 'GET') === 'GET'"));
t('the store-down message still gets through',
  str_contains($authN, "\$q['store'] = 'down'"));
t('the query is built, not pasted together by hand',
  str_contains($authN, 'http_build_query($q)'),
  'the next value is concatenated without encoding');

/* Prove the two patterns agree: everything require_login would produce,
   login_next must accept. A drift between them is a broken bookmark. */
head('8. The two halves agree');

$produced = [];
foreach ([['m.php', ''], ['m_gate.php', 'dir=in'], ['m_pack.php', 'id=1&t=weight&g=2'],
          ['m_gate.php', 'dir=out&new=1'], ['m_pack.php', '']] as [$script, $qs]) {
    $produced[] = $script . ($qs !== '' ? '?' . $qs : '');
}
foreach ($produced as $p) {
    t("what require_login would send back is accepted: $p", login_next($p) === $p, login_next($p));
}

/* ================================================= 9. the door, rendered */
head('9. The door drawn for three different accounts');

$work = __DIR__ . '/.zmdoor';
@mkdir($work . '/includes', 0777, true);
foreach (glob($work . '/*.html') ?: [] as $old) @unlink($old);
copy($B . 'm.php',               $work . '/m.php');
copy($B . 'includes/mobile.php', $work . '/includes/mobile.php');

file_put_contents($work . '/includes/bootstrap.php', <<<'PHP'
<?php
session_start();
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function current_user(): ?array { return ['id'=>1,'name'=>'Rafiq','email'=>'r@z','role'=>getenv('ROLE') ?: 'staff']; }
function is_production_staff(){ return (getenv('ROLE') ?: '') === 'production_staff'; }
function redirect($u){ echo "__REDIRECT__ $u"; exit; }
PHP);
/* inv_perm comes from inventory.php in the real app; here it is the one
   thing the scenario varies. */
file_put_contents($work . '/includes/inventory.php',
    "<?php\nfunction inv_perm(string \$w): bool { return getenv('GATE') === '1'; }\n");

$render = function (array $env) use ($work): string {
    $pre = '';
    foreach ($env as $k => $v) $pre .= $k . '=' . escapeshellarg($v) . ' ';
    return (string)shell_exec('cd ' . escapeshellarg($work) . ' && ' . $pre
        . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL'
        . ' -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]="GET";require "m.php";') . ' 2>&1');
};

$gateman = $render(['ROLE' => 'staff', 'GATE' => '1']);
$packer  = $render(['ROLE' => 'staff', 'GATE' => '0']);
$prod    = $render(['ROLE' => 'production_staff', 'GATE' => '0']);
file_put_contents($work . '/gateman.html', $gateman);
file_put_contents($work . '/packer.html',  $packer);
file_put_contents($work . '/prod.html',    $prod);

foreach (['gateman' => $gateman, 'packer' => $packer, 'prod' => $prod] as $who => $h) {
    t("$who: renders with no PHP complaint",
      !preg_match('~(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)~i', $h),
      preg_match('~^.*(Fatal error|Warning:|Notice:|Uncaught).*$~mi', $h, $m) ? $m[0] : null);
    t("$who: reaches the end of the page", str_contains($h, '</html>'));
    t("$who: has no sidebar or desktop nav", !preg_match('~<nav|sidebar~i', $h));
    t("$who: can sign out", str_contains($h, 'logout.php'));
}

t('a gatekeeper sees Gate In and Gate Out',
  str_contains($gateman, 'Gate In') && str_contains($gateman, 'Gate Out'));
t('and Packing, which needs no gate permission',
  str_contains($gateman, 'Packing'));
/* class="tiles" is the wrapper, not a tile — match the space. */
t('three tiles, no more', substr_count($gateman, 'class="tile ') === 3,
  substr_count($gateman, 'class="tile '));

t('without the gate permission the gate tiles are gone',
  !str_contains($packer, 'Gate In') && !str_contains($packer, 'Gate Out'));
t('but packing is still there',
  str_contains($packer, 'Packing') && substr_count($packer, 'class="tile ') === 1,
  substr_count($packer, 'class="tile '));

t('production staff get no tile at all',
  substr_count($prod, 'class="tile ') === 0, substr_count($prod, 'class="tile '));
t('and are told so plainly rather than shown an empty page',
  str_contains($prod, 'no phone screen open to your account'));

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
