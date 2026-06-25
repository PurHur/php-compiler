<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_is_local() URI probe for compiled JIT/AOT modules (#11413, php-in-PHP).
 *
 * SSOT: {@see VmStreamMeta::isLocalUri()}
 * php-src: ext/standard/streamsfuncs.c — php_stream_is_local
 */
final class StreamCapsJitHelper
{
    /** @return 0|1 ABI for __compiler_stream_is_local_uri */
    public static function isLocalUriArgv(string $uri): int
    {
        return VmStreamMeta::isLocalUri($uri) ? 1 : 0;
    }
}
