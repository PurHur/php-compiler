<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_set_time_limit/ignore_user_abort/connection_aborted via ExecutionLimitsJitHelper PHP (#9339).
 *
 * Replaces LLVM globals in prior ExecutionLimitsRuntime bodies.
 * php-src: ext/standard/basic_functions.c
 */
final class ExecutionLimitsRuntime
{
    private const HELPER_PATH = '/ext/standard/ExecutionLimitsJitHelper.php';

    private const SET_TIME_LIMIT = 'PHPCompiler\\ext\\standard\\ExecutionLimitsJitHelper::setTimeLimit';

    private const IGNORE_USER_ABORT = 'PHPCompiler\\ext\\standard\\ExecutionLimitsJitHelper::ignoreUserAbort';

    private const CONNECTION_ABORTED = 'PHPCompiler\\ext\\standard\\ExecutionLimitsJitHelper::connectionAborted';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SET_TIME_LIMIT,
        self::IGNORE_USER_ABORT,
        self::CONNECTION_ABORTED,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_set_time_limit',
        'phpc_ignore_user_abort',
        'phpc_connection_aborted',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_set_time_limit');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementSetTimeLimitBridge($context);
        self::implementIgnoreUserAbortBridge($context);
        self::implementConnectionAbortedBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSetTimeLimitBridge(Context $context): void
    {
        $abiName = 'phpc_set_time_limit';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i1, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('set_time_limit_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $ok = $context->builder->call(
            self::helperFunction($context, self::SET_TIME_LIMIT),
            $context->builder->sext($fn->getParam(0), $i64)
        );
        $context->builder->returnValue($ok);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementIgnoreUserAbortBridge(Context $context): void
    {
        $abiName = 'phpc_ignore_user_abort';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $i32, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ignore_user_abort_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $previous = $context->builder->call(
            self::helperFunction($context, self::IGNORE_USER_ABORT),
            $context->builder->sext($fn->getParam(0), $i64),
            $context->builder->sext($fn->getParam(1), $i64)
        );
        $context->builder->returnValue($context->builder->trunc($previous, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementConnectionAbortedBridge(Context $context): void
    {
        $abiName = 'phpc_connection_aborted';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('connection_aborted_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $status = $context->builder->call(self::helperFunction($context, self::CONNECTION_ABORTED));
        $context->builder->returnValue($context->builder->trunc($status, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ExecutionLimitsJitHelper compile (#9339)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ExecutionLimitsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ExecutionLimitsJitHelper.php parseAndCompile failed (#9339)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9339)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ExecutionLimitsRuntime bridge (#9339)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
