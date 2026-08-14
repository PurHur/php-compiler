<?php
// Repro #31126 — ReflectionClass kind/query excess argc
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
