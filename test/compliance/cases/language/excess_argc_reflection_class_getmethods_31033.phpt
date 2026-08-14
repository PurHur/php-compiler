--TEST--
language: ReflectionClass getMethods/getProperties/getConstructor excess argc → ArgumentCountError (#31033, php_reflection.c)
--FILE--
<?php
$rc = new ReflectionClass(DateTime::class);
foreach ([
    'getMethods' => fn () => $rc->getMethods(null, 1),
    'getProperties' => fn () => $rc->getProperties(null, 1),
    'getConstructor' => fn () => $rc->getConstructor(1),
] as $n => $fn) {
    try {
        $fn();
        echo "$n: SILENT\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
echo 'ok=', $rc->getConstructor()->getName(), ',',
    count($rc->getMethods()) > 0 ? '1' : '0', ',',
    is_array($rc->getProperties()) ? '1' : '0', ',',
    count($rc->getMethods(null)), "\n";
--EXPECT--
getMethods: ArgumentCountError: ReflectionClass::getMethods() expects at most 1 argument, 2 given
getProperties: ArgumentCountError: ReflectionClass::getProperties() expects at most 1 argument, 2 given
getConstructor: ArgumentCountError: ReflectionClass::getConstructor() expects exactly 0 arguments, 1 given
ok=__construct,1,1,22
