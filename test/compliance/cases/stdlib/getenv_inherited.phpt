--TEST--
stdlib getenv() inherits process environment (PATH) — #11744
--FILE--
<?php
$path = getenv('PATH');
echo ($path !== false && is_string($path) && '' !== $path) ? "path_ok\n" : "path_fail\n";
$all = getenv();
echo is_array($all) && array_key_exists('PATH', $all) ? "all_has_path\n" : "all_missing_path\n";
putenv('PHP_COMPILER_GETENV_TEST=1');
echo getenv('PHP_COMPILER_GETENV_TEST') === '1' ? "putenv_ok\n" : "putenv_fail\n";
--EXPECT--
path_ok
all_has_path
putenv_ok
