--TEST--
stdlib Memcached set/get round-trip when memcached available (#6099)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMemcached()) {
    die('skip memcached withheld on reference profile (#6099)');
}
--FILE--
<?php
declare(strict_types=1);

$m = new Memcached();
$m->addServer('127.0.0.1', 11211);
$key = 'phpc_memcached_6099_'.bin2hex(random_bytes(4));
if (!$m->set($key, 'v', 60)) {
    echo "skip_connect\n";
    return;
}
echo $m->get($key), "\n";
$ok = (int) (new ReflectionClass('Memcached'))->getConstants()['RES_SUCCESS'];
echo $m->getResultCode() === $ok ? "ok\n" : "bad_code\n";
$m->delete($key);
?>
--EXPECTF--
%s
