--TEST--
stdlib user_error() — E_USER_NOTICE helper (PHP 8.4, #6183, ext/standard/basic_functions.c)
--FILE--
<?php
echo user_error('test notice') ? '1' : '0';
echo "\n";
echo user_error('again') ? '1' : '0';
echo "\nok\n";
--EXPECT--
PHP Notice:  test notice
PHP Notice:  again
1
1
ok
