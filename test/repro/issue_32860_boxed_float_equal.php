<?php
/**
 * #32860 — AOT boxed float == / <=> must not SIGSEGV.
 *
 * Prior: (1) looseEqualValueToNativeDouble eagerly __value__readString on
 * TYPE_NATIVE_DOUBLE boxes; (2) __value__spaceship NestedJIT doubleSpaceship
 * re-entered itself (#32538 shape).
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 php bin/compile.php -o /tmp/feq.bin \
 *     test/repro/issue_32860_boxed_float_equal.php && /tmp/feq.bin
 */
$a = 1.5;
var_dump($a == 1.5);
var_dump($a != 2.0);
$c = 1.5;
var_dump($a == $c);
var_dump(NAN == 1.0);
var_dump(NAN != NAN);
var_dump(NAN <=> NAN);
$b = NAN;
var_dump($b == 1.0);
