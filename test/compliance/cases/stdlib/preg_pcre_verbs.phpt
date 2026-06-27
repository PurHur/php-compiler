--TEST--
Stdlib: preg_match() PCRE pattern verbs on empty subject (#12434, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
echo preg_match('/(*UTF)/', '') . ' ' . preg_last_error() . "\n";
echo preg_match('/(*CRLF)/', '') . ' ' . preg_last_error() . "\n";
echo preg_match('/(*ANY)/', '') . ' ' . preg_last_error() . "\n";
@preg_match('/(*INVALID)/', '');
echo preg_last_error() . "\n";
?>
--EXPECT--
1 0
1 0
1 0
1
