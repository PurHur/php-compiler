--TEST--
stdlib Redis class_exists + connect failure RedisException (#6098, PECL phpredis)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsRedis()) {
    die('skip redis withheld on reference profile (#6098)');
}
--FILE--
<?php
declare(strict_types=1);

echo class_exists('Redis') ? '1' : '0';
echo class_exists('RedisException') ? '1' : '0';
echo extension_loaded('redis') ? '1' : '0';
echo "\n";

$r = new Redis();
try {
    $r->connect('127.0.0.1', 1, 0.25);
    echo "unexpected_ok\n";
} catch (RedisException $e) {
    echo "ex\n";
}
?>
--EXPECT--
111
ex
