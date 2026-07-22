<?php
// repro #22267 — WeakMap IteratorAggregate / getIterator
$m = new WeakMap();
$o = new stdClass();
$m[$o] = 42;
echo 'IA=', ($m instanceof IteratorAggregate) ? 'Y' : 'N', PHP_EOL;
echo 'ME=', method_exists($m, 'getIterator') ? 'Y' : 'N', PHP_EOL;
$ci = class_implements($m) ?: [];
echo 'CI=', implode(',', $ci), PHP_EOL;
function f_22267(IteratorAggregate $x): void
{
    echo "hint=ok\n";
}
f_22267($m);
$it = $m->getIterator();
echo 'IT=', get_class($it), PHP_EOL;
foreach ($it as $k => $v) {
    echo (is_object($k) && $k === $o && $v === 42) ? "iter=ok\n" : "iter=bad\n";
}
