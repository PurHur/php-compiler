<?php

declare(strict_types=1);

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_dump(function_exists('stream_context_set_option'));
var_dump(function_exists('stream_context_get_params'));
stream_context_set_option($ctx, 'http', 'user_agent', 'phpc-test');
$opts = stream_context_get_options($ctx);
var_export($opts['http']['user_agent'] ?? null);
echo "\n";
$params = stream_context_get_params($ctx);
echo in_array('options', array_keys($params), true) ? "params_ok\n" : "params_fail\n";

stream_context_set_default(['http' => ['timeout' => 3]]);
$default = stream_context_get_default();
$defOpts = stream_context_get_options($default);
var_export($defOpts['http']['timeout'] ?? null);
echo "\n";
