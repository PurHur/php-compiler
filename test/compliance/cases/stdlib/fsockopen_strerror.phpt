--TEST--
stdlib fsockopen()/stream_socket_client() errstr uses strerror text (#9320)
--FILE--
<?php
declare(strict_types=1);

$errno = 0;
$errstr = '';
@fsockopen('127.0.0.1', 9, $errno, $errstr, 1);
echo is_int($errno) && 0 !== $errno ? "fsock_errno\n" : "fsock_errno_bad\n";
echo str_contains($errstr, 'Connection refused') ? "fsock_errstr\n" : "fsock_errstr_bad\n";

$errno = 0;
$errstr = '';
@stream_socket_client('tcp://127.0.0.1:9', $errno, $errstr, 1);
echo is_int($errno) && 0 !== $errno ? "stream_errno\n" : "stream_errno_bad\n";
echo str_contains($errstr, 'Connection refused') ? "stream_errstr\n" : "stream_errstr_bad\n";
--EXPECT--
fsock_errno
fsock_errstr
stream_errno
stream_errstr
