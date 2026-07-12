<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** zip_open/zip_read resource argument helpers (#6370). */
final class VmZipResourceArg
{
    public static function resolveHandle(Variable $var): ?int
    {
        $var = $var->resolveIndirect();
        if (!$var->isStreamResource()) {
            return null;
        }
        $handle = ResourceSupport::resolveHandle($var);
        if (null === $handle) {
            return null;
        }
        if (VmFs::isZipArchivePlaceholder($handle) || VmFs::isZipEntryPlaceholder($handle)) {
            return $handle;
        }

        return null;
    }

    public static function isZipResource(Variable $var): bool
    {
        $handle = self::resolveHandle($var);

        return null !== $handle;
    }

    public static function debugTypeName(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }
        $handle = self::resolveHandle($var);
        if (null !== $handle) {
            if (VmFs::isZipArchivePlaceholder($handle)) {
                return 'resource';
            }
            if (VmFs::isZipEntryPlaceholder($handle)) {
                return 'resource';
            }
        }
        $resourceDebug = ResourceSupport::debugTypeName($var);
        if (null !== $resourceDebug) {
            return $resourceDebug;
        }

        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
