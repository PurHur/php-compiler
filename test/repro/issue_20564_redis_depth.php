<?php
/**
 * Repro #20564 — Redis depth methods after #6098 (method surface; live round-trip when redis-server up).
 */
$r = new Redis();
foreach (['connect', 'get', 'set', 'close', 'del', 'exists', 'hGet', 'hSet', 'hGetAll', 'multi', 'eval', 'auth', 'ping', 'select', 'isConnected'] as $m) {
    echo $m, ': ', method_exists($r, $m) ? 'yes' : 'NO', PHP_EOL;
}
try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}
$key = 'phpc_20564_'.bin2hex(random_bytes(3));
$r->hSet($key, 'a', '1');
$r->hSet($key, 'b', '2');
echo 'hGet=', $r->hGet($key, 'a'), PHP_EOL;
$all = $r->hGetAll($key);
echo 'hGetAll_n=', is_array($all) ? count($all) : 0, PHP_EOL;
echo 'exists=', (string) $r->exists($key), PHP_EOL;
echo 'ping=', $r->ping() === true ? '1' : '0', PHP_EOL;
echo 'del=', (string) $r->del($key), PHP_EOL;
echo 'isConnected=', $r->isConnected() ? '1' : '0', PHP_EOL;
$r->close();
echo "live=ok\n";
