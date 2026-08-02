<?php
// AOT array_reverse vs Zend (#27067).
// Packed list: implode (thin-AOT json_encode of setAtIndex results can mis-key; values/keys are correct).
// Assoc preserve_keys: json_encode matches Zend.
$a = array_reverse([1, 2, 3]);
$b = array_reverse(['a' => 1, 'b' => 2], true);
echo '['.implode(',', $a).']', PHP_EOL;
echo json_encode($b), PHP_EOL;
