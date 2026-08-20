<?php
/**
 * #32883 — AOT float == / != numeric string must match Zend.
 *
 * Prior: NATIVE_DOUBLE↔STRING == fell through to hardcoded false;
 * STRING↔VALUE == used identical; VALUE↔STRING == used spaceship
 * (float→string form vs numeric-string).
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 php bin/compile.php -o /tmp/feqs.bin \
 *     test/repro/issue_32883_float_eq_numeric_string.php && /tmp/feqs.bin
 */
$a = 1.5;
var_dump($a == "1.5");
var_dump("1.5" == $a);
var_dump($a != "1.5");
var_dump(1.5 == "1.5");
var_dump(1.5 == "1.50");
var_dump(1.5 == "2");
var_dump(1.5 == "abc");
var_dump(1.5 === "1.5");
