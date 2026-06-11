<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export(function_exists('stream_context_set_options'));
echo "\n";
stream_context_set_options($ctx, ['http' => ['follow_location' => 0, 'timeout' => 10]]);
$opts = stream_context_get_options($ctx);
var_export($opts['http']['timeout']);
echo "\n";
var_export($opts['http']['follow_location']);
echo "\n";
