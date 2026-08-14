--TEST--
ReflectionClass getProperties/getMethods/getConstants filter is ?int (#30897)
--FILE--
<?php
$r = new ReflectionClass(DateTime::class);
foreach (['getProperties', 'getMethods', 'getConstants', 'getReflectionConstants'] as $m) {
    try {
        $r->$m('EXTRA');
        echo "$m accepted\n";
    } catch (TypeError $e) {
        echo "$m ", $e->getMessage(), "\n";
    }
}
foreach (['getProperties', 'getMethods', 'getConstants', 'getReflectionConstants'] as $m) {
    try {
        $v = $r->$m(null);
        echo "$m null=", count($v), "\n";
    } catch (Throwable $e) {
        echo "$m null ", get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'getMethods PUBLIC=', count($r->getMethods(ReflectionMethod::IS_PUBLIC)), "\n";
try {
    $r->getProperties([]);
    echo "array accepted\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getProperties ReflectionClass::getProperties(): Argument #1 ($filter) must be of type ?int, string given
getMethods ReflectionClass::getMethods(): Argument #1 ($filter) must be of type ?int, string given
getConstants ReflectionClass::getConstants(): Argument #1 ($filter) must be of type ?int, string given
getReflectionConstants ReflectionClass::getReflectionConstants(): Argument #1 ($filter) must be of type ?int, string given
getProperties null=0
getMethods null=22
getConstants null=14
getReflectionConstants null=14
getMethods PUBLIC=22
ReflectionClass::getProperties(): Argument #1 ($filter) must be of type ?int, array given
