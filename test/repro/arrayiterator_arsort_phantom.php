<?php
// #22594 — ArrayIterator::arsort/krsort must not exist (php-src spl_array.stub.php)
// Use object form of method_exists (class-string form hits a separate JIT gap).
$it = new ArrayIterator(['b' => 2, 'a' => 1]);
foreach (['arsort', 'krsort'] as $m) {
    echo "$m method_exists=", method_exists($it, $m) ? '1' : '0', "\n";
}
try {
    $it->arsort();
    echo "arsort ran\n";
} catch (Error $e) {
    echo "arsort: Error\n";
}
try {
    $it->krsort();
    echo "krsort ran\n";
} catch (Error $e) {
    echo "krsort: Error\n";
}
