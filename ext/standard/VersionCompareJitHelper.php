<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_version_compare (#9813, php-in-PHP).
 *
 * SSOT: {@see VmInfo::version_compare()} without operator (php-src ext/standard/versioning.c).
 */
final class VersionCompareJitHelper
{
    /** @return int -1, 0, or 1 */
    public static function compare(string $ver1, string $ver2): int
    {
        $result = VmInfo::version_compare($ver1, $ver2);
        if (!\is_int($result)) {
            throw new \LogicException('version_compare() without operator must return int (#9813)');
        }

        return $result;
    }
}
