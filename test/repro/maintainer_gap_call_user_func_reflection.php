<?php
/**
 * Issue #24449 — ReflectionFunction('call_user_func') must succeed with Zend
 * stub params callback + variadic args (ext/standard/basic_functions.stub.php).
 *
 * Symptom before #24461: construct / parameter introspection threw
 * ReflectionException "Function call_user_func() does not exist" while
 * function_exists() was true.
 *
 *   php bin/vm.php test/repro/maintainer_gap_call_user_func_reflection.php
 *   php test/repro/maintainer_gap_call_user_func_reflection.php
 */
echo 'fe=', function_exists('call_user_func') ? '1' : '0', "\n";
echo 'call=', call_user_func('strlen', 'ab'), "\n";

try {
    $rf = new ReflectionFunction('call_user_func');
    $bits = [];
    foreach ($rf->getParameters() as $p) {
        $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
    }
    echo 'params=', implode(',', $bits), "\n";
    echo 'isV=', $rf->isVariadic() ? '1' : '0', "\n";
    echo 'num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
} catch (Throwable $e) {
    echo 'ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $rf2 = new ReflectionFunction('call_user_func_array');
    $bits2 = [];
    foreach ($rf2->getParameters() as $p) {
        $bits2[] = $p->getName().($p->isVariadic() ? '...' : '');
    }
    echo 'cufa=', implode(',', $bits2), "\n";
} catch (Throwable $e) {
    echo 'cufa_ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
