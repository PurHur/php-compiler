--TEST--
Stdlib: preg_match() inline (?modifiers) on empty subject (#12432, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
echo preg_match('/(?i)/', '') . ' ' . preg_last_error() . "\n";
echo preg_match('/(?J)/', '') . ' ' . preg_last_error() . "\n";
echo preg_match('/(?-i)(?i)/', '') . ' ' . preg_last_error() . "\n";
echo preg_match('/(?i)ABC/', 'abc') . "\n";
?>
--EXPECT--
1 0
1 0
1 0
1
