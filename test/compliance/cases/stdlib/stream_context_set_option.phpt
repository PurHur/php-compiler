--TEST--
stdlib stream_context_set_option / get_params (#3448)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export(function_exists('stream_context_set_option'));
echo "\n";
var_export(function_exists('stream_context_get_params'));
echo "\n";
var_export(stream_context_set_option($ctx, 'http', 'user_agent', 'phpc-test'));
echo "\n";
$opts = stream_context_get_options($ctx);
var_export($opts['http']['user_agent'] ?? null);
echo "\n";
$params = stream_context_get_params($ctx);
var_export(array_key_exists('options', $params));
echo "\n";
stream_context_set_default(['http' => ['timeout' => 3]]);
$default = stream_context_get_default();
$defOpts = stream_context_get_options($default);
var_export($defOpts['http']['timeout'] ?? null);
echo "\n";
?>
--EXPECT--
true
true
true
'phpc-test'
true
3
