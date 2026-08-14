<?php
// Repro #31033 — ReflectionClass getMethods/getProperties/getConstructor excess argc
$rc = new ReflectionClass(DateTime::class);
foreach ([
    'getMethods' => fn () => $rc->getMethods(null, 1),
    'getProperties' => fn () => $rc->getProperties(null, 1),
    'getConstructor' => fn () => $rc->getConstructor(1),
] as $n => $fn) {
    try {
        $fn();
        echo "$n: SILENT\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
echo 'ok=', $rc->getConstructor()->getName(), ',',
    count($rc->getMethods()) > 0 ? '1' : '0', ',',
    is_array($rc->getProperties()) ? '1' : '0', ',',
    count($rc->getMethods(null)), "\n";
