--TEST--
ReflectionProperty::isInitialized() — typed property init probe (issue #6653, ext/reflection/php_reflection.c)
--FILE--
<?php
class Box {
    public int $count;
    public $untyped;
    public static int $sid;
}
$r = new ReflectionProperty(Box::class, 'count');
$b = new Box();
var_export($r->isInitialized($b));
$b->count = 1;
var_export($r->isInitialized($b));
echo "\n";

$ru = new ReflectionProperty(Box::class, 'untyped');
var_export($ru->isInitialized($b));
echo "\n";

$rs = new ReflectionProperty(Box::class, 'sid');
var_export($rs->isInitialized());
echo "\n";

try {
    $r->isInitialized();
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $r->isInitialized(new stdClass());
} catch (ReflectionException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
falsetrue
true
false
TypeError: ReflectionProperty::isInitialized(): Argument #1 ($object) must be provided for instance properties
ReflectionException: Given object is not an instance of the class this property was declared in
