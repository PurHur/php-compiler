<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_isatty/is_local/supports for compiled JIT/AOT embed modules (#11413 phase 2).
 *
 * SSOT: {@see VmFs}, {@see VmStreamMeta}
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamCapsJitHelper
{
    /** @return 0|1 ABI for __compiler_stream_is_local_uri */
    public static function isLocalUriArgv(string $uri): int
    {
        return VmStreamMeta::isLocalUri($uri) ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_stream_isatty */
    public static function isattyArgv(int $handle): int
    {
        return VmFs::streamIsatty($handle) ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_stream_is_local */
    public static function isLocalArgv(int $handle): int
    {
        return VmFs::streamIsLocal($handle) ? 1 : 0;
    }

    /** @return 0|1 ABI for __compiler_stream_supports */
    public static function supportsArgv(int $handle, int $feature): int
    {
        return VmFs::streamSupports($handle, $feature) ? 1 : 0;
    }
}
