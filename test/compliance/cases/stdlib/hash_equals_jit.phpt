--TEST--
stdlib hash_equals() JIT path
--FILE--
<?php
$token = hash_hmac('sha256', 'body', 'key');
echo hash_equals($token, $token) ? '1' : '0', "\n";
echo hash_equals($token, '515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae0') ? '1' : '0', "\n";
--EXPECT--
1
0
