<?php
/**
 * #23348 — AOT: rename(from:, to:) Zend stub names (compile-time named dispatch)
 * php-src: ext/standard/file.stub.php
 *
 * Do not assert is_file($to) after a successful AOT rename — thin standalone
 * is_file still reports nonew (#29090 / #29141). Named dispatch itself is
 * rename() returning true.
 */
$a = sys_get_temp_dir() . '/phpc-ren-23348-aot-a-' . getmypid();
$b = sys_get_temp_dir() . '/phpc-ren-23348-aot-b-' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
echo rename(from: $a, to: $b) ? "true\n" : "false\n";
@unlink($a);
@unlink($b);
