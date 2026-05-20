--TEST--
stdlib hash_hmac() JIT path
--FILE--
<?php
echo hash_hmac('sha256', 'body', 'key'), "\n";
echo hash('sha256', 'body'), "\n";
--EXPECT--
515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae1
230d8358dc8e8890b4c58deeb62912ee2f20357ae92a5cc861b98e68fe31acb5
