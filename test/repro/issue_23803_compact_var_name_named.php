<?php
// Repro #23803 — compact() variadic named $var_name (ext/standard/basic_functions.stub.php)
$rf = new ReflectionFunction('compact');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'reflection:', implode(',', $names), "\n";

$a = 1;
$b = 2;
try {
    compact(var_name: 'a', var_name: 'b');
    echo "named: no error\n";
} catch (Throwable $e) {
    echo 'named:', get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'positional:';
var_export(compact('a', 'b'));
echo "\n";
