<?php
// Repro #24664 — mysqli procedural Reflection / named params (php-src mysqli.stub.php).
// Run: PHP_COMPILER_ENABLE_MYSQLI=1 php bin/vm.php test/repro/issue_24664_mysqli_reflection_param_names.php
foreach (['mysqli_query', 'mysqli_prepare', 'mysqli_real_escape_string'] as $f) {
    $n = [];
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $f, ': ', implode(',', $n), "\n";
}
try {
    mysqli_query(mysql: null, query: 'SELECT 1');
} catch (Throwable $e) {
    echo 'named mysql: ', $e->getMessage(), "\n";
}
try {
    mysqli_query(link: null, query: 'SELECT 1');
} catch (Throwable $e) {
    echo 'named link: ', $e->getMessage(), "\n";
}
try {
    mysqli_real_escape_string(mysql: null, string: 'x');
} catch (Throwable $e) {
    echo 'escape string: ', $e->getMessage(), "\n";
}
try {
    mysqli_real_escape_string(link: null, escapestr: 'x');
} catch (Throwable $e) {
    echo 'escape stale: ', $e->getMessage(), "\n";
}
