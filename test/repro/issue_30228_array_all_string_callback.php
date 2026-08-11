<?php

// Issue #30228 — array_all/find/any with unary internal string callback (PROFILE≥8.4).
echo 'all=', var_export(array_all([1, 2, 3], 'is_int'), true), "\n";
echo 'any=', var_export(array_any([1, 'x'], 'is_string'), true), "\n";
echo 'find=', var_export(array_find([1, 'x', 3], 'is_string'), true), "\n";
echo 'find_key=', var_export(array_find_key(['a' => 1, 'b' => 'x'], 'is_string'), true), "\n";
$n = 0;
echo 'closure=', var_export(array_all([1, 2], static function ($v, $k) use (&$n) {
    ++$n;

    return is_int($v) && is_int($k);
}), true), " args={$n}\n";
