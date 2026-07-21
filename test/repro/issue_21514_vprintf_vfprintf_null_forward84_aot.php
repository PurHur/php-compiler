<?php
/**
 * AOT repro #21514 — vfprintf/fprintf null format soft-null on PROFILE=8.4.
 * AotTest defaults REQUEST_METHOD=GET which aborts soft-null fprintf teardown;
 * fixture sets REQUEST_METHOD= (CLI) like file_get_contents.phpt.
 *
 * PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/i21514 \
 *   test/repro/issue_21514_vprintf_vfprintf_null_forward84_aot.php && /tmp/i21514
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$fmt = null;
$fp = fopen('php://memory', 'w+');
vfprintf($fp, $fmt, []);
fprintf($fp, $fmt);
fclose($fp);
echo "OK\n";
