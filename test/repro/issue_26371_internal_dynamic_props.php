<?php
/**
 * Repro #26371 — DateTime/XMLReader/SplFixedArray/ArrayObject dynamic props:
 * Zend E_DEPRECATED + write; Closure/WeakMap still Error.
 */
set_error_handler(function ($n, $s) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

foreach ([
    'DateTime' => fn() => new DateTime(),
    'XMLReader' => fn() => new XMLReader(),
    'SplFixedArray' => fn() => new SplFixedArray(1),
    'ArrayObject' => fn() => new ArrayObject(),
] as $label => $mk) {
    $o = $mk();
    try {
        $o->foo = 1;
        echo $label, '=', isset($o->foo) ? 'Y' : 'N', "\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), "\n";
    }
}

try {
    $c = function () {};
    $c->foo = 1;
    echo "Closure=OK\n";
} catch (Throwable $e) {
    echo 'Closure=', get_class($e), "\n";
}
try {
    $w = new WeakMap();
    $w->foo = 1;
    echo "WeakMap=OK\n";
} catch (Throwable $e) {
    echo 'WeakMap=', get_class($e), "\n";
}
