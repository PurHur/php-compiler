--TEST--
stdlib stream_context_create() inline nested options array (#4738)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
echo $ctx['http']['timeout'], "\n";
echo array_key_exists('__phpc_stream_context', $ctx) ? "marker\n" : "no_marker\n";
--EXPECT--
5
marker
