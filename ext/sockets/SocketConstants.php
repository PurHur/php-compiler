<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Linux SOCK_* values for SocketType backed enum (php-src ext/sockets/sockets.stub.php; #7235).
 */
final class SocketConstants
{
    public const SOCK_STREAM = 1;
    public const SOCK_DGRAM = 2;
    public const SOCK_RAW = 3;
    public const SOCK_RDM = 4;
    public const SOCK_SEQPACKET = 5;
}
