--TEST--
Fiber suspend and resume (issue #3130)
--FILE--
<?php
$fiber = new Fiber(function (): void {
    echo "before\n";
    Fiber::suspend("payload");
    echo "after\n";
});
$v = $fiber->start();
echo "suspend returned: $v\n";
$fiber->resume();
--EXPECT--
before
suspend returned: payload
after
