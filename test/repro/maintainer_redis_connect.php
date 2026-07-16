<?php
/**
 * Repro for #6098 — Redis connect/get/set (PECL phpredis subset).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_redis_connect.php
 */
if (!class_exists('Redis')) {
    fwrite(STDERR, "class Redis missing\n");
    exit(1);
}

$r = new Redis();
try {
    $r->connect('127.0.0.1', 1, 0.25);
    fwrite(STDERR, "expected RedisException on closed port\n");
    exit(1);
} catch (RedisException $e) {
    echo "connect_fail: ok\n";
}

$fp = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.25);
if (false === $fp) {
    echo "skip_connect\n";
    exit(0);
}
fclose($fp);

$r2 = new Redis();
if (!$r2->connect('127.0.0.1', 6379, 1.0)) {
    fwrite(STDERR, "connect failed\n");
    exit(1);
}
$key = 'phpc_redis_6098_probe';
$r2->set($key, 'v');
$got = $r2->get($key);
if ('v' !== $got) {
    fwrite(STDERR, 'get mismatch: '.var_export($got, true)."\n");
    exit(1);
}
echo "roundtrip: ok\n";
