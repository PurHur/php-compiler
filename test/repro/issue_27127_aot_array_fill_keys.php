<?php
// #27127 — AOT array_fill_keys() must match Zend/VM/JIT (not link fail / segfault / {})
echo json_encode(array_fill_keys(['a', 'b'], 1)), PHP_EOL;
$a = array_fill_keys(['x', 'y'], 'z');
echo $a['x'], $a['y'], PHP_EOL;
echo json_encode(array_fill_keys([1, 2], true)), PHP_EOL;
