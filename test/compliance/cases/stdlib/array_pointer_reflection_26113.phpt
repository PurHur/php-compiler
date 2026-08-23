--TEST--
stdlib current/end/reset/next/prev/key Reflection match Zend stubs (#26113)
--FILE--
<?php
foreach (['current', 'end', 'reset', 'next', 'prev', 'key'] as $f) {
    $r = new ReflectionFunction($f);
    $p = $r->getParameters()[0];
    echo $f, ' type=', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' byref=', (int) $p->isPassedByReference(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
$a = [10, 20];
echo 'current=', var_export(current($a), true), "\n";
?>
--EXPECT--
current type=object|array byref=0 ret=mixed
end type=object|array byref=1 ret=mixed
reset type=object|array byref=1 ret=mixed
next type=object|array byref=1 ret=mixed
prev type=object|array byref=1 ret=mixed
key type=object|array byref=0 ret=string|int|null
current=10
