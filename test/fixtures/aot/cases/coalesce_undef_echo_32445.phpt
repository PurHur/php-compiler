--TEST--
AOT: echo $undef ?? default must verify and match Zend ZEND_COALESCE (#32445)
--FILE--
<?php
echo $u ?? 'd';
echo "\n";
$x = null;
echo $x ?? 'n';
echo "\n";
$y = 'ok';
echo $y ?? 'n';
echo "\n";
--EXPECT--
d
n
ok
--EXPECT_EXIT--
0
