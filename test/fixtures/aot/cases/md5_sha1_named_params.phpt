--TEST--
AOT: md5()/sha1() named string:/binary: arguments (#23227)
--FILE--
<?php
echo md5(string: 'x'), "\n";
echo bin2hex(md5(string: 'x', binary: true)), "\n";
echo sha1(string: 'x'), "\n";
echo bin2hex(sha1(string: 'x', binary: true)), "\n";
--EXPECT--
9dd4e461268c8034f5c8564e155c67a6
9dd4e461268c8034f5c8564e155c67a6
11f6ad8ec52a2984abaafd7c3b516503785c2072
11f6ad8ec52a2984abaafd7c3b516503785c2072
