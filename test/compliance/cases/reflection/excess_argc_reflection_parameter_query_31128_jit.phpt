--TEST--
language: ReflectionParameter query excess argc → ArgumentCountError JIT (#31128, php_reflection.c)
--FILE--
<?php
function f(int $a = 1) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
foreach ([
    'isOptional' => fn () => $p->isOptional(1),
    'isPassedByReference' => fn () => $p->isPassedByReference(1),
    'canBePassedByValue' => fn () => $p->canBePassedByValue(1),
    'isDefaultValueAvailable' => fn () => $p->isDefaultValueAvailable(1),
    'isDefaultValueConstant' => fn () => $p->isDefaultValueConstant(1),
    'isVariadic' => fn () => $p->isVariadic(1),
    'hasType' => fn () => $p->hasType(1),
    'isPromoted' => fn () => $p->isPromoted(1),
    'allowsNull' => fn () => $p->allowsNull(1),
] as $n => $fn) {
    try {
        var_export($fn());
        echo " $n: SILENT\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=',
    $p->isOptional() ? '1' : '0', ',',
    $p->isPassedByReference() ? '1' : '0', ',',
    $p->canBePassedByValue() ? '1' : '0', ',',
    $p->isDefaultValueAvailable() ? '1' : '0', ',',
    $p->isDefaultValueConstant() ? '1' : '0', ',',
    $p->isVariadic() ? '1' : '0', ',',
    $p->hasType() ? '1' : '0', ',',
    $p->isPromoted() ? '1' : '0', ',',
    $p->allowsNull() ? '1' : '0', "\n";
--EXPECT--
isOptional: ArgumentCountError: ReflectionParameter::isOptional() expects exactly 0 arguments, 1 given
isPassedByReference: ArgumentCountError: ReflectionParameter::isPassedByReference() expects exactly 0 arguments, 1 given
canBePassedByValue: ArgumentCountError: ReflectionParameter::canBePassedByValue() expects exactly 0 arguments, 1 given
isDefaultValueAvailable: ArgumentCountError: ReflectionParameter::isDefaultValueAvailable() expects exactly 0 arguments, 1 given
isDefaultValueConstant: ArgumentCountError: ReflectionParameter::isDefaultValueConstant() expects exactly 0 arguments, 1 given
isVariadic: ArgumentCountError: ReflectionParameter::isVariadic() expects exactly 0 arguments, 1 given
hasType: ArgumentCountError: ReflectionParameter::hasType() expects exactly 0 arguments, 1 given
isPromoted: ArgumentCountError: ReflectionParameter::isPromoted() expects exactly 0 arguments, 1 given
allowsNull: ArgumentCountError: ReflectionParameter::allowsNull() expects exactly 0 arguments, 1 given
ok=1,0,1,1,0,0,1,0,0
