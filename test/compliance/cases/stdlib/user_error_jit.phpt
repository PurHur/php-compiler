--TEST--
stdlib user_error() JIT — E_USER_NOTICE helper (#6183)
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
