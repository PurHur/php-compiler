--TEST--
stdlib mb_regex_set_options(null) — no Deprecated, returns current options (#30070, ext/mbstring/php_mbregex.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$prev = mb_regex_set_options(null);
echo gettype($prev), "\n";
echo "no deprecated\n";
?>
--EXPECT--
string
no deprecated
