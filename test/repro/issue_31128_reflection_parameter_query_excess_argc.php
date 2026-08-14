<?php
// Repro #31128 — ReflectionParameter zero-arg query excess argc
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
try {
    $p->getName(1);
    echo "already: SILENT\n";
} catch (Throwable $e) {
    echo 'already: ', get_class($e), ': ', $e->getMessage(), "\n";
}
