<?php

/**
 * #27811 — pg_connect()/pg_pconnect() Reflection: connection_string/flags → PgSql\Connection|false
 * (ext/pgsql/pgsql.stub.php). Requires PHP_COMPILER_ENABLE_PGSQL=1 when host lacks ext/pgsql.
 */
declare(strict_types=1);

foreach (['pg_connect', 'pg_pconnect'] as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo $fn, ' ', $p->getName(), ':', $t ? (string) $t : '?', $p->isOptional() ? ' opt' : '', "\n";
    }
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
}

try {
    pg_connect(connection_string: 'host=127.0.0.1 port=1 connect_timeout=1', flags: 0);
    echo "named_ok\n";
} catch (Throwable $e) {
    // Failed connect still returns false under @ or throws — either proves named resolve.
    echo 'named: ', get_class($e), "\n";
}

try {
    pg_connect(connect_type: 0);
} catch (Throwable $e) {
    echo 'legacy: ', get_class($e), "\n";
}
