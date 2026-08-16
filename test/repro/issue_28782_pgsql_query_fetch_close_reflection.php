<?php

/**
 * #28782 — pg_query/pg_fetch_assoc/pg_fetch_row/pg_close Reflection matches Zend stubs
 * (ext/pgsql/pgsql.stub.php). Requires PHP_COMPILER_ENABLE_PGSQL=1 when host lacks ext/pgsql.
 */
declare(strict_types=1);

foreach (['pg_query', 'pg_fetch_assoc', 'pg_fetch_row', 'pg_close'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo $fn, ' ', $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}

try {
    pg_query(connection: null, query: 'SELECT 1');
    echo "named_query_ok\n";
} catch (Throwable $e) {
    echo 'named_query: ', get_class($e), "\n";
}

try {
    pg_fetch_assoc(result: null, row: null);
    echo "named_fetch_ok\n";
} catch (Throwable $e) {
    echo 'named_fetch: ', get_class($e), "\n";
}

try {
    pg_fetch_row(result: null, row: null, mode: 2);
    echo "named_row_ok\n";
} catch (Throwable $e) {
    echo 'named_row: ', get_class($e), "\n";
}

try {
    pg_close(connection: null);
    echo "named_close_ok\n";
} catch (Throwable $e) {
    echo 'named_close: ', get_class($e), "\n";
}

try {
    pg_fetch_row(result_type: 2);
} catch (Throwable $e) {
    echo 'legacy_result_type: ', get_class($e), "\n";
}
