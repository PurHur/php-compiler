--TEST--
stdlib Redis pub/sub + SCAN + streams + companions advertised (#20682, PECL phpredis)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsRedis()) {
    die('skip redis withheld on reference profile (#20682)');
}
--FILE--
<?php
declare(strict_types=1);
$r = new Redis();
foreach ([
    'publish','subscribe','psubscribe',
    'scan','hScan','sScan','zScan',
    'xAdd','xRead','xGroup',
    'pconnect','rawCommand','setEx','setNx',
    'blPop','brPop','info','flushAll','watch','unwatch',
] as $m) {
    echo $m, '=', method_exists($r, $m) ? '1' : '0', "\n";
}
try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}
$key = 'phpc_20682_'.bin2hex(random_bytes(3));
$r->setEx($key, 30, 'v');
$r->setNx($key.':nx', '1');
$r->rPush($key.':l', 'a');
$bp = $r->blPop([$key.':l'], 1);
echo 'blpop=', is_array($bp) ? '1' : '0', "\n";
$r->publish($key.':ch', 'm');
$it = null;
$r->scan($it, $key.'*', 5);
$r->hSet($key.':h', 'f', '1');
$hit = null;
$r->hScan($key.':h', $hit);
$r->xAdd($key.':s', '*', ['a' => 'b']);
$r->xRead([$key.':s' => '0-0'], 1);
$r->info('server');
$r->rawCommand('PING');
$r->watch($key);
$r->unwatch();
$r->del($key, $key.':nx', $key.':l', $key.':h', $key.':s');
$r->close();
echo "live=ok\n";
?>
--EXPECTF--
publish=1
subscribe=1
psubscribe=1
scan=1
hScan=1
sScan=1
zScan=1
xAdd=1
xRead=1
xGroup=1
pconnect=1
rawCommand=1
setEx=1
setNx=1
blPop=1
brPop=1
info=1
flushAll=1
watch=1
unwatch=1
%s
