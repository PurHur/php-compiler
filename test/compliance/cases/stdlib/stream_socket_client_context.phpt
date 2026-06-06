--TEST--
Stdlib: stream_socket_client() with stream_context — no refcount fatal (#6815)
--FILE--
<?php
$ctx = stream_context_create(['socket' => ['connect_timeout' => 1]]);
$result = @stream_socket_client('tcp://127.0.0.1:9', $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx);
var_export($result);
echo "\n";
echo is_int($errno) ? 'errno' : 'no_errno', "\n";
--EXPECT--
false
errno
