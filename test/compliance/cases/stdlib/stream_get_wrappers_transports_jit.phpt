--TEST--
stdlib stream_get_wrappers() / stream_get_transports() — JIT lowering (#3329)
--FILE--
<?php
var_export(in_array('file', stream_get_wrappers(), true));
echo "\n";
var_export(in_array('tcp', stream_get_transports(), true));
echo "\n";
--EXPECT--
true
true
