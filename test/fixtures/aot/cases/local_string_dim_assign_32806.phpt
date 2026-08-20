--TEST--
AOT: local string $s[i]='Z' stays a string (#32806)
--FILE--
<?php
$s = 'abc';
$s[1] = 'Z';
echo $s, "\n";
--EXPECT--
aZc
--EXPECT_EXIT--
0
