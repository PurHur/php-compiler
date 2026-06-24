<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** Shared stream argument helpers (issue #3755, #6044, #5135). */
final class VmStreamArg
{
    /**
     * php-src ext/standard/streams.c — invalid/closed stream resource on stream ops.
     */
    public static function invalidStreamTypeError(string $functionName): \TypeError
    {
        return new \TypeError(\sprintf(
            '%s(): supplied resource is not a valid stream resource',
            $functionName
        ));
    }

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
        if (ResourceSupport::isStreamResource($v) && !ResourceSupport::isOpenStreamResource($v)) {
            throw self::invalidStreamTypeError($functionName);
        }
        if (ResourceSupport::isOpenStreamResource($v)) {
            $handle = ResourceSupport::resolveHandle($v);
            if (null !== $handle) {
                return $handle;
            }
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
        $resourceDebug = ResourceSupport::debugTypeName($v);
        if (null !== $resourceDebug) {
            return $resourceDebug;
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
                return $v->toObject()->class->name;
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
