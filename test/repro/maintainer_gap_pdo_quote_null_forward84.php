<?php
/**
 * #21080 — PDO::quote(null) TypeError under PHP_COMPILER_PROFILE=8.4 (ext/pdo/pdo.stub.php).
 */
$p = new PDO('sqlite::memory:');
foreach ([
    'null' => fn () => $p->quote(null),
    'empty' => fn () => $p->quote(''),
] as $n => $fn) {
    try {
        var_export($fn());
        echo " $n\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
