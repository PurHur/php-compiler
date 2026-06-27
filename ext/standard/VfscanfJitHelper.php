<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * vfscanf() for compiled JIT/AOT modules (#12541, php-in-PHP).
 *
 * SSOT: {@see VmVfscanf} (php-src ext/standard/scanf.c).
 */
final class VfscanfJitHelper
{
    /**
     * By-ref assignment path: meta blob for {@see SscanfAssignApply} (consumed unused).
     */
    public static function parseAssignMeta(int $handle, string $format, int $outCount): ?string
    {
        if ($outCount <= 0) {
            return null;
        }

        $outVars = [];
        for ($i = 0; $i < $outCount; ++$i) {
            $outVars[] = new Variable();
        }

        $assigned = VmVfscanf::parse($handle, $format, $outVars);
        if (false === $assigned) {
            return null;
        }

        return SscanfJitHelper::packMetaFromVariables($assigned, 0, $outVars);
    }
}
