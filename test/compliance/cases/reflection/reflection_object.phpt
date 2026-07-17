--TEST--
Reflection: ReflectionObject extends ReflectionClass — construct + dynamic props (#20098, ext/reflection/php_reflection.c)
--FILE--
<?php
echo "ReflectionObject=", class_exists("ReflectionObject") ? "yes" : "no", "\n";

$o = new stdClass();
$o->x = 1;
$ro = new ReflectionObject($o);
echo "name=", $ro->getName(), " props=", count($ro->getProperties()), "\n";
echo "is_rc=", ($ro instanceof ReflectionClass) ? "yes" : "no", "\n";

class U {
    public $a = 1;
}
$u = new U();
$u->dyn = 2;
$ru = new ReflectionObject($u);
echo "U name=", $ru->getName(), " props=", count($ru->getProperties()), "\n";

try {
    new ReflectionObject(1);
} catch (TypeError $e) {
    echo "type:", $e->getMessage(), "\n";
}

try {
    new ReflectionObject();
} catch (ArgumentCountError $e) {
    echo "argc:", $e->getMessage(), "\n";
}
--EXPECT--
ReflectionObject=yes
name=stdClass props=1
is_rc=yes
U name=U props=2
type:ReflectionObject::__construct(): Argument #1 ($object) must be of type object, int given
argc:ReflectionObject::__construct() expects exactly 1 argument, 0 given
