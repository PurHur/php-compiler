--TEST--
stdlib ksort() no-op on packed list arrays (issue #2271)
--FILE--
<?php
$list = ['c', 'a', 'b'];
ksort($list);
echo implode(',', $list), "\n";
--EXPECT--
c,a,b
