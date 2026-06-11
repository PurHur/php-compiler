--TEST--
stdlib stream_get_wrappers() / stream_get_transports() — registered protocol and transport lists (#3329)
--FILE--
<?php
var_export(function_exists('stream_get_wrappers'));
echo "\n";
var_export(function_exists('stream_get_transports'));
echo "\n";
var_export(in_array('file', stream_get_wrappers(), true));
echo "\n";
var_export(in_array('php', stream_get_wrappers(), true));
echo "\n";
var_export(in_array('tcp', stream_get_transports(), true));
echo "\n";
var_export(in_array('udp', stream_get_transports(), true));
echo "\n";
var_export(in_array('unix', stream_get_transports(), true));
echo "\n";
--EXPECT--
true
true
true
true
true
true
true
