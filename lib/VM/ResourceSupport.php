<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmProcess;
use PHPCompiler\ext\standard\VmStreamBucket;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\ext\standard\VmStreamFilterChain;

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

    /** VM Resource wrapper — not a userland class (php-src-strict, #12840). */
    public static function isHiddenPseudoClassLc(string $classLc): bool
    {
        return self::CLASS_LC === strtolower(ltrim($classLc, '\\'));
    }

    public static function isHiddenPseudoClassEntry(ClassEntry $entry): bool
    {
        return self::isHiddenPseudoClassLc($entry->name);
    }

    /** get_class() operand — Zend rejects legacy resources (#12840). */
    public static function rejectsGetClassOperand(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type && self::isResourceObject($var->toObject())) {
            return true;
        }

        return $var->isVmResource();
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

    public static function isStreamFilterResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_STREAM_FILTER === $state->kind
                && VmStreamFilterChain::isValidFilter($state->handle);
        }

        $var = $var->resolveIndirect();

        return $var->streamFilterResource && Variable::TYPE_INTEGER === $var->type;
    }

    /** Process handle zval shape — closed handles fail isValidHandle (php-src proc_get_status after proc_close, #16967). */
    public static function isProcessResourceRepresentation(Variable $var): bool
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return ResourceState::KIND_PROCESS === $state->kind;
        }

        $var = $var->resolveIndirect();

        return $var->procResource && Variable::TYPE_INTEGER === $var->type;
    }

    public static function isProcessResource(Variable $var): bool
    {
        if (!self::isProcessResourceRepresentation($var)) {
            return false;
        }
        $handle = self::resolveHandle($var);

        return null !== $handle && VmProcess::isValidHandle($handle);
    }

    /** pecl-text-wddx packet resource zval (wddx_packet_start; #27858) — open when handle > 0. */
    public static function isWddxPacketResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);

        return null !== $state
            && ResourceState::KIND_WDDX_PACKET === $state->kind
            && $state->handle > 0;
    }

    /** Closed WDDX packet resource after wddx_packet_end (zend_list_close shape). */
    public static function isClosedWddxPacketResource(Variable $var): bool
    {
        $state = self::stateFromVariable($var);

        return null !== $state
            && ResourceState::KIND_WDDX_PACKET === $state->kind
            && $state->handle <= 0;
    }

    /** VM stream-context array handles (ext/standard/streams.c, #6367, #8743). */
    public static function isStreamContextResource(Variable $var): bool
    {
        return VmStreamContext::isRepresentation($var);
    }

    public static function isVmResource(Variable $var): bool
    {
        return self::isStreamResource($var)
            || self::isDirResource($var)
            || self::isBrigadeResource($var)
            || self::isBucketResource($var)
            || self::isStreamFilterResource($var)
            || self::isProcessResource($var)
            || self::isWddxPacketResource($var)
            || self::isClosedWddxPacketResource($var)
            || self::isStreamContextResource($var);
    }

    /** php-src ext/standard/type.c — gettype()/get_debug_type() on stale resource zvals (#5147). */
    public static function isClosedVmResource(Variable $var): bool
    {
        if (!self::isVmResource($var)) {
            return false;
        }
        if (self::isStreamResource($var)) {
            $handle = self::resolveHandle($var);
            if (null !== $handle && VmFs::isFailedStreamHandle($handle)) {
                return false;
            }

            return !self::isOpenStreamResource($var);
        }
        if (self::isDirResource($var)) {
            $handle = self::resolveHandle($var);

            return null === $handle || !VmDir::isValidHandle($handle);
        }
        if (self::isBrigadeResource($var)) {
            $handle = self::resolveHandle($var);

            return null === $handle || !VmStreamBucket::isValidBrigade($handle);
        }
        if (self::isBucketResource($var)) {
            $handle = self::resolveHandle($var);

            return null === $handle || !VmStreamBucket::isValidBucket($handle);
        }
        if (self::isStreamFilterResource($var)) {
            $handle = self::resolveHandle($var);

            return null === $handle || !VmStreamFilterChain::isValidFilter($handle);
        }
        if (self::isProcessResourceRepresentation($var)) {
            $handle = self::resolveHandle($var);

            return null === $handle || !VmProcess::isValidHandle($handle);
        }
        if (self::isClosedWddxPacketResource($var)) {
            return true;
        }

        return false;
    }

    public static function resolveHandle(Variable $var): ?int
    {
        $state = self::stateFromVariable($var);
        if (null !== $state) {
            return $state->handle;
        }
        $var = $var->resolveIndirect();
        if ($var->isVmResource() && Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        return null;
    }

    public static function debugTypeName(Variable $var): ?string
    {
        if (self::isClosedVmResource($var)) {
            return 'resource (closed)';
        }
        if (self::isStreamResource($var)) {
            $handle = self::resolveHandle($var);
            if (null !== $handle && VmFs::isFailedStreamHandle($handle)) {
                return 'resource (stream)';
            }
            if (null === $handle) {
                return 'Resource';
            }

            return 'resource ('.VmFs::resourceTypeForStreamTag($handle).')';
        }
        if (self::isDirResource($var)) {
            return 'resource (directory)';
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
        if (self::isStreamFilterResource($var)) {
            $handle = self::resolveHandle($var);
            $type = null !== $handle ? VmStreamFilterChain::getResourceType($handle) : null;

            return null !== $type ? 'resource ('.$type.')' : 'Resource';
        }
        if (self::isProcessResourceRepresentation($var)) {
            if (self::isClosedVmResource($var)) {
                return 'resource (closed)';
            }

            return 'resource (process)';
        }
        if (self::isStreamContextResource($var)) {
            return 'resource (stream-context)';
        }
        if (self::isClosedWddxPacketResource($var)) {
            return 'resource (closed)';
        }
        if (self::isWddxPacketResource($var)) {
            return 'resource (WDDX packet ID)';
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
            case ResourceState::KIND_STREAM_FILTER:
                $var->legacyStreamFilterHandle($handle);
                break;
            case ResourceState::KIND_PROCESS:
                $var->legacyProcessHandle($handle);
                break;
            case ResourceState::KIND_WDDX_PACKET:
                // No legacy int-tagged path — WDDX packets require Resource object wrap (#27858).
                $var->int($handle);
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

        $leftCtxId = self::isStreamContextResource($left) ? VmStreamContext::idFrom($left) : null;
        $rightCtxId = self::isStreamContextResource($right) ? VmStreamContext::idFrom($right) : null;
        if (null !== $leftCtxId || null !== $rightCtxId) {
            return null !== $leftCtxId
                && null !== $rightCtxId
                && $leftCtxId === $rightCtxId;
        }

        $leftHandle = self::resolveHandle($left);
        $rightHandle = self::resolveHandle($right);

        return $left->streamResource === $right->streamResource
            && $left->dirResource === $right->dirResource
            && $left->brigadeResource === $right->brigadeResource
            && $left->bucketResource === $right->bucketResource
            && $left->streamFilterResource === $right->streamFilterResource
            && null !== $leftHandle
            && null !== $rightHandle
            && $leftHandle === $rightHandle;
    }

    private static function resourceKindsMatch(Variable $left, Variable $right): bool
    {
        return self::isStreamResource($left) === self::isStreamResource($right)
            && self::isDirResource($left) === self::isDirResource($right)
            && self::isBrigadeResource($left) === self::isBrigadeResource($right)
            && self::isBucketResource($left) === self::isBucketResource($right)
            && self::isStreamFilterResource($left) === self::isStreamFilterResource($right)
            && self::isProcessResource($left) === self::isProcessResource($right)
            && self::isStreamContextResource($left) === self::isStreamContextResource($right);
    }
}
