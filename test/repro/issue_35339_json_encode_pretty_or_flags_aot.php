<?php
// #35339 — json_encode() array-literal fold must not bake flags=0 when $flags is runtime-known
// (JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES is not a single ConstFetch).
echo json_encode(['a' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
$f = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
echo json_encode(['a' => 1], $f), "\n";
