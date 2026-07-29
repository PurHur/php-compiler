--TEST--
get_mangled_object_vars named object argument + Reflection (VM, issue #25016)
--FILE--
<?php
class A25016
{
    private $x = 1;
}
$r = new ReflectionFunction('get_mangled_object_vars');
echo 'argc=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), $p->hasType() ? ':'.$p->getType() : '', ' req=', $p->isOptional() ? '0' : '1', PHP_EOL;
}
$m = get_mangled_object_vars(object: new A25016);
echo 'named_ok keys=', count($m), PHP_EOL;
echo array_key_exists("\0A25016\0x", $m) ? "mangled_key\n" : "missing_key\n";
try {
    get_mangled_object_vars(obj: new A25016);
    echo "obj accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
argc=1
object:object req=1
named_ok keys=1
mangled_key
Unknown named parameter $obj
