--TEST--
stdlib hash_hkdf() JIT (issue #5025)
--FILE--
<?php
echo strlen(hash_hkdf('sha256', 'key', 16, 'info', 'salt')), "\n";
echo bin2hex(hash_hkdf('sha256', 'key', 16, 'info', 'salt')), "\n";
echo bin2hex(hash_hkdf('sha256', 'key', 32)), "\n";
--EXPECT--
16
9ca0d662557439e3b83365f2da4626d3
80c724c4a65d79568fc3606753c192adcc24bc263286767d4410269d8093c372
