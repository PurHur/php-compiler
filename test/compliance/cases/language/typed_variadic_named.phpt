--TEST--
typed variadic named parameters — per-element checks on packed assoc array (#18647, Zend/zend_execute.c)
--FILE--
<?php
function stripCallSite(string $msg): string
{
    $pos = strpos($msg, ', called in ');
    return false === $pos ? $msg : substr($msg, 0, $pos);
}

function f(int ...$args): int {
    return array_sum($args);
}
echo f(a: 1, b: 2, c: 3), "\n";
try {
    f(a: 'bad');
} catch (TypeError $e) {
    echo stripCallSite($e->getMessage()), "\n";
}
--EXPECT--
6
f(): Argument #1 must be of type int, string given
