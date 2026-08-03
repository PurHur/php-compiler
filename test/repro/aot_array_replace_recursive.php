<?php

// Repro for #26977 — AOT array_replace_recursive call-site LLVM overlay
// Case: nested sibling string keys (php-src array_replace_recursive).
// Expected: 1,2  (b kept from left, c added from right)
$a = array_replace_recursive(['a' => ['b' => 1]], ['a' => ['c' => 2]]);
$inner = $a['a'];
echo $inner['b'], ',', $inner['c'], PHP_EOL;
