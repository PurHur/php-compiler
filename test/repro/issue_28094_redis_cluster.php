<?php
declare(strict_types=1);

/**
 * Repro for #28094 — RedisCluster / RedisArray / RedisClusterException when redis advertised.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28094_redis_cluster.php
 *
 * Live Redis optional via PHP_COMPILER_REDIS_HOST; offline path asserts class/method surface.
 */
echo 'ext=', extension_loaded('redis') ? '1' : '0', "\n";
foreach (['Redis', 'RedisCluster', 'RedisArray', 'RedisException', 'RedisClusterException'] as $c) {
    echo $c, '=', class_exists($c) ? '1' : '0', "\n";
}
foreach (['get', 'set', 'del'] as $m) {
    echo 'RedisCluster::', $m, '=', method_exists('RedisCluster', $m) ? '1' : '0', "\n";
    echo 'RedisArray::', $m, '=', method_exists('RedisArray', $m) ? '1' : '0', "\n";
}
echo 'parent=', (new ReflectionClass('RedisClusterException'))->getParentClass()->getName(), "\n";

$host = getenv('PHP_COMPILER_REDIS_HOST');
if (false === $host || '' === $host) {
    echo "live_skip=1\n";
    exit(0);
}

$port = (int) (getenv('PHP_COMPILER_REDIS_PORT') ?: 6379);
$seed = $host.':'.$port;
$key = 'phpc_28094_'.bin2hex(random_bytes(3));

$c = new RedisCluster(null, [$seed], 2.0);
echo 'c_set=', $c->set($key, 'v') ? '1' : '0', "\n";
echo 'c_get=', $c->get($key) === 'v' ? '1' : '0', "\n";
echo 'c_del=', $c->del($key) === 1 ? '1' : '0', "\n";
$c->close();

$a = new RedisArray([$seed]);
echo 'a_hosts=', count($a->_hosts()) >= 1 ? '1' : '0', "\n";
echo 'a_set=', $a->set($key, 'w') ? '1' : '0', "\n";
echo 'a_get=', $a->get($key) === 'w' ? '1' : '0', "\n";
echo 'a_del=', $a->del($key) === 1 ? '1' : '0', "\n";
