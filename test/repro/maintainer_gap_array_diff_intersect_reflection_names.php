<?php
/**
 * #23593 — array_diff and array_intersect family Reflection names match Zend (ext/standard/array.stub.php).
 */
$fns = [
    'array_diff',
    'array_diff_assoc',
    'array_diff_key',
    'array_intersect',
    'array_intersect_assoc',
    'array_intersect_key',
    'array_diff_uassoc',
    'array_diff_ukey',
    'array_intersect_uassoc',
    'array_intersect_ukey',
];
foreach ($fns as $fn) {
    $r = new ReflectionFunction($fn);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = $p->getName() . ($p->isVariadic() ? '*' : '');
    }
    echo $fn, ' [', implode(',', $parts), "]\n";
}

try {
    var_export(array_diff(array: [1, 2]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(array_diff(array: [1, 2], arrays: [2]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo json_encode(array_diff([1, 2, 3], [2])), "\n";
