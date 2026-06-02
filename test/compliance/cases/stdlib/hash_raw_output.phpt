--TEST--
stdlib hash()/hash_hmac() raw_output boolean argument (#3747)
--FILE--
<?php
var_dump(hash('sha256', 'a', false) === hash('sha256', 'a'));
var_dump(hash_hmac('sha256', 'a', 'key', false) === hash_hmac('sha256', 'a', 'key'));
echo bin2hex(hash('sha256', 'abc', true)), "\n";
echo bin2hex(hash_hmac('sha256', 'abc', 'key', true)), "\n";
--EXPECT--
bool(true)
bool(true)
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
9c196e32dc0175f86f4b1cb89289d6619de6bee699e4c378e68309ed97a1a6ab
