--TEST--
stdlib xmlrpc_encode/xmlrpc_decode round-trip (#6579, ext/xmlrpc/xmlrpc.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsXmlrpc()) {
    die('skip xmlrpc withheld on reference profile (#18503)');
}
--FILE--
<?php
declare(strict_types=1);

$xml = xmlrpc_encode(['method' => 'demo.add', 'params' => [1, 2]]);
$val = xmlrpc_decode($xml);
echo is_array($val) ? '1' : '0';
echo ($val['method'] ?? '') === 'demo.add' ? '1' : '0';
echo isset($val['params']) && $val['params'] === [1, 2] ? '1' : '0';
echo xmlrpc_decode('<broken') === false ? '1' : '0';
echo function_exists('xmlrpc_encode') ? '1' : '0';
echo function_exists('xmlrpc_decode') ? '1' : '0';
echo extension_loaded('xmlrpc') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111111
