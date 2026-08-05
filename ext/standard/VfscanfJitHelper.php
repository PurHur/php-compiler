<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * vfscanf()/fscanf() helper for VM/host (#12541).
 *
 * Thin AOT {@see \PHPCompiler\JIT\Builtin\StringVfscanf} does not NestedJIT this class
 * (#27663) — it uses `__compiler_fgets` + {@see SscanfJitHelper::parseAssignMeta}.
 * php-src: ext/standard/file.c PHP_FUNCTION(fscanf) / scanf.c
 */
final class VfscanfJitHelper
{
    /**
     * By-ref assignment path: meta blob for {@see SscanfAssignApply}.
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
