<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** Shared stream argument helpers (issue #3755, #6044). */
final class VmStreamArg
{
    /**
     * Resolve a VM/JIT stream handle for builtins that expect php-src "resource" streams.
     *
     * fopen() from the VM tags {@see Variable::streamHandle()}; JIT/AOT paths return bare
     * integers registered in {@see VmFs::isValidHandle()}. Plain integers (e.g. 42) TypeError.
     */
    public static function requireStreamHandle(Variable $v, string $functionName, int $argNum = 1): int
    {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($stream) must be of type resource, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($stream) must be of type resource, %s given',
                $functionName,
                $argNum,
                self::debugTypeName($v)
            ));
        }
        $handle = $v->toInt();
        if ($v->isStreamResource() || VmFs::isValidHandle($handle)) {
            return $handle;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($stream) must be of type resource, %s given',
            $functionName,
            $argNum,
            self::debugTypeName($v)
        ));
    }

    public static function debugTypeName(Variable $v): string
    {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            return EnumCaseSupport::typeNameForVariable($v);
        }

        switch ($v->type) {
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }

    public static function rejectEnumCaseOperand(
        Variable $v,
        string $functionName,
        int $argNum = 1,
        string $paramName = 'resource'
    ): void {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type resource, %s given',
                $functionName,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
    }
}
