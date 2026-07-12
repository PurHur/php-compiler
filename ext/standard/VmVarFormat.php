<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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

    public static function tryFormatPrintR(Variable $var): ?string
    {
        $handle = self::resourceDisplayId($var);
        if (null === $handle) {
            return null;
        }

        return 'Resource id #'.$handle;
    }

    public static function formatDebugZvalResource(Variable $var, int $refcount): ?string
    {
        $handle = self::resourceDisplayId($var);
        if (null === $handle) {
            return null;
        }

        return 'resource('.$handle.') of type ('.self::resourceTypeLabel($var, $handle).') refcount('.$refcount.")\n";
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
}
