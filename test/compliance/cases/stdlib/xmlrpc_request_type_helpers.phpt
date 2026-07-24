--TEST--
stdlib xmlrpc encode_request/decode_request/is_fault/get_type/set_type (#22254, ext/xmlrpc)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsXmlrpc()) {
    die('skip xmlrpc withheld on reference profile (#18503)');
}
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['xmlrpc_encode_request', 'xmlrpc_decode_request', 'xmlrpc_is_fault', 'xmlrpc_get_type', 'xmlrpc_set_type'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$req = xmlrpc_encode_request('demo.add', [1, 2]);
$method = '';
$params = xmlrpc_decode_request($req, $method);
echo 'method=', $method, "\n";
echo 'params=', ($params === [1, 2]) ? 'ok' : 'fail', "\n";

$fault = ['faultCode' => 1, 'faultString' => 'boom'];
echo 'is_fault=', xmlrpc_is_fault($fault) ? '1' : '0', "\n";
echo 'not_fault=', xmlrpc_is_fault(['x' => 1]) ? '1' : '0', "\n";

echo 'type_int=', xmlrpc_get_type(42), "\n";
echo 'type_arr=', xmlrpc_get_type([1, 2]), "\n";
echo 'type_struct=', xmlrpc_get_type(['a' => 1]), "\n";

$bin = 'hi';
echo 'set=', xmlrpc_set_type($bin, 'base64') ? '1' : '0', "\n";
echo 'type_b64=', xmlrpc_get_type($bin), "\n";
$enc = xmlrpc_encode($bin);
echo 'b64_xml=', str_contains($enc, '<base64>') ? '1' : '0', "\n";
--EXPECT--
xmlrpc_encode_request=1
xmlrpc_decode_request=1
xmlrpc_is_fault=1
xmlrpc_get_type=1
xmlrpc_set_type=1
method=demo.add
params=ok
is_fault=1
not_fault=0
type_int=int
type_arr=array
type_struct=struct
set=1
type_b64=base64
b64_xml=1
