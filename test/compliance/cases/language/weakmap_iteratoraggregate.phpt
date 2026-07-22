--TEST--
language WeakMap implements IteratorAggregate + getIterator() InternalIterator (#22267)
--FILE--
<?php
$m = new WeakMap();
$o = new stdClass();
$m[$o] = 42;

echo ($m instanceof IteratorAggregate) ? "IA=Y\n" : "IA=N\n";
echo method_exists($m, 'getIterator') ? "ME=Y\n" : "ME=N\n";

$ifaces = class_implements($m);
$need = ['ArrayAccess', 'Countable', 'IteratorAggregate', 'Traversable'];
$ok = true;
foreach ($need as $n) {
    if (!isset($ifaces[$n])) {
        $ok = false;
        break;
    }
}
echo $ok ? "CI=Y\n" : "CI=N\n";

function f(IteratorAggregate $x): void
{
    echo "hint=ok\n";
}
f($m);

$it = $m->getIterator();
echo (is_object($it) && $it instanceof InternalIterator) ? "IT=Y\n" : "IT=N\n";
$it->rewind();
echo $it->valid() ? "V=Y\n" : "V=N\n";
$k = $it->key();
echo (is_object($k) && $k === $o) ? "K=Y\n" : "K=N\n";
echo ($it->current() === 42) ? "C=Y\n" : "C=N\n";

$n = 0;
foreach ($m as $fk => $fv) {
    if ($fk === $o && $fv === 42) {
        $n++;
    }
}
echo ($n === 1) ? "FE=Y\n" : "FE=N\n";
--EXPECT--
IA=Y
ME=Y
CI=Y
hint=ok
IT=Y
V=Y
K=Y
C=Y
FE=Y
