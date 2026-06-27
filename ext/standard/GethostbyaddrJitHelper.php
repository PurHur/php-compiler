<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostbyaddr() reverse DNS for compiled JIT/AOT modules (#9474, php-in-PHP).
 *
 * SSOT: {@see VmDns::gethostbyaddr}; empty string signals lookup failure for LLVM bridge.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbyaddr)
 */
final class GethostbyaddrJitHelper
{
    public static function resolve(string $ipAddress): string
    {
        $error = VmDns::ERR_NONE;
        $result = VmDns::gethostbyaddr($ipAddress, $error);
        if (false !== $result) {
            return $result;
        }
        if (VmDns::ERR_NOT_FOUND === $error) {
            return $ipAddress;
        }

        return '';
    }
}
