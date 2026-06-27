--TEST--
stdlib stream_wrapper_restore() — noop on unchanged built-in returns true (#12621)
--FILE--
<?php
var_export(stream_wrapper_restore('http'));
echo "\n";
$wrappers = stream_get_wrappers();
var_export(is_array($wrappers) && in_array('http', $wrappers, true));
echo "\n";
--EXPECTF--
PHP Notice:  stream_wrapper_restore(): "http" was never changed, nothing to restore in %s on line %d
true
true
