<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * HTTP fetch entry alias — delegates to {@see VmHttpFetchPure} (#8939, #8552).
 *
 * php-src: ext/standard/streams.c — http wrapper
 */
final class VmHttpFetchNative
{
    public static function available(): bool
    {
        return VmHttpFetchPure::available();
    }

    /**
     * @param array<string, mixed> $httpOptions stream_context http wrapper options
     *
     * @return string|false response body; false on transport/parse failure
     */
    public static function fetch(
        string $url,
        array $httpOptions = [],
        ?Context $ctx = null,
        ?Variable $streamContext = null
    ): string|false {
        return VmHttpFetchPure::fetch($url, $httpOptions, $ctx, $streamContext);
    }

    /**
     * @param array<string, mixed> $httpOptions
     *
     * @return list<string>|false
     */
    public static function fetchHeaders(
        string $url,
        array $httpOptions = [],
        ?Context $ctx = null,
        ?Variable $streamContext = null
    ): array|false {
        return VmHttpFetchPure::fetchHeaders($url, $httpOptions, $ctx, $streamContext);
    }
}
