<?php
/**
 * Repro #26374 — SPL class constants visible to defined()/constant()/Reflection
 * (regression of #22348 after case-sensitive ClassConstName keys #25910).
 */
foreach (['ArrayObject::STD_PROP_LIST', 'ArrayObject::ARRAY_AS_PROPS', 'RecursiveArrayIterator::CHILD_ARRAYS_ONLY'] as $c) {
    echo $c, ' defined=', defined($c) ? 'Y' : 'N';
    try {
        echo ' constant=', constant($c);
    } catch (Throwable $e) {
        echo ' constant=ERR';
    }
    echo "\n";
}
$r = new ReflectionClass(ArrayObject::class);
echo 'has=', $r->hasConstant('STD_PROP_LIST') ? 'Y' : 'N',
    ' get=', var_export($r->getConstant('STD_PROP_LIST'), true),
    ' fetch=', ArrayObject::STD_PROP_LIST, "\n";
