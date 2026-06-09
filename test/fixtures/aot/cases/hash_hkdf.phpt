--TEST--
AOT: hash_hkdf() sha256 raw output (#5025)
--FILE--
<?php
$key = hash_hkdf('sha256', 'key', 16, 'info', 'salt');
echo strlen($key), "\n";
echo bin2hex($key), "\n";
--EXPECT--
16
9ca0d662557439e3b83365f2da4626d3
