--TEST--
AOT hash()/hash_hmac() raw_output boolean argument (#3747)
--FILE--
<?php
echo hash('sha256', 'a', false) === hash('sha256', 'a') ? '1' : '0', "\n";
echo hash_hmac('sha256', 'a', 'key', false) === hash_hmac('sha256', 'a', 'key') ? '1' : '0', "\n";
echo bin2hex(hash('sha256', 'abc', true)), "\n";
--EXPECT--
1
1
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
