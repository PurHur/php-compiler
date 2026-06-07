--TEST--
Language: #[\NoDiscard] unchecked return warns; (void) cast suppresses (#7346)
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
(void) f();
$last = error_get_last();
echo null === $last ? "none\n" : "warn\n";

echo "ok\n";
--EXPECT--
PHP Warning:  The return value of function f() should either be used or intentionally ignored by casting it as (void)
The return value of function f() should either be used or intentionally ignored by casting it as (void)
warn
none
ok
