--TEST--
AOT: openssl_error_string() empty queue is false (#32336)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslSignNative.php';
if (!\PHPCompiler\ext\openssl\VmOpensslSignNative::available()) {
    echo 'skip';
}
?>
--FILE--
<?php
declare(strict_types=1);

var_export(openssl_error_string());
echo "\n";
--EXPECT--
false
