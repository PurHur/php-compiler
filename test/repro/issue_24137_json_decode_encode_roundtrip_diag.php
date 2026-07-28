<?php
// Maintainer diag for #24137 — NestedJIT string param from heap __string__*.
// Under AOT, resultTag sees strlen but empty content (hex=). Constant `$j='{"a":1}'` works.
$d = ['a' => 1];
$j = json_encode($d);
echo 'enc=', $j, ' len=', strlen($j), "\n";
$r = json_decode($j, true);
echo 'a=', (is_array($r) ? ($r['a'] ?? 'x') : 'n'), ' type=', gettype($r), "\n";
