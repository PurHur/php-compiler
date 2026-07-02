<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** Shared directory-handle argument helpers (issue #3653, #3235). */
final class VmDirArg
{
    public static function invalidDirTypeError(string $functionName): \TypeError
    {
        return new \TypeError(\sprintf(
            '%s(): supplied resource is not a valid directory resource',
            $functionName
        ));
    }

    public static function requireDirHandle(Variable $v, string $functionName, int $argNum = 1): int
    {
        $v = $v->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($v)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($dir_handle) must be of type resource, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($v)
            ));
        }
        if (ResourceSupport::isDirResource($v)) {
            $handle = ResourceSupport::resolveHandle($v);
            if (null !== $handle && VmDir::isValidHandle($handle)) {
                return $handle;
            }

            throw self::invalidDirTypeError($functionName);
        }
        $state = ResourceSupport::stateFromVariable($v);
        if (null !== $state && ResourceState::KIND_DIR === $state->kind) {
            throw self::invalidDirTypeError($functionName);
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($dir_handle) must be of type resource, %s given',
            $functionName,
            $argNum,
            VmStreamArg::debugTypeName($v)
        ));
    }
}
