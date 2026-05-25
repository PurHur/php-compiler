--TEST--
stdlib hash_equals() timing-safe compare
--FILE--
<?php
$token = hash_hmac('sha256', 'body', 'key');
echo hash_equals($token, $token) ? '1' : '0', "\n";
echo hash_equals($token, 'wrong') ? '1' : '0', "\n";
echo hash_equals('abc', 'abcd') ? '1' : '0', "\n";
echo hash_equals('', '') ? '1' : '0', "\n";
--EXPECT--
1
0
0
1
