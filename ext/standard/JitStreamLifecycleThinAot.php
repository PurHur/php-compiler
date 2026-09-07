<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Builtin\StreamLibcHandleRuntime;
use PHPCompiler\JIT\Context;

/**
 * Thin standalone AOT stream lifecycle — LLVM handle table only (#36382 / #23777 / #27186).
 *
 * No NestedJIT of StreamLifecycleJitHelper / JitOpenStreamHandles (hangs under IncludeHelper
 * and never sees {@see StreamGlobalsJit} FILE* slots that {@see JitStreamIoKernel} fopen fills).
 *
 * php-src: ext/standard/file.c — fclose / is_resource on php://memory streams
 */
final class JitStreamLifecycleThinAot
{
    public static function implement(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        StreamGlobalsJit::implementThinIsResource($context);
        // feof/fflush need __phpc_resolve_stream body before emit (#32287 / hello link).
        JitStreamIoKernel::implementLifecyclePeersForThinAot($context);
        self::implementFclose($context);
        self::implementPclose($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Thin `__compiler_fclose` — libc fclose + clear LLVM slot (#33426). */
    private static function implementFclose(Context $context): void
    {
        $fn = self::resetOrAddUnaryI32($context, '__compiler_fclose', 'stream_lifecycle_thin_fclose_entry');
        $context->builder->returnValue(
            StreamLibcHandleRuntime::emitFcloseAndClearLlvmHandleSlot($context, $fn->getParam(0))
        );
        $context->registerFunction('__compiler_fclose', $fn);
        $context->builder->clearInsertionPosition();
    }

    /** Thin `__compiler_pclose` — clear LLVM slot only (#36382). */
    private static function implementPclose(Context $context): void
    {
        $fn = self::resetOrAddUnaryI32($context, '__compiler_pclose', 'stream_lifecycle_thin_pclose_entry');
        StreamLibcHandleRuntime::emitClearLlvmHandleSlot($context, $fn->getParam(0));
        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction('__compiler_pclose', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function resetOrAddUnaryI32(
        Context $context,
        string $abiName,
        string $entryName
    ): \PHPLLVM\Value\Function_ {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);

        return $fn;
    }
}
