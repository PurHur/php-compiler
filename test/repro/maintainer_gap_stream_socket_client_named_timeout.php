<?php

declare(strict_types=1);

/** Issue #11576 — stream_socket_client() named timeout: must compile and populate errno on refused connect. */

$errno = 0;
$errstr = '';
$result = @stream_socket_client(address: 'tcp://127.0.0.1:1', timeout: 1, error_code: $errno, error_message: $errstr);
echo 'connect_ok=', false === $result ? '0' : '1', "\n";
echo 'errno=', $errno, "\n";
