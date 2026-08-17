<?php
/**
 * #23347 — AOT: copy(from:, to:) Zend stub names (compile-time named dispatch)
 * php-src: ext/standard/file.stub.php
 */
$a = sys_get_temp_dir() . '/phpc-copy-23347-aot-a-' . getmypid();
$b = sys_get_temp_dir() . '/phpc-copy-23347-aot-b-' . getmypid();
file_put_contents($a, 'x');
@unlink($b);
echo copy(from: $a, to: $b) ? "true\n" : "false\n";
echo is_file($b) ? file_get_contents($b) : 'missing', "\n";
@unlink($a);
@unlink($b);
