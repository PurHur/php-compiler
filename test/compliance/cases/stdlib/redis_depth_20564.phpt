--TEST--
stdlib Redis del/exists/ping/hash methods advertised (#20564, PECL phpredis)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsRedis()) {
    die('skip redis withheld on reference profile (#20564)');
}
--FILE--
<?php
declare(strict_types=1);
$r = new Redis();
foreach (['del','exists','ping','auth','select','isConnected','hGet','hSet','hGetAll'] as $m) {
    echo $m, '=', method_exists($r, $m) ? '1' : '0', "\n";
}
try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}
$key = 'phpc_20564_'.bin2hex(random_bytes(3));
$r->hSet($key, 'f', 'v');
echo 'h=', $r->hGet($key, 'f'), "\n";
echo 'e=', $r->exists($key), "\n";
echo 'd=', $r->del($key), "\n";
$r->close();
echo "live=ok\n";
?>
--EXPECTF--
del=1
exists=1
ping=1
auth=1
select=1
isConnected=1
hGet=1
hSet=1
hGetAll=1
%s
