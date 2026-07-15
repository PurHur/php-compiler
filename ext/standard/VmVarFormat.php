<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * var_dump()/print_r() resource formatting (ext/standard/var.c parity, #5149).
 *
 * php-src: closed stream zvals still dump as resource(id) of type (Unknown), not int(handle).
 */
final class VmVarFormat
{
    public static function tryFormatVarDump(Variable $var): ?string
    {
        $handle = self::resourceDisplayId($var);
        if (null === $handle) {
            return null;
        }

        return 'resource('.$handle.') of type ('.self::resourceTypeLabel($var, $handle).")\n";
    }

    /** debug_zval_dump() resource branch — var_dump line + GC refcount (#18419, Zend/zend.c). */
    public static function tryFormatDebugZvalDump(VM $vm, Variable $var): ?string
    {
        $handle = self::resourceDisplayId($var);
        if (null === $handle) {
            return null;
        }

        return 'resource('.$handle.') of type ('.self::resourceTypeLabel($var, $handle)
            .') refcount('.self::resourceDebugRefcount($vm, $var).")\n";
    }

    public static function tryFormatPrintR(Variable $var): ?string
    {
        $handle = self::resourceDisplayId($var);
        if (null === $handle) {
            return null;
        }

        return 'Resource id #'.$handle;
    }

    private static function resourceDisplayId(Variable $var): ?int
    {
        $var = $var->resolveIndirect();
        if (ResourceSupport::isStreamContextResource($var)) {
            return VmStreamContext::idFrom($var);
        }
        $state = ResourceSupport::stateFromVariable($var);
        if (null !== $state) {
            return $state->handle;
        }
        if (ResourceSupport::isVmResource($var) && Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        return null;
    }

    private static function resourceTypeLabel(Variable $var, int $handle): string
    {
        $state = ResourceSupport::stateFromVariable($var);
        if (null !== $state) {
            return match ($state->kind) {
                ResourceState::KIND_STREAM => VmFs::resourceTypeForStreamTag($handle),
                ResourceState::KIND_DIR => VmDir::isValidHandle($handle) ? 'directory' : 'Unknown',
                ResourceState::KIND_BRIGADE => VmStreamBucket::getResourceType($handle, true) ?? 'Unknown',
                ResourceState::KIND_BUCKET => VmStreamBucket::getResourceType($handle, false) ?? 'Unknown',
                ResourceState::KIND_STREAM_FILTER => VmStreamFilterChain::getResourceType($handle) ?? 'Unknown',
                default => 'Unknown',
            };
        }
        if (ResourceSupport::isStreamResource($var)) {
            return VmFs::resourceTypeForStreamTag($handle);
        }
        if (ResourceSupport::isDirResource($var)) {
            return 'directory';
        }
        if (ResourceSupport::isBrigadeResource($var)) {
            return VmStreamBucket::getResourceType($handle, true) ?? 'Unknown';
        }
        if (ResourceSupport::isBucketResource($var)) {
            return VmStreamBucket::getResourceType($handle, false) ?? 'Unknown';
        }
        if (ResourceSupport::isStreamFilterResource($var)) {
            return VmStreamFilterChain::getResourceType($handle) ?? 'Unknown';
        }
        if (ResourceSupport::isStreamContextResource($var)) {
            return 'stream-context';
        }

        return 'Unknown';
    }

    /**
     * Zend GC_REFCOUNT for Resource object zvals — count user-visible aliases (#18419).
     */
    private static function resourceDebugRefcount(VM $vm, Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type && ResourceSupport::isResourceObject($var->toObject())) {
            return max(2, VmDebugZval::countObjectAliases($vm, $var->toObject()) + 1);
        }

        return 2;
    }
}
