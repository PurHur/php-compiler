--TEST--
stream_context_create([]) after @mkdir — empty literal stays array (#16205, ext/standard/streams.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_stream_ctx_' . getmypid();
@mkdir($dir);
$ctx = stream_context_create([]);
echo is_resource($ctx) ? "ok\n" : "fail\n";
--EXPECT--
ok
