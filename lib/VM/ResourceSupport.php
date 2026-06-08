<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamBucket;

/**
 * PHP 8.4 Resource builtin — VM stream/dir zval wrappers (#7073, pairs #7071).
 */
final class ResourceSupport
{
    public const CLASS_LC = 'resource';

    public static function hasResourceClass(Context $ctx): bool
    {
        return isset($ctx->classes[self::CLASS_LC]);
    }

    public static function isResourceObject(?ObjectEntry $object): bool
    {
        return null !== $object
            && 0 === strcasecmp($object->class->name, 'Resource')
            && null !== $object->resourceState;
    }

    public static function stateFromVariable(Variable $var): ?ResourceState
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }
        $object = $var->toObject();
        if (!self::isResourceObject($object)) {
            return null;
        }

        return $object->resourceState;
    }

    public static function isStreamResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_STREAM === $state->kind;
        }

        return $var->resolveIndirect()->streamResource
            && Variable::TYPE_INTEGER === $var->resolveIndirect()->type;
    }

    public static function isOpenStreamResource(Variable $var): bool
    {
        if (!self::isStreamResource($var)) {
            return false;
        }
        $handle = self::resolveHandle($var);

        return null !== $handle && VmFs::isValidHandle($handle);
    }

    public static function isDirResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_DIR === $state->kind && VmDir::isValidHandle($state->handle);
        }

        $var = $var->resolveIndirect();

        return $var->dirResource && Variable::TYPE_INTEGER === $var->type;
    }

    public static function isBrigadeResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_BRIGADE === $state->kind
                && VmStreamBucket::isValidBrigade($state->handle);
        }

        $var = $var->resolveIndirect();

        return $var->brigadeResource && Variable::TYPE_INTEGER === $var->type;
    }

    public static function isBucketResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_BUCKET === $state->kind
                && VmStreamBucket::isValidBucket($state->handle);
        }

        $var = $var->resolveIndirect();

        return $var->bucketResource && Variable::TYPE_INTEGER === $var->type;
    }

    public static function isVmResource(Variable $var): bool
    {
        return self::isStreamResource($var)
            || self::isDirResource($var)
            || self::isBrigadeResource($var)
            || self::isBucketResource($var);
    }

    public static function resolveHandle(Variable $var): ?int
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return $state->handle;
        }
        $var = $var->resolveIndirect();
        if ($var->isVmResource() && Variable::TYPE_INTEGER === $var->type) {
            return $var->integer;
        }

        return null;
    }

    public static function debugTypeName(Variable $var): ?string
    {
        if (self::isStreamResource($var)) {
            $handle = self::resolveHandle($var);
            if (null === $handle) {
                return 'Resource';
            }

            return 'resource ('.VmFs::resourceTypeForStreamTag($handle).')';
        }
        if (self::isDirResource($var)) {
            return 'resource (stream)';
        }
        if (self::isBrigadeResource($var)) {
            $handle = self::resolveHandle($var);
            $type = null !== $handle ? VmStreamBucket::getResourceType($handle, true) : null;

            return null !== $type ? 'resource ('.$type.')' : 'Resource';
        }
        if (self::isBucketResource($var)) {
            $handle = self::resolveHandle($var);
            $type = null !== $handle ? VmStreamBucket::getResourceType($handle, false) : null;

            return null !== $type ? 'resource ('.$type.')' : 'Resource';
        }

        return null;
    }

    public static function wrap(Variable $var, int $handle, string $kind, Context $ctx): void
    {
        if (!self::hasResourceClass($ctx)) {
            self::wrapLegacy($var, $handle, $kind);

            return;
        }
        $entry = $ctx->classes[self::CLASS_LC];
        $object = new ObjectEntry($entry);
        $object->constructed = true;
        $object->resourceState = new ResourceState($handle, $kind);
        $var->object($object);
    }

    private static function wrapLegacy(Variable $var, int $handle, string $kind): void
    {
        switch ($kind) {
            case ResourceState::KIND_STREAM:
                $var->legacyStreamHandle($handle);
                break;
            case ResourceState::KIND_DIR:
                $var->legacyDirHandle($handle);
                break;
            case ResourceState::KIND_BRIGADE:
                $var->legacyBrigadeHandle($handle);
                break;
            case ResourceState::KIND_BUCKET:
                $var->legacyBucketHandle($handle);
                break;
            default:
                throw new \LogicException('Unknown VM resource kind: '.$kind);
        }
    }

    /**
     * @return bool|null null when operands are not comparable resources
     */
    public static function compareResources(Variable $left, Variable $right): ?bool
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        $leftRes = self::isVmResource($left);
        $rightRes = self::isVmResource($right);
        if (!$leftRes && !$rightRes) {
            return null;
        }
        if ($leftRes !== $rightRes) {
            return false;
        }
        $leftState = self::stateFromVariable($left);
        $rightState = self::stateFromVariable($right);
        if (null !== $leftState && null !== $rightState) {
            return $leftState->kind === $rightState->kind
                && $leftState->handle === $rightState->handle;
        }
        if (null !== $leftState || null !== $rightState) {
            $leftHandle = self::resolveHandle($left);
            $rightHandle = self::resolveHandle($right);

            return null !== $leftHandle
                && null !== $rightHandle
                && $leftHandle === $rightHandle
                && self::resourceKindsMatch($left, $right);
        }

        return $left->streamResource === $right->streamResource
            && $left->dirResource === $right->dirResource
            && $left->brigadeResource === $right->brigadeResource
            && $left->bucketResource === $right->bucketResource
            && $left->integer === $right->integer;
    }

    private static function resourceKindsMatch(Variable $left, Variable $right): bool
    {
        return self::isStreamResource($left) === self::isStreamResource($right)
            && self::isDirResource($left) === self::isDirResource($right)
            && self::isBrigadeResource($left) === self::isBrigadeResource($right)
            && self::isBucketResource($left) === self::isBucketResource($right);
    }
}
