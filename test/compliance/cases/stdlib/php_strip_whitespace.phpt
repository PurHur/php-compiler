--TEST--
stdlib php_strip_whitespace() — strip comments and whitespace (#3262)
--FILE--
<?php
$code = "<?php // comment\n echo 1;";
$path = sys_get_temp_dir() . '/phpc-strip-' . getmypid() . '.php';
file_put_contents($path, $code);
echo php_strip_whitespace($path), "\n";
echo php_strip_whitespace('/no/such/file.php') === '' ? "empty\n" : "not-empty\n";
@unlink($path);
--EXPECT--
<?php  echo 1;
empty
