<?php
$ctx = stream_context_create(['socket' => ['connect_timeout' => 1]]);
var_export(@stream_socket_client('tcp://127.0.0.1:9', $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx));
echo "\n";
