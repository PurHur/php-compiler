--TEST--
stdlib stream_get_transports() — tlsv1.0–tlsv1.3 OpenSSL aliases (#11198)
--FILE--
<?php
$transports = stream_get_transports();
sort($transports);
var_export(count($transports));
echo "\n";
var_export(in_array('tlsv1.0', $transports, true));
echo "\n";
var_export(in_array('tlsv1.1', $transports, true));
echo "\n";
var_export(in_array('tlsv1.2', $transports, true));
echo "\n";
var_export(in_array('tlsv1.3', $transports, true));
echo "\n";
--EXPECT--
10
true
true
true
true
