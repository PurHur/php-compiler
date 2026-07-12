<?php

// Issue #6579 — xmlrpc_encode()/xmlrpc_decode() round-trip (ext/xmlrpc/xmlrpc.c).
$xml = xmlrpc_encode(['method' => 'demo.add', 'params' => [1, 2]]);
$val = xmlrpc_decode($xml);
echo var_export(is_array($val), true), "\n";
echo $val['method'] ?? 'missing_method', "\n";
echo isset($val['params']) && $val['params'] === [1, 2] ? "params_ok\n" : "params_bad\n";

$xml2 = xmlrpc_encode(42);
$val2 = xmlrpc_decode($xml2);
echo var_export($val2, true), "\n";
echo xmlrpc_decode('<not-xml') === false ? "invalid_false\n" : "invalid_bad\n";
echo function_exists('xmlrpc_encode') && function_exists('xmlrpc_decode') ? "funcs_ok\n" : "funcs_bad\n";
echo extension_loaded('xmlrpc') ? "ext_ok\n" : "ext_bad\n";
