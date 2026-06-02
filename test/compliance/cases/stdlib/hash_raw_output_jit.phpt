--TEST--
stdlib hash()/hash_hmac() raw_output JIT path (#3747)
--FILE--
<?php
var_dump(hash('md5', 'a', false) === hash('md5', 'a'));
var_dump(hash_hmac('md5', 'a', 'key', false) === hash_hmac('md5', 'a', 'key'));
echo bin2hex(hash('md5', 'abc', true)), "\n";
--EXPECT--
bool(true)
bool(true)
900150983cd24fb0d6963f7d28e17f72
