<?php
$B = __DIR__ . '/app_src/public_html/';
$port = 8743;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/tmp/zprobe-php.log', 'a']], $pipes);
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
echo shell_exec('cd ' . escapeshellarg(__DIR__ . '/.zprobe') . ' && node probe.js http://127.0.0.1:' . $port . ' ' . escapeshellarg(getenv('SHOTS') ?: '') . ' 2>&1');
proc_terminate($srv, 9);
