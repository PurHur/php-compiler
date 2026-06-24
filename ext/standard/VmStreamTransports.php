<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Registered stream transport list — php-src ext/standard/streams.c php_stream_get_transports().
 *
 * PHP-in-PHP: core socket transports this build exposes via fsockopen/stream APIs (#3329).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/streams.c PHP_FUNCTION(stream_get_transports)
 */
final class VmStreamTransports
{
    /** @var list<string> */
    private const BUILTIN_TRANSPORTS = [
        'tcp',
        'udp',
        'unix',
        'udg',
        'ssl',
        'tls',
        'tlsv1.0',
        'tlsv1.1',
        'tlsv1.2',
        'tlsv1.3',
    ];

    /** @return list<string> */
    public static function getTransports(): array
    {
        $all = self::BUILTIN_TRANSPORTS;
        \sort($all);

        return $all;
    }
}
