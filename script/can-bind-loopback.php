#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exit 0 when the process can bind a TCP listener on 127.0.0.1 (issue #234).
 */
$s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $s) {
    fwrite(STDERR, "Cannot bind loopback: {$errstr} ({$errno})\n");
    exit(1);
}

fclose($s);
exit(0);
