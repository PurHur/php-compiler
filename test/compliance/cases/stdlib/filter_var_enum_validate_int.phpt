--TEST--
stdlib filter_var() — FILTER_VALIDATE_INT rejects enum case (#5796, ext/filter/filter.c, php-src-strict)
--FILE--
<?php
enum E: int { case A = 1; }
var_export(filter_var(E::A, FILTER_VALIDATE_INT));
echo "\n";
--EXPECT--
false
