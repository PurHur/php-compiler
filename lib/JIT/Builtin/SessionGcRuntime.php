<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_gc() via SessionGcJitHelper PHP (#9411).
 *
 * Replaces LLVM file-scan in SessionStorageRuntime and session GC apply logic here.
 * php-src: ext/session/session.c — php_session_gc
 */
final class SessionGcRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionGcJitHelper.php';

    private const GC_EXPIRED_FILES = 'PHPCompiler\\ext\\standard\\SessionGcJitHelper::gcExpiredFilesAsInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GC_EXPIRED_FILES,
    ];

    public static function ensureLinked(Context $context): void
    {
        CallArgv::implement($context);
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageRuntime::ensureLinked($context);

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_gc_expired_files', self::implementGcExpiredFilesBridge(...));
        self::implementIfMissing($context, '__phpc_session_gc_apply', self::implementGcApplyBridge(...));
        self::registerLinkedRuntime($context);
    }

    public static function ensureGcExpiredFilesLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_gc_expired_files', self::implementGcExpiredFilesBridge(...));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['phpc_session_gc_expired_files', '__phpc_session_gc_apply'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SessionGcRuntime bridge (#9411)');
            }
            $context->registerFunction($name, $fn);
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

        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');

        return match ($name) {
            'phpc_session_gc_expired_files' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false)
            ),
            '__phpc_session_gc_apply' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr)
            ),
            default => throw new \LogicException('Unknown session GC JIT helper: '.$name),
        };
    }

    private static function implementGcExpiredFilesBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgc_expired_entry');
        $context->builder->positionAtEnd($entry);
        $deleted = $context->builder->call(
            self::helperFunction($context, self::GC_EXPIRED_FILES)
        );
        $i64 = $context->getTypeFromString('int64');
        $context->builder->returnValue($context->builder->sext($deleted, $i64));
    }

    private static function implementGcApplyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgc_apply_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);
        $negOneI64 = $i64->constInt(-1, true);

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $bbInactive = BasicBlockHelper::append($context, 'sgc_inactive');
        $bbActive = BasicBlockHelper::append($context, 'sgc_active');
        $bbDone = BasicBlockHelper::append($context, 'sgc_done');
        $context->builder->branchIf($isActive, $bbActive, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        self::emitInactiveWarning($context);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbActive);
        $deleted = $context->builder->call($context->lookupFunction('phpc_session_gc_expired_files'));
        $failed = $context->builder->icmp(Builder::INT_EQ, $deleted, $negOneI64);
        $bbFail = BasicBlockHelper::append($context, 'sgc_fail');
        $bbOk = BasicBlockHelper::append($context, 'sgc_ok');
        $context->builder->branchIf($failed, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $deleted
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitInactiveWarning(Context $context): void
    {
        $msg = 'session_gc(): Session cannot be garbage collected when there is no active session';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast(
            $context->constantFromString($msg),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $i8p->constNull(),
            $i32->constInt(0, false)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SessionGcJitHelper compile (#9411)');
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

        CallArgv::implement($context);
        StringFileGetContents::implement($context);
        StringFilePutContents::implement($context);

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SessionGcJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SessionGcJitHelper.php parseAndCompile failed (#9411)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9411)');
            }
        }
    }
}
