--TEST--
stdlib for-loop $arr[] append survives until after loop (#15125, ext/dom/node.c sibling walk)
--FILE--
<?php
$paths = [];
for ($i = 0; $i < 2; ++$i) {
    $paths[] = 'x';
}
echo json_encode($paths), "\n";
--EXPECT--
["x","x"]
