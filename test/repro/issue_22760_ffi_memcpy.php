<?php
// Repro for #22760 — FFI memory helpers after #22369.
foreach (['memcpy', 'memcmp', 'memset', 'string', 'alignof', 'type'] as $m) {
    echo $m, '=', method_exists('FFI', $m) ? '1' : '0', PHP_EOL;
}
$a = FFI::new('char[16]');
FFI::memset($a, 0, 16);
FFI::memcpy($a, 'hi', 2);
echo 'string=', FFI::string($a, 2), PHP_EOL;
echo 'memcmp=', FFI::memcmp($a, 'hi', 2), PHP_EOL;
echo 'alignof=', FFI::alignof($a), PHP_EOL;
$t = FFI::type('int');
echo 'type=', $t instanceof FFI\CType ? 'CType' : get_class($t), PHP_EOL;
echo 'sizeof=', FFI::sizeof($t), PHP_EOL;
