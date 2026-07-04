--TEST--
stdlib stream_context_create() nested wrapper options round-trip (#15993)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$opts = stream_context_get_options($ctx);
echo $opts['http']['timeout'], "\n";
echo array_key_exists('__phpc_stream_context', $ctx) ? "marker\n" : "no_marker\n";
--EXPECT--
5
marker
