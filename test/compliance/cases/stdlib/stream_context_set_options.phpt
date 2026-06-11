--TEST--
stdlib stream_context_set_options / get_options (#6517)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export(function_exists('stream_context_set_options'));
echo "\n";
var_export(stream_context_set_options($ctx, ['http' => ['follow_location' => 0, 'timeout' => 10]]));
echo "\n";
$opts = stream_context_get_options($ctx);
var_export($opts['http']['timeout']);
echo "\n";
var_export($opts['http']['follow_location']);
echo "\n";
--EXPECT--
true
true
10
0
