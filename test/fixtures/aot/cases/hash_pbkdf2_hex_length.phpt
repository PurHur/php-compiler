--TEST--
AOT: hash_pbkdf2() hex $length truncates to Zend width (#27241)
--FILE--
<?php
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 8), "\n";
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 16), "\n";
echo hash_pbkdf2('sha256', 'pass', 'salt', 1000, 7), "\n";
echo bin2hex(hash_pbkdf2('sha256', 'pass', 'salt', 1000, 8, true)), "\n";
--EXPECT--
ce7834ce
ce7834ce3ad3ce55
ce7834c
ce7834ce3ad3ce55
