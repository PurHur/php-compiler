--TEST--
stdlib array_find/array_find_key/array_any/array_all Reflection types match Zend stubs (#25452)
--FILE--
<?php
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        echo $fn, ' ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
?>
--EXPECT--
array_find array type=array
array_find callback type=callable
array_find ret=mixed
array_find_key array type=array
array_find_key callback type=callable
array_find_key ret=mixed
array_any array type=array
array_any callback type=callable
array_any ret=bool
array_all array type=array
array_all callback type=callable
array_all ret=bool
