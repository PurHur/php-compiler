--TEST--
Language: #[\NoDiscard] warns when return value is discarded (VM, #5078)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
ini_set('error_reporting', '32767');

#[\NoDiscard]
function must_use(): int {
    return 1;
}

#[\NoDiscard(message: "check result")]
function with_message(): int {
    return 2;
}

class Box {
    #[\NoDiscard]
    public function ping(): int {
        return 3;
    }
}

must_use();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 2 ? "warn\n" : "no\n";

$x = must_use();
echo $x, "\n";

with_message();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

(new Box())->ping();
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";

$y = (new Box())->ping();
echo $y, "\n";
--EXPECT--
The return value of function must_use() should either be used or intentionally ignored by casting it as (void)
warn
1
The return value of function with_message() should either be used or intentionally ignored by casting it as (void), check result
The return value of function Box::ping() should either be used or intentionally ignored by casting it as (void)
3
