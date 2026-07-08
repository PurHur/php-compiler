<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Lazy minimal ob stack for user-script AOT exec stdout capture (#10492).
 */
final class ObOutputExecCaptureRuntime
{
    private const HELPER_PATH = '/ext/standard/ObOutputExecCaptureJitHelper.php';

    private const START = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::start';

    private const APPEND = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::appendString';

    private const GET_CLEAN = 'PHPCompiler\\ext\\standard\\ObOutputExecCaptureJitHelper::getClean';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::START,
        self::APPEND,
        self::GET_CLEAN,
    ];

    public static function ensureLinked(Context $context): void
    {
        $append = $context->module->getNamedFunction('__phpc_ob_append_bytes');
        if (null !== $append && $append->countBasicBlocks() > 0) {
            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            ObOutputExecCaptureLlvm::ensureLinked($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        ObOutputEchoJitEmit::ensureEchoAbiDeclared($context);
        self::ensureAppendBytesDeclared($context);
        self::ensureHelperCompiled($context);
        self::implementStart($context);
        self::implementAppendBytes($context);
        self::implementGetClean($context);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureHelperCompiled(Context $context): void
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ObOutputExecCaptureJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ObOutputExecCaptureJitHelper.php parseAndCompile failed (#10492)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        $fn = $context->functions[\strtolower($logical)] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ObOutputExecCaptureJitHelper compile (#10492)');
        }

        return $fn;
    }

    private static function implementStart(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_start', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_start_entry');
            $context->builder->positionAtEnd($entry);
            $context->builder->call(self::helperFunction($context, self::START));
            $context->builder->returnVoid();
        });
    }

    private static function implementAppendBytes(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_append_bytes', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_append_entry');
            $done = $fn->appendBasicBlock('oec_append_done');
            $skip = $fn->appendBasicBlock('oec_append_skip');
            $work = $fn->appendBasicBlock('oec_append_work');
            $context->builder->positionAtEnd($entry);
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $i64 = $context->getTypeFromString('int64');
            $data = $fn->getParam(0);
            $len = $fn->getParam(1);
            $zero = $sizeT->constInt(0, false);
            $bad = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $data, $i8p->constNull()),
                $context->builder->icmp(Builder::INT_EQ, $len, $zero)
            );
            $context->builder->branchIf($bad, $skip, $work);
            $context->builder->positionAtEnd($work);
            $chunk = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->sext($len, $i64),
                $data
            );
            $context->builder->call(
                self::helperFunction($context, self::APPEND),
                $context->builder->call($context->lookupFunction('__string__separate'), $chunk)
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($skip);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $context->builder->returnVoid();
        });
    }

    private static function implementGetClean(Context $context): void
    {
        self::implementIfMissing($context, '__phpc_ob_get_clean', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('oec_get_clean_entry');
            $fail = $fn->appendBasicBlock('oec_get_clean_fail');
            $okBb = $fn->appendBasicBlock('oec_get_clean_ok');
            $context->builder->positionAtEnd($entry);
            $out = $fn->getParam(0);
            $i32 = $context->getTypeFromString('int32');
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::GET_CLEAN),
                []
            );
            $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
            $context->builder->branchIf($isNull, $fail, $okBb);
            $context->builder->positionAtEnd($fail);
            $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
            $context->builder->returnValue($i32->constInt(0, false));
            $context->builder->positionAtEnd($okBb);
            $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
            $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
            $context->builder->returnValue($i32->constInt(1, false));
        });
    }

    private static function ensureAppendBytesDeclared(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ob_append_bytes');
        if (null !== $probe) {
            $context->registerFunction('__phpc_ob_append_bytes', $probe);

            return;
        }
        $void = $context->context->voidType();
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = $context->module->addFunction(
            '__phpc_ob_append_bytes',
            $context->context->functionType($void, false, $i8p, $sizeT)
        );
        $context->registerFunction('__phpc_ob_append_bytes', $fn);
    }

    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $fn = $probe;
        if (null === $fn) {
            throw new \LogicException($name.' not declared before ObOutputExecCaptureRuntime (#10492)');
        }
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
