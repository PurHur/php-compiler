--TEST--
stdlib stream_context_set_options JIT (#6517)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
stream_context_set_options($ctx, ['http' => ['timeout' => 10]]);
$opts = stream_context_get_options($ctx);
echo $opts['http']['timeout'], "\n";
--EXPECT--
10
