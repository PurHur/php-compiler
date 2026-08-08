<?php
/** Repro #28854 — move_uploaded_file Reflection/named params match Zend from/to. */
$rf = new ReflectionFunction('move_uploaded_file');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'reflection=', implode(',', $names), "\n";

try {
    $ok = move_uploaded_file(from: '/nope-from', to: '/nope-to');
    echo 'named_from_to=', var_export($ok, true), "\n";
} catch (Throwable $e) {
    echo 'named_from_to=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    move_uploaded_file(path: '/a', new_path: '/b');
    echo "legacy_path_accepted\n";
} catch (Throwable $e) {
    echo 'legacy_path=', $e->getMessage(), "\n";
}
