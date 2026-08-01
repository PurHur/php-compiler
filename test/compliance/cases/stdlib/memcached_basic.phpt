--TEST--
stdlib Memcached class_exists + addServer + getResultCode (#6099, PECL php-memcached)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMemcached()) {
    die('skip memcached withheld on reference profile (#6099)');
}
--FILE--
<?php
declare(strict_types=1);

echo class_exists('Memcached') ? '1' : '0';
echo extension_loaded('memcached') ? '1' : '0';
echo "\n";

$consts = (new ReflectionClass('Memcached'))->getConstants();
$resSuccess = (int) $consts['RES_SUCCESS'];
$resConnFail = (int) $consts['RES_CONNECTION_FAILURE'];

$m = new Memcached();
echo $m->addServer('127.0.0.1', 1) ? '1' : '0';
echo $m->getResultCode() === $resSuccess ? '1' : '0';
echo "\n";

$got = $m->get('missing_key_6099');
echo false === $got ? '1' : '0';
echo $m->getResultCode() === $resConnFail ? '1' : '0';
echo "\n";
echo $resSuccess === 0 ? '1' : '0';
echo isset($consts['OPT_COMPRESSION']) ? '1' : '0';
?>
--EXPECT--
11
11
11
11
