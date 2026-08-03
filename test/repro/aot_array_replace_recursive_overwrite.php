<?php

// Repro for #26977 — nested key replace (b updated) + add (c)
$a = array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]]);
$inner = $a['a'];
echo $inner['b'], ',', $inner['c'], PHP_EOL;
