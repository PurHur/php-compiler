<?php
declare(strict_types=1);

/**
 * Repro for #28116 — Redis connection introspection accessors.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28116_redis_introspection.php
 *
 * Live connect is optional (PHP_COMPILER_REDIS_HOST); offline path still asserts method_exists
 * and disconnected getHost/getPort/getMode/getLastError behaviour.
 */
echo 'Redis=', class_exists('Redis') ? '1' : '0', "\n";
foreach ([
    'isConnected', 'getHost', 'getPort', 'getDBNum', 'getTimeout', 'getReadTimeout',
    'getPersistentID', 'getAuth', 'getLastError', 'clearLastError', 'getMode',
] as $m) {
    echo $m, '=', method_exists('Redis', $m) ? '1' : '0', "\n";
}

$r = new Redis();
echo 'disc_host=', var_export($r->getHost(), true), "\n";
echo 'disc_port=', var_export($r->getPort(), true), "\n";
echo 'disc_db=', var_export($r->getDBNum(), true), "\n";
echo 'disc_mode=', var_export($r->getMode(), true), "\n";
echo 'disc_auth=', var_export($r->getAuth(), true), "\n";
echo 'disc_err=', var_export($r->getLastError(), true), "\n";
echo 'clear=', var_export($r->clearLastError(), true), "\n";

$host = getenv('PHP_COMPILER_REDIS_HOST');
if (false !== $host && '' !== $host) {
    $port = (int) (getenv('PHP_COMPILER_REDIS_PORT') ?: 6379);
    $r->connect($host, $port, 1.0);
    echo 'conn_host=', $r->getHost(), "\n";
    echo 'conn_port=', (string) $r->getPort(), "\n";
    echo 'conn_timeout=', (string) $r->getTimeout(), "\n";
    echo 'conn_persistent=', var_export($r->getPersistentID(), true), "\n";
    $r->select(0);
    echo 'conn_db=', (string) $r->getDBNum(), "\n";
} else {
    echo "live_skip=1\n";
}
