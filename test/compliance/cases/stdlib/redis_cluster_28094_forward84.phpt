--TEST--
stdlib RedisCluster/RedisArray/RedisClusterException advertised (#28094, PECL phpredis)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo 'ext=', extension_loaded('redis') ? '1' : '0', "\n";
foreach (['Redis', 'RedisCluster', 'RedisArray', 'RedisException', 'RedisClusterException'] as $c) {
    echo $c, '=', class_exists($c) ? '1' : '0', "\n";
}
foreach (['get', 'set', 'del'] as $m) {
    echo 'c_', $m, '=', method_exists('RedisCluster', $m) ? '1' : '0', "\n";
    echo 'a_', $m, '=', method_exists('RedisArray', $m) ? '1' : '0', "\n";
}
echo 'parent=', (new ReflectionClass('RedisClusterException'))->getParentClass()->getName(), "\n";
$a = new RedisArray(['127.0.0.1:6379', '127.0.0.1:6380']);
$hosts = $a->_hosts();
echo 'hosts=', is_array($hosts) && count($hosts) === 2 ? '1' : '0', "\n";
$t = $a->_target('k');
echo 'target=', is_string($t) && str_contains($t, '127.0.0.1:') ? '1' : '0', "\n";
try {
    $c = new RedisCluster(null, ['127.0.0.1:6379'], 0.25);
    $c->set('phpc_28094_probe', '1');
    $c->del('phpc_28094_probe');
    $c->close();
    echo "live=ok\n";
} catch (RedisClusterException $e) {
    echo "live=skip\n";
}
?>
--EXPECTF--
ext=1
Redis=1
RedisCluster=1
RedisArray=1
RedisException=1
RedisClusterException=1
c_get=1
a_get=1
c_set=1
a_set=1
c_del=1
a_del=1
parent=RuntimeException
hosts=1
target=1
%s
