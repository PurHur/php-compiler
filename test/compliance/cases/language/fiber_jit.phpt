--TEST--
Fiber suspend via JIT (issue #4019)
--FILE--
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
--EXPECT--
ok
