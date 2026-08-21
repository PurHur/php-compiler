<?php
// Repro #33630 — AOT str_replace/str_ireplace with array $search must not SIGSEGV.
echo str_replace(['a'], 'x', 'ab'), "\n";
echo str_replace(['a', 'b'], ['x', 'y'], 'ab'), "\n";
echo str_replace(['a', 'b'], 'X', 'abab'), "\n";
echo str_ireplace(['A'], 'x', 'ab'), "\n";
echo str_replace('a', 'x', 'ab'), "\n";
