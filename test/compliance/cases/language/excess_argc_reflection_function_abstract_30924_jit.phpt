--TEST--
language: ReflectionFunctionAbstract introspection + getClosure excess argc → ArgumentCountError JIT (#30924, php_reflection.c)
--FILE--
<?php
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
--EXPECT--
getNumberOfParameters: ArgumentCountError: ReflectionFunctionAbstract::getNumberOfParameters() expects exactly 0 arguments, 1 given
getNumberOfRequiredParameters: ArgumentCountError: ReflectionFunctionAbstract::getNumberOfRequiredParameters() expects exactly 0 arguments, 1 given
getFileName: ArgumentCountError: ReflectionFunctionAbstract::getFileName() expects exactly 0 arguments, 1 given
getStartLine: ArgumentCountError: ReflectionFunctionAbstract::getStartLine() expects exactly 0 arguments, 1 given
getEndLine: ArgumentCountError: ReflectionFunctionAbstract::getEndLine() expects exactly 0 arguments, 1 given
isClosure: ArgumentCountError: ReflectionFunctionAbstract::isClosure() expects exactly 0 arguments, 1 given
isInternal: ArgumentCountError: ReflectionFunctionAbstract::isInternal() expects exactly 0 arguments, 1 given
isUserDefined: ArgumentCountError: ReflectionFunctionAbstract::isUserDefined() expects exactly 0 arguments, 1 given
isVariadic: ArgumentCountError: ReflectionFunctionAbstract::isVariadic() expects exactly 0 arguments, 1 given
returnsReference: ArgumentCountError: ReflectionFunctionAbstract::returnsReference() expects exactly 0 arguments, 1 given
hasReturnType: ArgumentCountError: ReflectionFunctionAbstract::hasReturnType() expects exactly 0 arguments, 1 given
getStaticVariables: ArgumentCountError: ReflectionFunctionAbstract::getStaticVariables() expects exactly 0 arguments, 1 given
getClosure: ArgumentCountError: ReflectionFunction::getClosure() expects exactly 0 arguments, 1 given
ok=2,1,1,0,1,0,0,0,array,Closure
