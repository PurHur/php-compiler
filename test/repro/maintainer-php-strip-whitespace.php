<?php
echo 'php_strip_whitespace: ', function_exists('php_strip_whitespace') ? 'yes' : 'NO', "\n";

$code = "<?php // comment\n echo 1;";
$path = sys_get_temp_dir() . '/phpc-strip-test.php';
file_put_contents($path, $code);
echo php_strip_whitespace($path), "\n";
