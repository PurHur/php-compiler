<?php

declare(strict_types=1);

$errno = 0;
$errstr = '';
$result = @stream_socket_client(address: 'tcp://127.0.0.1:1', timeout: 1, error_code: $errno, error_message: $errstr);
echo 'connect_ok=', false === $result ? '0' : '1', "\n";
echo 'errno=', $errno, "\n";
