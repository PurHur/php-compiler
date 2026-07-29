--TEST--
Language: (void) cast suppresses #[\NoDiscard] unused-return warning (#7421, pairs #6992)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
ini_set('error_reporting', '32767');

#[\NoDiscard]
function f(): int {
    return 1;
}

f();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 2 ? "warn\n" : "no\n";

error_clear_last();
$z = (void)f();
var_export($z);
echo "\n";
$last = error_get_last();
echo null === $last ? "none\n" : "warn\n";
--EXPECTF--
PHP Warning:  The return value of function f() should either be used or intentionally ignored by casting it as (void)%A
The return value of function f() should either be used or intentionally ignored by casting it as (void)
warn
NULL
none
