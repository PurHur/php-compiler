<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmFsTempnam;
use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_touch/__compiler_mkdir/__compiler_tempnam via FsDirJitHelper PHP (#8999).
 *
 * Replaces libc LLVM in {@see StringFsDirJit}. SSOT: {@see \PHPCompiler\ext\standard\VmFs}.
 * php-src: ext/standard/filestat.c, ext/standard/file.c
 */
final class FsDirRuntime
{
    private const HELPER_PATH = '/ext/standard/FsDirJitHelper.php';

    private const TOUCH_HELPER = 'PHPCompiler\\ext\\standard\\FsDirJitHelper::touch';

    private const MKDIR_HELPER = 'PHPCompiler\\ext\\standard\\FsDirJitHelper::mkdir';

    private const TEMPNAM_HELPER = 'PHPCompiler\\ext\\standard\\FsDirJitHelper::tempnam';

    private const TEMPNAM_NOTICE_HELPER = 'PHPCompiler\\ext\\standard\\FsDirJitHelper::consumeTempnamNotice';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TOUCH_HELPER,
        self::MKDIR_HELPER,
        self::TEMPNAM_HELPER,
        self::TEMPNAM_NOTICE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_touch',
        '__compiler_mkdir',
        '__compiler_tempnam',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_touch');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_touch', self::implementTouchBridge(...));
        self::implementIfMissing($context, '__compiler_mkdir', self::implementMkdirBridge(...));
        self::implementIfMissing($context, '__compiler_tempnam', self::implementTempnamBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        return match ($name) {
            '__compiler_touch' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i64)
            ),
            '__compiler_mkdir' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $strPtr, $i64, $i32)
            ),
            '__compiler_tempnam' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown fs dir JIT helper: '.$name),
        };
    }

    private static function implementTouchBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fdr_touch_entry');
        $fail = $fn->appendBasicBlock('fdr_touch_fail');
        $body = $fn->appendBasicBlock('fdr_touch_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $ok = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TOUCH_HELPER),
            [$path, $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $ok, $i32)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function implementMkdirBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fdr_mkdir_entry');
        $fail = $fn->appendBasicBlock('fdr_mkdir_fail');
        $body = $fn->appendBasicBlock('fdr_mkdir_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $recursive = $context->builder->icmp(
            Builder::INT_NE,
            $fn->getParam(2),
            $i32->constInt(0, false)
        );
        $ok = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MKDIR_HELPER),
            [$path, $fn->getParam(1), $recursive]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $ok, $i32)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function implementTempnamBridge(Context $context, LlvmFunction $fn): void
    {
        StringTriggerError::ensureLinked($context);

        $entry = $fn->appendBasicBlock('fdr_tempnam_entry');
        $fail = $fn->appendBasicBlock('fdr_tempnam_fail');
        $body = $fn->appendBasicBlock('fdr_tempnam_body');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $dir = $fn->getParam(0);
        $pfx = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dir, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $pfx, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $pathRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TEMPNAM_HELPER),
            [$dir, $pfx]
        );
        $pending = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::TEMPNAM_NOTICE_HELPER),
            []
        );
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $emitNotice = $context->builder->icmp(
            Builder::INT_NE,
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $pending, $i32),
            $i32->constInt(0, false)
        );
        $noticeDo = BasicBlockHelper::append($context, 'fdr_tempnam_notice_do');
        $afterNotice = BasicBlockHelper::append($context, 'fdr_tempnam_after_notice');
        $context->builder->branchIf($emitNotice, $noticeDo, $afterNotice);

        $context->builder->positionAtEnd($noticeDo);
        $message = VmFsTempnam::NOTICE_MESSAGE;
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->branch($afterNotice);

        $context->builder->positionAtEnd($afterNotice);
        $pathNull = JitNestedHelperCoerce::isHelperResultNull($context, $pathRaw);
        $retBb = BasicBlockHelper::append($context, 'fdr_tempnam_ret');
        $context->builder->branchIf($pathNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $path = JitNestedHelperCoerce::coerceBridgeResult($context, $pathRaw, $strPtr);
        $context->builder->returnValue($path);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FsDirJitHelper compile (#8999)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FsDirJitHelper.php');
            if (null === $block) {
                throw new \LogicException('FsDirJitHelper.php parseAndCompile failed (#8999)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#8999)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after FsDirRuntime bridge (#8999)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
