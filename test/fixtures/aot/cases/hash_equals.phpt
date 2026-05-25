--TEST--
AOT hash_equals() CSRF token compare (issue #2179)
--FILE--
<?php
$token = hash_hmac('sha256', 'body', 'key');
echo hash_equals($token, $token) ? '1' : '0', "\n";
echo hash_equals($token, 'wrong') ? '1' : '0', "\n";
--EXPECT--
1
0
