--TEST--
language: ReflectionClass kind/query excess argc → ArgumentCountError (#31126, php_reflection.c)
--FILE--
<?php
enum E { case A; }
$re = new ReflectionClass(E::class);
$rs = new ReflectionClass(stdClass::class);
foreach ([
    'isEnum' => fn () => $re->isEnum(1),
    'isInterface' => fn () => $rs->isInterface(1),
    'isTrait' => fn () => $rs->isTrait(1),
    'isAbstract' => fn () => $rs->isAbstract(1),
    'isFinal' => fn () => $rs->isFinal(1),
    'isReadOnly' => fn () => $rs->isReadOnly(1),
    'isIterable' => fn () => $rs->isIterable(1),
    'getModifiers' => fn () => $rs->getModifiers(1),
] as $n => $fn) {
    try {
        var_export($fn());
        echo " $n: SILENT\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
echo 'ok=', $re->isEnum() ? '1' : '0', ',',
    $rs->isInterface() ? '1' : '0', ',',
    $rs->isTrait() ? '1' : '0', ',',
    $rs->isAbstract() ? '1' : '0', ',',
    $rs->isFinal() ? '1' : '0', ',',
    $rs->isReadOnly() ? '1' : '0', ',',
    $rs->isIterable() ? '1' : '0', ',',
    $rs->getModifiers(), "\n";
--EXPECT--
isEnum: ArgumentCountError: ReflectionClass::isEnum() expects exactly 0 arguments, 1 given
isInterface: ArgumentCountError: ReflectionClass::isInterface() expects exactly 0 arguments, 1 given
isTrait: ArgumentCountError: ReflectionClass::isTrait() expects exactly 0 arguments, 1 given
isAbstract: ArgumentCountError: ReflectionClass::isAbstract() expects exactly 0 arguments, 1 given
isFinal: ArgumentCountError: ReflectionClass::isFinal() expects exactly 0 arguments, 1 given
isReadOnly: ArgumentCountError: ReflectionClass::isReadOnly() expects exactly 0 arguments, 1 given
isIterable: ArgumentCountError: ReflectionClass::isIterable() expects exactly 0 arguments, 1 given
getModifiers: ArgumentCountError: ReflectionClass::getModifiers() expects exactly 0 arguments, 1 given
ok=1,0,0,0,0,0,0,0
