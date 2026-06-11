--TEST--
stdlib stream_context_set_params (#6122, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

var_export(function_exists('stream_context_set_params'));
echo "\n";
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export(stream_context_set_params($ctx, ['notification' => null]));
echo "\n";
var_export(stream_context_set_params($ctx, ['source' => 'unit-test']));
echo "\n";
--EXPECT--
true
true
true
