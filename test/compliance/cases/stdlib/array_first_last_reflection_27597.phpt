--TEST--
stdlib array_first/array_last Reflection types match Zend stubs (#27597)
--FILE--
<?php
foreach (['array_first', 'array_last'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo $fn, ' ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
?>
--EXPECT--
array_first array type=array
array_first ret=mixed
array_last array type=array
array_last ret=mixed
