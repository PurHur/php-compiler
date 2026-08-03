<?php
// Issue #27021: AOT is_finite()/is_infinite()/is_nan() — NestedJIT-safe fcmp leaves.
var_export(is_finite(1.0));
echo PHP_EOL;
var_export(is_infinite(INF));
echo PHP_EOL;
var_export(is_nan(NAN));
echo PHP_EOL;
