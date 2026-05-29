<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

/**
 * TCP FastCGI listener (issue #173).
 */
final class Listener
{
    /**
     * Accept connections until interrupted; one request per connection (phase 2).
     */
    public static function serve(string $listen, string $docroot): void
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server('tcp://'.$listen, $errno, $errstr);
        if (false === $server) {
            throw new \RuntimeException('FastCGI listen failed on tcp://'.$listen.': '.$errstr);
        }
        stream_set_blocking($server, true);
        $handler = new RequestHandler($docroot);
        while (true) {
            $conn = @stream_socket_accept($server, -1);
            if (false === $conn) {
                continue;
            }
            try {
                $handler->handleStream($conn);
            } finally {
                fclose($conn);
            }
        }
    }
}
