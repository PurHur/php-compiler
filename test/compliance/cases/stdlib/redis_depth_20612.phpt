--TEST--
stdlib Redis list/set/zset/multi/pipeline/eval methods advertised (#20612, PECL phpredis)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsRedis()) {
    die('skip redis withheld on reference profile (#20612)');
}
--FILE--
<?php
declare(strict_types=1);
$r = new Redis();
foreach ([
    'lPush','lPop','rPush','rPop','lRange',
    'sAdd','sRem','sMembers','sIsMember',
    'zAdd','zRange','zRem',
    'multi','exec','pipeline','eval',
    'expire','ttl','incr','decr','keys','mget','mset',
] as $m) {
    echo $m, '=', method_exists($r, $m) ? '1' : '0', "\n";
}
echo 'MULTI=', (new ReflectionClass('Redis'))->hasConstant('MULTI') ? '1' : '0', "\n";
echo 'PIPELINE=', (new ReflectionClass('Redis'))->hasConstant('PIPELINE') ? '1' : '0', "\n";
echo 'RedisMULTI=', (string) Redis::MULTI, "\n";
echo 'RedisPIPELINE=', (string) Redis::PIPELINE, "\n";
try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}
$key = 'phpc_20612_'.bin2hex(random_bytes(3));
$r->lPush($key, 'v');
$r->sAdd($key.':s', 'm');
$r->zAdd($key.':z', 1.5, 'z');
$r->set($key.':c', '1');
$r->incr($key.':c');
$r->multi();
$r->set($key.':t', '1');
$r->get($key.':t');
$out = $r->exec();
echo 'multi=', is_array($out) && count($out) === 2 ? '1' : '0', "\n";
$r->del($key, $key.':s', $key.':z', $key.':c', $key.':t');
$r->close();
echo "live=ok\n";
?>
--EXPECTF--
lPush=1
lPop=1
rPush=1
rPop=1
lRange=1
sAdd=1
sRem=1
sMembers=1
sIsMember=1
zAdd=1
zRange=1
zRem=1
multi=1
exec=1
pipeline=1
eval=1
expire=1
ttl=1
incr=1
decr=1
keys=1
mget=1
mset=1
MULTI=1
PIPELINE=1
RedisMULTI=1
RedisPIPELINE=2
%s
