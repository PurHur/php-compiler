<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT bridge for phpc_jit_* throw/catch pending state via ExceptionJitHelper PHP (#9632).
 *
 * AOT standalone keeps LLVM globals in {@see JitThrow} until native link can rely on compiled helpers.
 */
final class ExceptionThrowRuntime
{
    private const HELPER_PATH = '/ext/standard/ExceptionJitHelper.php';

    private const CLEAR_THROW_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::clearThrowPending';

    private const HAS_THROW_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::hasThrowPending';

    private const SET_THROW_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::setThrowPending';

    private const TAKE_THROW_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::takeThrowPending';

    private const CLEAR_CATCH_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::clearActiveCatch';

    private const GET_CATCH_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::getActiveCatch';

    private const SET_CATCH_HELPER = 'PHPCompiler\\ext\\standard\\ExceptionJitHelper::setActiveCatch';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CLEAR_THROW_HELPER,
        self::HAS_THROW_HELPER,
        self::SET_THROW_HELPER,
        self::TAKE_THROW_HELPER,
        self::CLEAR_CATCH_HELPER,
        self::GET_CATCH_HELPER,
        self::SET_CATCH_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_jit_clear_throw_pending',
        'phpc_jit_has_throw_pending',
        'phpc_jit_set_throw_pending',
        'phpc_jit_take_throw_pending',
        'phpc_jit_clear_active_catch',
        'phpc_jit_get_active_catch',
        'phpc_jit_set_active_catch',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_jit_clear_throw_pending');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementClearThrowBridge($context);
        self::implementHasThrowBridge($context);
        self::implementSetThrowBridge($context);
        self::implementTakeThrowBridge($context);
        self::implementClearCatchBridge($context);
        self::implementGetCatchBridge($context);
        self::implementSetCatchBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementClearThrowBridge(Context $context): void
    {
        self::implementVoidBridge($context, 'phpc_jit_clear_throw_pending', self::CLEAR_THROW_HELPER);
    }

    private static function implementClearCatchBridge(Context $context): void
    {
        self::implementVoidBridge($context, 'phpc_jit_clear_active_catch', self::CLEAR_CATCH_HELPER);
    }

    private static function implementHasThrowBridge(Context $context): void
    {
        $abiName = 'phpc_jit_has_throw_pending';
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

        $entry = $fn->appendBasicBlock('ejh_has_entry');
        $context->builder->positionAtEnd($entry);
        $pending = $context->builder->call(self::helperFunction($context, self::HAS_THROW_HELPER));
        $context->builder->returnValue($context->builder->zext($pending, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetThrowBridge(Context $context): void
    {
        $abiName = 'phpc_jit_set_throw_pending';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ejh_set_throw_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $context->builder->call(
            self::helperFunction($context, self::SET_THROW_HELPER),
            $context->builder->ptrToInt($obj, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTakeThrowBridge(Context $context): void
    {
        $abiName = 'phpc_jit_take_throw_pending';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($objPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ejh_take_entry');
        $nullBb = $fn->appendBasicBlock('ejh_take_null');
        $ptrBb = $fn->appendBasicBlock('ejh_take_ptr');
        $doneBb = $fn->appendBasicBlock('ejh_take_done');
        $context->builder->positionAtEnd($entry);

        $addr = $context->builder->call(self::helperFunction($context, self::TAKE_THROW_HELPER));
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $addr,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isZero, $nullBb, $ptrBb);

        $context->builder->positionAtEnd($ptrBb);
        $loaded = $context->builder->intToPtr($addr, $objPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($loaded, $ptrBb);
        $phi->addIncoming($objPtr->constNull(), $nullBb);
        $context->builder->returnValue($phi);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetCatchBridge(Context $context): void
    {
        $abiName = 'phpc_jit_get_active_catch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($objPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ejh_get_catch_entry');
        $nullBb = $fn->appendBasicBlock('ejh_get_catch_null');
        $ptrBb = $fn->appendBasicBlock('ejh_get_catch_ptr');
        $doneBb = $fn->appendBasicBlock('ejh_get_catch_done');
        $context->builder->positionAtEnd($entry);

        $addr = $context->builder->call(self::helperFunction($context, self::GET_CATCH_HELPER));
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $addr,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isZero, $nullBb, $ptrBb);

        $context->builder->positionAtEnd($ptrBb);
        $loaded = $context->builder->intToPtr($addr, $objPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($objPtr);
        $phi->addIncoming($loaded, $ptrBb);
        $phi->addIncoming($objPtr->constNull(), $nullBb);
        $context->builder->returnValue($phi);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetCatchBridge(Context $context): void
    {
        $abiName = 'phpc_jit_set_active_catch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ejh_set_catch_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $context->builder->call(
            self::helperFunction($context, self::SET_CATCH_HELPER),
            $context->builder->ptrToInt($obj, $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('ejh_void_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ExceptionJitHelper compile (#9632)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ExceptionJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ExceptionJitHelper.php parseAndCompile failed (#9632)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9632)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ExceptionThrowRuntime bridge (#9632)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
