--TEST--
stdlib xmlrpc withheld on reference profile — extension_loaded false (#18503, ext/xmlrpc/xmlrpc.c)
--SKIPIF--
<?php
$raw = getenv('PHP_COMPILER_PROFILE');
if (\is_string($raw) && '' !== trim($raw) && version_compare(trim($raw).'.0', '8.4.0', '>=')) {
    die('skip forward profile enables xmlrpc');
}
--FILE--
<?php
declare(strict_types=1);

$phantom = extension_loaded('xmlrpc')
    || function_exists('xmlrpc_encode')
    || function_exists('xmlrpc_decode');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
