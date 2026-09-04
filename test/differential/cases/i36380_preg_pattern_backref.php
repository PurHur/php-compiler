<?php
// Numeric pattern backreference `\1` — Zend vs VM (#36380)
// @differential-skip-aot: thin AOT preg $matches still dumps core on master helper-runtime (#24115)
$m = [];
echo preg_match('/^([`]++)(.+?)\1$/', '`hi`', $m) ? '1' : '0';
echo ':';
echo isset($m[2]) ? $m[2] : '-';
echo "\n";
