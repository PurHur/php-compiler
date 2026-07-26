--TEST--
md5/sha1 named string:/binary: arguments (JIT, issue #23227)
--FILE--
<?php
echo md5(string: 'x'), PHP_EOL;
echo bin2hex(md5(string: 'x', binary: true)), PHP_EOL;
echo sha1(string: 'x'), PHP_EOL;
echo bin2hex(sha1(string: 'x', binary: true)), PHP_EOL;
--EXPECT--
9dd4e461268c8034f5c8564e155c67a6
9dd4e461268c8034f5c8564e155c67a6
11f6ad8ec52a2984abaafd7c3b516503785c2072
11f6ad8ec52a2984abaafd7c3b516503785c2072
