--TEST--
Fiber JIT/VM agree on start/resume/getReturn (#10079)
--FILE--
<?php
declare(strict_types=1);

$fiber = new Fiber(function (): int {
    Fiber::suspend('step1');
    return 42;
});
var_export($fiber->start());
echo "\n";
var_export($fiber->resume());
echo "\n";
var_export($fiber->getReturn());
echo "\n";
--EXPECT--
'step1'
NULL
42
