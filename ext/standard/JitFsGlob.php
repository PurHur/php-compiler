<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FsGlobVecRuntime;
use PHPCompiler\JIT\Builtin\StringFsGlobVecJit;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for glob() and scandir(); embed/standalone via FsGlobJitHelper PHP (#5459/#11515/#12909). */
final class JitFsGlob
{
    private static int $seq = 0;

    public static function glob(Context $context, Value $patternStr, Value $flagsI32): Value
    {
        StringFsGlobVecJit::implement($context);

        return self::collectFromHelper(
            $context,
            FsGlobVecRuntime::GLOB_HELPER,
            $patternStr,
            $flagsI32,
            'glob'
        );
    }

    public static function scandir(Context $context, Value $pathStr, Value $sortI32): Value
    {
        StringTriggerErrorJit::implement($context);
        StringFsGlobVecJit::implement($context);

        return self::collectFromHelper(
            $context,
            FsGlobVecRuntime::SCANDIR_HELPER,
            $pathStr,
            $sortI32,
            'scandir'
        );
    }

    private static function collectFromHelper(
        Context $context,
        string $helperLogical,
        Value $argStr,
        Value $argI32,
        string $id
    ): Value {
        FsGlobVecRuntime::ensureLinked($context);
        $tag = $id.(string) ++self::$seq;
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            FsGlobVecRuntime::helperFunction($context, $helperLogical),
            [$argStr, $argI32]
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBlock = BasicBlockHelper::append($context, $tag.'_helper_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_helper_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_helper_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitScandirFailureWarnings($context, $argStr, $id);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }

    private static function emitScandirFailureWarnings(Context $context, Value $pathStr, string $id): void
    {
        if ('scandir' !== $id) {
            return;
        }
        JitBuiltinWarning::emitPathOpenFailed($context, $pathStr, 'scandir');
        JitBuiltinWarning::emit($context, 'scandir(): (errno 2): No such file or directory');
    }
}
