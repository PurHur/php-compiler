--TEST--
stdlib ReflectionProperty::isReadable()/isWritable() on 8.5 forward profile (#28533, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class ReflectionReadableForwardProbe {
    public int $x = 1;
}
$r = new ReflectionProperty(ReflectionReadableForwardProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    echo $method, '=', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
$m = new ReflectionMethod(ReflectionProperty::class, 'isReadable');
echo 'arity=', $m->getNumberOfParameters(), ' req=', $m->getNumberOfRequiredParameters(), "\n";
foreach ($m->getParameters() as $p) {
    echo $p->getName(), ':', (string) $p->getType(), ' opt=', (int) $p->isOptional(), "\n";
}
echo 'readable=', (int) $r->isReadable(null), ' writable=', (int) $r->isWritable(null), "\n";
echo 'named=', (int) $r->isReadable(scope: null, object: null), "\n";
try {
    $r->isReadable();
    echo "zero-arg-ok\n";
} catch (ArgumentCountError $e) {
    echo "zero-arg=ACE\n";
}
--EXPECT--
isReadable=yes
isWritable=yes
arity=2 req=1
scope:?string opt=0
object:?object opt=1
readable=1 writable=1
named=1
zero-arg=ACE
