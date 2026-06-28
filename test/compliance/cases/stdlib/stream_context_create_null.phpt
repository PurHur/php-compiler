--TEST--
stdlib stream_context_create(null) — default empty context (#13356, ext/standard/streams.c)
--FILE--
<?php
$ctx = stream_context_create(null);
echo is_array($ctx) || is_resource($ctx) ? "ok\n" : "fail\n";
--EXPECT--
ok
