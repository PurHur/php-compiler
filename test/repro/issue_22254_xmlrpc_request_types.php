<?php
// Issue #22254 — xmlrpc request/type helpers on forward profile.
foreach (['xmlrpc_encode', 'xmlrpc_encode_request', 'xmlrpc_decode_request', 'xmlrpc_is_fault', 'xmlrpc_get_type', 'xmlrpc_set_type'] as $f) {
    echo $f, '=', var_export(function_exists($f), true), PHP_EOL;
}
if (!function_exists('xmlrpc_encode_request')) {
    exit(0);
}
$req = xmlrpc_encode_request('demo.add', [1, 2]);
$method = '';
$params = xmlrpc_decode_request($req, $method);
echo 'method=', $method, PHP_EOL;
echo 'params_ok=', ($params === [1, 2]) ? '1' : '0', PHP_EOL;
echo 'is_fault=', xmlrpc_is_fault(['faultCode' => 1, 'faultString' => 'x']) ? '1' : '0', PHP_EOL;
