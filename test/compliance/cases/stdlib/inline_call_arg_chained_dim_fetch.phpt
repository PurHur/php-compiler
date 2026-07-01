--TEST--
Stdlib: inline dead call-arg temp wires chained dim-fetch and method-call producers (#14555)
--FILE--
<?php
declare(strict_types=1);

function inner(): void {
    $f = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    echo is_string($f[0]['function']) ? "bt_ok\n" : "bt_bad\n";
}
inner();
$ao = new ArrayObject(['a' => 1]);
echo is_array($ao->getArrayCopy()) ? "ao_ok\n" : "ao_bad\n";
--EXPECT--
bt_ok
ao_ok
