<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM as VmEngine;

/**
 * php-src zend_hash / zend_execute — resource array offsets warn and cast to int (#29550).
 *
 * Zend: {@code Resource ID#N used as offset, casting to integer (N)} then store under N.
 * Objects/enums/arrays remain illegal ({@see EnumCaseSupport::rejectIllegalArrayOffset}).
 */
final class ResourceArrayOffsetSupport
{
    /** snprintf / sprintf format — keep JIT {@see \PHPCompiler\JIT\HashTableResourceKeyLlvm} in sync. */
    public const WARNING_FORMAT = 'Resource ID#%d used as offset, casting to integer (%d)';

    /** LLVM snprintf uses `%lld` for int64 handles. */
    public const WARNING_FORMAT_LL = 'Resource ID#%lld used as offset, casting to integer (%lld)';

    public static function warningMessage(int $resourceId): string
    {
        return sprintf(self::WARNING_FORMAT, $resourceId, $resourceId);
    }

    /**
     * When $index is a VM resource, emit the Zend E_WARNING and return an integer key.
     * Otherwise null (caller continues normal key normalization / illegal-offset checks).
     */
    public static function tryCoerceToIntKey(Variable $index, ?Frame $frame = null): ?Variable
    {
        $index = $index->resolveIndirect();
        if (!self::isResourceArrayOffset($index)) {
            return null;
        }
        $handle = ResourceSupport::resolveHandle($index);
        if (null === $handle) {
            $handle = $index->toInt();
        }
        self::emitWarning($handle, $frame);
        $intKey = new Variable();
        $intKey->int($handle);

        return $intKey;
    }

    public static function isResourceArrayOffset(Variable $index): bool
    {
        $index = $index->resolveIndirect();
        if (ResourceSupport::isVmResource($index)) {
            return true;
        }

        return Variable::TYPE_OBJECT === $index->type
            && ResourceSupport::isResourceObject($index->toObject());
    }

    public static function emitWarning(int $resourceId, ?Frame $frame = null): void
    {
        $vm = VmEngine::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->languageWarning(
            self::warningMessage($resourceId),
            null,
            0,
            $vm->context,
            $frame
        );
    }
}
