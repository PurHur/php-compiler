<?php
// Repro for #16616 — str_starts_with()/str_ends_with() haystack: named parameter
$ok = str_starts_with(haystack: 'abcdef', needle: 'abc')
    && str_ends_with(haystack: 'abcdef', needle: 'def');
echo $ok ? "ok\n" : "fail\n";
