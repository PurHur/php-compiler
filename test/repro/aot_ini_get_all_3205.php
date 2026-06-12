<?php
// Issue #3205 — ini_get_all() JIT/AOT lowering (ext/standard/ini.c).
$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors']) ? "flat_ok\n" : "flat_fail\n";
echo ini_get_all('nonexistent') === false ? "ext_false\n" : "ext_bad\n";
