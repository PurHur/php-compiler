<?php
/**
 * ?? on uninitialized typed property as a function argument.
 */
error_reporting(E_ALL);

class C
{
    public int $x;
}

function take($v)
{
    return $v;
}

$o = new C();
try {
    echo "arg=" . take($o->x ?? "d") . "\n";
} catch (Throwable $e) {
    echo "arg=" . get_class($e) . ":" . $e->getMessage() . "\n";
}
echo "after\n";
