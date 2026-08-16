<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** Shared directory-handle argument helpers (issue #3653, #3235, #27999). */
final class VmDirArg
{
    public static function invalidDirTypeError(string $functionName): \TypeError
    {
        return new \TypeError(\sprintf(
            '%s(): supplied resource is not a valid Directory resource',
            $functionName
        ));
    }

    /**
     * Optional $dir_handle for closedir/readdir/rewinddir (php-src dir.c / #27999).
     *
     * Omitted argument or null → EG(default_directory); absent/invalid → TypeError
     * "No resource supplied".
     */
    public static function resolveOptionalDirHandle(?Variable $v, string $functionName): int
    {
        if (null === $v) {
            return self::requireDefaultDirHandle();
        }
        $v = $v->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            return self::requireDefaultDirHandle();
        }

        return self::requireDirHandle($v, $functionName);
    }

    public static function requireDefaultDirHandle(): int
    {
        $handle = VmDir::defaultHandle();
        if (null === $handle || !VmDir::isValidHandle($handle)) {
            throw new \TypeError('No resource supplied');
        }

        return $handle;
    }

    public static function requireDirHandle(Variable $v, string $functionName, int $argNum = 1): int
    {
        $v = $v->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            // php-src ext/standard/dir.c — _php_stream_free() on null handle
            throw new \TypeError('No resource supplied');
        }
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
