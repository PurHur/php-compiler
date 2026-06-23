<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
var_export($ctx);
echo "\n";
var_export(is_resource($ctx));
echo "\n";
$opts = stream_context_get_options($ctx);
var_export(isset($opts['http']['timeout']) && 5 === $opts['http']['timeout']);
echo "\n";
