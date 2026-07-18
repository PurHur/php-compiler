<?php
/**
 * Repro #20612 — Redis list/set/zset/multi/pipeline/eval + companions after #20564.
 */
$r = new Redis();
$methods = [
    'lPush', 'lPop', 'rPush', 'rPop', 'lRange',
    'sAdd', 'sRem', 'sMembers', 'sIsMember',
    'zAdd', 'zRange', 'zRem',
    'multi', 'exec', 'pipeline', 'eval',
    'expire', 'ttl', 'incr', 'decr', 'keys', 'mget', 'mset',
];
foreach ($methods as $m) {
    echo $m, '=', method_exists($r, $m) ? '1' : '0', "\n";
}
echo 'MULTI=', (new ReflectionClass(Redis::class))->hasConstant('MULTI') ? '1' : '0', "\n";
echo 'PIPELINE=', (new ReflectionClass(Redis::class))->hasConstant('PIPELINE') ? '1' : '0', "\n";
echo 'RedisMULTI=', (string) Redis::MULTI, "\n";
echo 'RedisPIPELINE=', (string) Redis::PIPELINE, "\n";

try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}

$pfx = 'phpc_20612_'.bin2hex(random_bytes(3));
$list = $pfx.':list';
$set = $pfx.':set';
$zset = $pfx.':z';
$ctr = $pfx.':c';

$r->lPush($list, 'a', 'b');
$r->rPush($list, 'c');
$range = $r->lRange($list, 0, -1);
echo 'list_n=', is_array($range) ? count($range) : 0, "\n";
echo 'lpop=', (string) $r->lPop($list), "\n";

$r->sAdd($set, 'x', 'y');
echo 'sism=', $r->sIsMember($set, 'x') ? '1' : '0', "\n";
$members = $r->sMembers($set);
echo 'smembers_n=', is_array($members) ? count($members) : 0, "\n";
$r->sRem($set, 'x');

$r->zAdd($zset, 1.0, 'one', 2.0, 'two');
$zr = $r->zRange($zset, 0, -1);
echo 'zrange_n=', is_array($zr) ? count($zr) : 0, "\n";
$r->zRem($zset, 'one');

$r->set($ctr, '10');
echo 'incr=', (string) $r->incr($ctr), "\n";
echo 'decr=', (string) $r->decr($ctr), "\n";
$r->expire($ctr, 30);
echo 'ttl_pos=', $r->ttl($ctr) > 0 ? '1' : '0', "\n";

$r->mset([$pfx.':a' => '1', $pfx.':b' => '2']);
$mg = $r->mget([$pfx.':a', $pfx.':b', $pfx.':missing']);
echo 'mget_n=', is_array($mg) ? count($mg) : 0, "\n";

$ev = $r->eval('return redis.call("PING")', [], 0);
echo 'eval=', is_string($ev) ? $ev : (true === $ev ? 'PONG' : 'x'), "\n";

$r->multi();
$r->set($pfx.':m', 'v');
$r->get($pfx.':m');
$exec = $r->exec();
echo 'multi_n=', is_array($exec) ? count($exec) : 0, "\n";

$r->pipeline();
$r->set($pfx.':p', 'q');
$r->get($pfx.':p');
$pipe = $r->exec();
echo 'pipe_n=', is_array($pipe) ? count($pipe) : 0, "\n";

$r->del($list, $set, $zset, $ctr, $pfx.':a', $pfx.':b', $pfx.':m', $pfx.':p');
$r->close();
echo "live=ok\n";
