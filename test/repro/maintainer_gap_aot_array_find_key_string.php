<?php
// Repro #27296 — AOT array_find_key string keys must match VM (not NULL).
$k = array_find_key(['a' => 1, 'b' => 2], fn ($v) => $v === 2);
var_export($k);
echo "\n";
