<?php
// Repro #30924 — ReflectionFunctionAbstract introspection + getClosure excess argc
$rf = new ReflectionFunction(function ($a, $b = 1) {});
foreach ([
    'getNumberOfParameters',
    'getNumberOfRequiredParameters',
    'getFileName',
    'getStartLine',
    'getEndLine',
    'isClosure',
    'isInternal',
    'isUserDefined',
    'isVariadic',
    'returnsReference',
    'hasReturnType',
    'getStaticVariables',
    'getClosure',
] as $m) {
    try {
        $r = $rf->$m(1);
        echo $m, ': ACCEPTED ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $m, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok=', $rf->getNumberOfParameters(), ',', $rf->getNumberOfRequiredParameters(), ',',
    $rf->isClosure() ? '1' : '0', ',', $rf->isInternal() ? '1' : '0', ',',
    $rf->isUserDefined() ? '1' : '0', ',', $rf->isVariadic() ? '1' : '0', ',',
    $rf->returnsReference() ? '1' : '0', ',', $rf->hasReturnType() ? '1' : '0', ',',
    gettype($rf->getStaticVariables()), ',', get_class($rf->getClosure()), "\n";
