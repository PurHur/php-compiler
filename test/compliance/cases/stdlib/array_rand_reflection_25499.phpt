--TEST--
stdlib array_rand() Reflection return and $num default match Zend stub (#25499)
--FILE--
<?php
$r = new ReflectionFunction('array_rand');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
    if ($p->isDefaultValueAvailable()) {
        echo $p->getName(), ' default=', var_export($p->getDefaultValue(), true), "\n";
    }
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
?>
--EXPECT--
array type=array
num type=int
num default=1
ret=array|string|int
