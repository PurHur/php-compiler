--TEST--
stdlib array_slice/gc_status/preg_replace_callback_array Reflection match Zend stubs (#25513)
--FILE--
<?php
foreach (['array_slice', 'gc_status', 'preg_replace_callback_array'] as $f) {
    $r = new ReflectionFunction($f);
    echo "== $f\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
        if ($p->isDefaultValueAvailable()) {
            echo $p->getName(), ' default=', var_export($p->getDefaultValue(), true), "\n";
        }
    }
    echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
?>
--EXPECT--
== array_slice
array type=array
offset type=int
length type=?int
length default=NULL
preserve_keys type=bool
preserve_keys default=false
ret=array
== gc_status
ret=array
== preg_replace_callback_array
pattern type=array
subject type=array|string
limit type=int
limit default=-1
count type=NONE
count default=NULL
flags type=int
flags default=0
ret=array|string|null
