--TEST--
stdlib Redis set/get round-trip when redis-server available (#6098)
--FILE--
<?php
declare(strict_types=1);

$r = new Redis();
try {
    $r->connect('127.0.0.1', 6379, 0.5);
} catch (RedisException $e) {
    echo "skip_connect\n";
    return;
}
$key = 'phpc_redis_6098_'.bin2hex(random_bytes(4));
$r->set($key, 'v');
echo $r->get($key), "\n";
$r->close();
?>
--EXPECTF--
%s
