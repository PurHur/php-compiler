--TEST--
stdlib bz2 — not advertised when libbz2 unavailable (#11840)
--SKIPIF--
<?php
if (\PHPCompiler\ext\bz2\VmBz2Native::available()) {
    die('skip libbz2 available on this host');
}
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('bz2') ? "fail\n" : "ok\n";
--EXPECT--
ok
