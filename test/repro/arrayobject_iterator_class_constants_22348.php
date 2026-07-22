<?php
foreach (['ArrayObject', 'ArrayIterator', 'RecursiveArrayIterator'] as $cls) {
    echo $cls, ":\n";
    echo '  defined_AAP=', defined($cls . '::ARRAY_AS_PROPS') ? 'Y' : 'N', "\n";
    echo '  defined_SPL=', defined($cls . '::STD_PROP_LIST') ? 'Y' : 'N', "\n";
    echo '  getConstant_AAP=', var_export((new ReflectionClass($cls))->getConstant('ARRAY_AS_PROPS'), true), "\n";
    echo '  getConstant_SPL=', var_export((new ReflectionClass($cls))->getConstant('STD_PROP_LIST'), true), "\n";
    echo '  hasConstant_AAP=', (new ReflectionClass($cls))->hasConstant('ARRAY_AS_PROPS') ? 'Y' : 'N', "\n";
    try {
        echo '  constant_AAP=', constant($cls . '::ARRAY_AS_PROPS'), "\n";
    } catch (Throwable $e) {
        echo '  constant_AAP=ERR:', $e->getMessage(), "\n";
    }
    echo '  direct_AAP=', $cls::ARRAY_AS_PROPS, "\n";
    $c = (new ReflectionClass($cls))->getConstants();
    ksort($c);
    echo '  getConstants=', json_encode($c), "\n";
}
