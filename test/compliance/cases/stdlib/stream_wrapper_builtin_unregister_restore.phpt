--TEST--
stdlib stream_wrapper_unregister()/restore() — built-in scheme parity (#12620, #12621)
--FILE--
<?php
var_export(stream_wrapper_unregister('http'));
echo "\n";
$wrappers = stream_get_wrappers();
var_export(is_array($wrappers) && in_array('http', $wrappers, true));
echo "\n";
var_export(stream_wrapper_restore('http'));
echo "\n";
$wrappers = stream_get_wrappers();
var_export(is_array($wrappers) && in_array('http', $wrappers, true));
echo "\n";
--EXPECTF--
true
false
true
true
