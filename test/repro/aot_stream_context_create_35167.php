<?php

// #35167 — thin AOT stream_context_create must compile (HashTableMergeLlvm scoped to ABI).
$empty = stream_context_create();
$opts = stream_context_create(['http' => ['timeout' => 1]]);
echo (is_resource($empty) || is_object($empty)) ? 'e' : 'E';
echo (is_resource($opts) || is_object($opts)) ? 'o' : 'O';
$got = stream_context_get_options($opts);
echo isset($got['http']['timeout']) && (int) $got['http']['timeout'] === 1 ? '1' : '0';
echo "\n";
