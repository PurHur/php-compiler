--TEST--
Function-local static object initializer (issue #4352)
--FILE--
<?php
declare(strict_types=1);

function counter(): int {
    static $n = 0;
    return ++$n;
}

function holder(): stdClass {
    static $obj = new stdClass;
    $obj->count = ($obj->count ?? 0) + 1;
    return $obj;
}

echo counter(), ' ', counter(), "\n";
$o = holder();
echo $o->count, ' ';
$o2 = holder();
echo $o2->count, "\n";
--EXPECT--
1 2
1 2
