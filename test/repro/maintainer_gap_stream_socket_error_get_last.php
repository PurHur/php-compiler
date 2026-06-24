<?php
declare(strict_types=1);

error_clear_last();
$errno = 0;
$errstr = '';
@stream_socket_client('tcp://127.0.0.1:1', $errno, $errstr, 1);
$last = error_get_last();
echo $errno === 111 ? "errno_ok\n" : "errno_bad\n";
echo is_array($last) && str_contains($last['message'], 'Unable to connect') ? "last_ok\n" : "last_bad\n";
