--TEST--
AOT: stream_context_set_option four-arg + get_options (#27295)
--FILE--
<?php
$ctx = stream_context_create();
$ok = stream_context_set_option($ctx, 'http', 'method', 'POST');
var_export($ok);
echo "\n";
echo stream_context_get_options($ctx)['http']['method'], "\n";
--EXPECT--
true
POST
