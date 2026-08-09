--TEST--
stdlib xmlrpc_server_create/register/call/destroy (#27879, php-src xmlrpc-epi-php.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'xmlrpc_server_create',
    'xmlrpc_server_destroy',
    'xmlrpc_server_register_method',
    'xmlrpc_server_call_method',
    'xmlrpc_parse_method_descriptions',
    'xmlrpc_server_add_introspection_data',
    'xmlrpc_server_register_introspection_callback',
] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

$s = xmlrpc_server_create();
echo 'resource=', is_resource($s) ? 'Y' : 'N', "\n";
xmlrpc_server_register_method($s, 'add', function ($method, $params) {
    return $params[0] + $params[1];
});
$req = xmlrpc_encode_request('add', [2, 3]);
$out = xmlrpc_server_call_method($s, $req, null);
$method = '';
$decoded = xmlrpc_decode_request($out, $method);
echo 'sum=', (string) $decoded, "\n";
echo 'destroy=', xmlrpc_server_destroy($s) ? 'Y' : 'N', "\n";
echo 'closed=', is_resource($s) ? 'Y' : 'N', "\n";
echo 'parse=', is_array(xmlrpc_parse_method_descriptions(xmlrpc_encode([1, 2]))) ? 'Y' : 'N', "\n";
--EXPECT--
xmlrpc_server_create=Y
xmlrpc_server_destroy=Y
xmlrpc_server_register_method=Y
xmlrpc_server_call_method=Y
xmlrpc_parse_method_descriptions=Y
xmlrpc_server_add_introspection_data=Y
xmlrpc_server_register_introspection_callback=Y
resource=Y
sum=5
destroy=Y
closed=N
parse=Y
