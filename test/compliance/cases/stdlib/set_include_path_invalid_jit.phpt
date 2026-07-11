--TEST--
JIT/AOT set_include_path() — empty/false rejected (issue #12165)
--FILE--
<?php
$before = get_include_path();
$r1 = set_include_path('');
$r2 = set_include_path(false);
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo get_include_path() === $before ? "unchanged\n" : "changed\n";
--EXPECT--
false
false
unchanged
--CREDITS--
PurHur/php-compiler issue #12165
