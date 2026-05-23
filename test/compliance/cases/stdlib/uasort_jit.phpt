--TEST--
stdlib uasort() JIT packed list sort (issue #1211)
--FILE--
<?php
$list = ['zebra', 'apple', 'mango'];
uasort($list, 'strcmp');
echo implode(',', $list), "\n";
--EXPECT--
apple,mango,zebra
