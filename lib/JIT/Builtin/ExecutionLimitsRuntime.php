<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM execution limit state for JIT/AOT (issue #8078, phase 2 of #3242).
 *
 * PHP-in-PHP: mirrors ext/standard/VmExecutionLimits.php — no runtime/*.c.
 * php-src: ext/standard/basic_functions.c
 */
final class ExecutionLimitsRuntime
{
    private const G_DEADLINE = 'phpc_exec_limit_deadline';

    private const G_LIMIT_SECONDS = 'phpc_exec_limit_seconds';

    private const G_IGNORE_USER_ABORT = 'phpc_ignore_user_abort';

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

        StringMicrotime::ensureLinked($context);
        self::ensureGlobals($context);

        self::implementSetTimeLimit($context);
        self::implementIgnoreUserAbort($context);
        self::implementConnectionAborted($context);

        self::registerLinkedRuntime($context);
    }

    private static function implementSetTimeLimit(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $double = $context->getTypeFromString('double');
        $ft = $context->context->functionType($i1, false, $i32);
        $fn = self::functionOrCreate($context, 'phpc_set_time_limit', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('set_time_limit_entry');
        $context->builder->positionAtEnd($entry);

        $seconds = $fn->getParam(0);
        $limitPtr = self::globalPtr($context, self::G_LIMIT_SECONDS, $i32);
        $deadlinePtr = self::globalPtr($context, self::G_DEADLINE, $double);
        $context->builder->store($seconds, $limitPtr);

        $zeroSec = $i32->constInt(0, false);
        $isUnlimited = $context->builder->icmp(Builder::INT_EQ, $seconds, $zeroSec);
        $unlimitedBb = $fn->appendBasicBlock('set_time_limit_unlimited');
        $limitedBb = $fn->appendBasicBlock('set_time_limit_limited');
        $doneBb = $fn->appendBasicBlock('set_time_limit_done');
        $context->builder->branchIf($isUnlimited, $unlimitedBb, $limitedBb);

        $context->builder->positionAtEnd($unlimitedBb);
        $context->builder->store($double->constReal(0.0), $deadlinePtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($limitedBb);
        $now = $context->builder->call($context->lookupFunction('__compiler_microtime_float'));
        $secondsDouble = $context->builder->sitofp($seconds, $double);
        $deadline = $context->builder->fadd($now, $secondsDouble);
        $context->builder->store($deadline, $deadlinePtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($i1->constInt(1, false));
    }

    private static function implementIgnoreUserAbort(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i32, $i32);
        $fn = self::functionOrCreate($context, 'phpc_ignore_user_abort', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('ignore_user_abort_entry');
        $context->builder->positionAtEnd($entry);

        $apply = $fn->getParam(0);
        $value = $fn->getParam(1);
        $globalPtr = self::globalPtr($context, self::G_IGNORE_USER_ABORT, $i32);
        $previous = $context->builder->load($globalPtr);

        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $shouldApply = $context->builder->icmp(Builder::INT_NE, $apply, $zero);
        $applyBb = $fn->appendBasicBlock('ignore_user_abort_apply');
        $doneBb = $fn->appendBasicBlock('ignore_user_abort_done');
        $context->builder->branchIf($shouldApply, $applyBb, $doneBb);

        $context->builder->positionAtEnd($applyBb);
        $isTruthy = $context->builder->icmp(Builder::INT_NE, $value, $zero);
        $stored = $context->builder->select($isTruthy, $one, $zero);
        $context->builder->store($stored, $globalPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($previous);
    }

    private static function implementConnectionAborted(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = self::functionOrCreate($context, 'phpc_connection_aborted', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('connection_aborted_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $double = $context->getTypeFromString('double');

        if (null === $context->module->getNamedGlobal(self::G_DEADLINE)) {
            $g = $context->module->addGlobal($double, self::G_DEADLINE);
            $g->setInitializer($double->constReal(0.0));
        }
        if (null === $context->module->getNamedGlobal(self::G_LIMIT_SECONDS)) {
            $g = $context->module->addGlobal($i32, self::G_LIMIT_SECONDS);
            $g->setInitializer($i32->constInt(30, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_IGNORE_USER_ABORT)) {
            $g = $context->module->addGlobal($i32, self::G_IGNORE_USER_ABORT);
            $g->setInitializer($i32->constInt(0, false));
        }
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('Execution limits global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function functionOrCreate(Context $context, string $name, $fnType): LlvmFunction
    {
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing) {
            return $existing;
        }

        return $context->module->addFunction($name, $fnType);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                'phpc_set_time_limit',
                'phpc_ignore_user_abort',
                'phpc_connection_aborted',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ExecutionLimitsRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
