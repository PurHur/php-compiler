<?php

$m = array_merge(['x' => 1], [0 => 'a', 1 => 'b']);
$listOnly = array_merge([0 => 'a'], [0 => 'b']);

$ok = 3 === count($m)
    && 1 === $m['x']
    && 'a' === $m[0]
    && 'b' === $m[1];

$listOnlyOk = 2 === count($listOnly)
    && 'a' === $listOnly[0]
    && 'b' === $listOnly[1];

echo 'ok=' . ($ok ? 'true' : 'false') . "\n";
echo 'list_only_ok=' . ($listOnlyOk ? 'true' : 'false') . "\n";
