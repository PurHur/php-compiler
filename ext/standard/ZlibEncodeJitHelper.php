<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for ob_gzhandler gzip compression (#9091, php-in-PHP).
 *
 * SSOT: {@see ZlibJitHelper::encodeArgv} → {@see VmZlibCore::gzencode}
 */
final class ZlibEncodeJitHelper
{
    public static function gzencode(string $data, int $level, int $encoding): string|false
    {
        $result = ZlibJitHelper::encodeArgv($data, $level, $encoding);

        return null === $result ? false : $result;
    }
}
