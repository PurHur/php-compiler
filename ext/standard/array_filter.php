<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Func\PHP;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ArrayFilterRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_filter() with default falsy removal or string builtin / closure callbacks.
 *
 * php-src: ext/standard/array.c — php_array_filter(), ARRAY_FILTER_USE_* modes (#4243).
 *
 * Excess/missing argc → ArgumentCountError (#28473).
 * Z_PARAM_LONG $mode null under strict_types → TypeError (#31360).
 */
final class array_filter extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.stub.php — ArgumentCountError (#28473).
        $this->requireArgCountRange($frame, 'array_filter', 1, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $src = VmArray::requireArrayParam($frame->calledArgs[0], 'array_filter', 1, 'array');
        $out = new HashTable();
        // Named mode: alone leaves a hole at callback (php-src array.stub.php; #24843).
        $callbackArg = \array_key_exists(1, $frame->calledArgs) ? $frame->calledArgs[1] : null;
        // Z_PARAM_LONG $mode — caller strict_types → TypeError on null; else soft-null DEP+0 (#31360).
        // Present arg (including Variable TYPE_NULL) must be type-checked — not treated as omitted.
        $mode = 0;
        if (isset($frame->calledArgs[2])) {
            $mode = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'array_filter',
                3,
                'mode'
            );
        }
        if (null === $callbackArg) {
            self::filterDefault($src, $out);
            $frame->returnVar->array($out);

            return;
        }
        [$closure, $internal, $userFn, $general] = VmArrayFilterCallback::resolve($frame, $callbackArg);
        if (null === $closure && null === $internal && null === $userFn && null === $general) {
            self::filterDefault($src, $out);
            $frame->returnVar->array($out);

            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_filter() requires VM context in this compiler build');
        }
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $keep = self::invokeCallback(
                $frame,
                $closure,
                $internal,
                $userFn,
                $general,
                $mode,
                $key,
                $value
            );
            if (boolval::isTruthy($keep)) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28473.
        if (!$this->requireArgCountRangeJit($context, $args, 'array_filter', 1, 3)) {
            return HashTableHelper::alloc($context);
        }
        $argc = \count($args);
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_filter', 1, 'array');
        // Z_PARAM_LONG $mode before soft-null callback short-circuit (#31360 / peer parse_ini #31264).
        if ($argc >= 3) {
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'array_filter(): Argument #3 ($mode) must be of type int, null given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'array_filter_null_mode_te_cont');

                return HashTableHelper::alloc($context);
            }
            // Soft path: emit DEP+coerce even when callback is null (mode unused by filterDefault).
            JitSleep::zParamLong($context, $args[2], 'array_filter', 3, 'mode');
        }
        // Null / omitted callback → soft falsy filter; php-src array.c (#24843).
        if ($argc >= 2) {
            $callback = $args[1];
            if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
                return ArrayFilterRuntime::filterDefault($context, $args[0]);
            }
            throw new \LogicException(
                'array_filter() with a callback is not supported by the JIT compiler in this build'
            );
        }

        return ArrayFilterRuntime::filterDefault($context, $args[0]);
    }

    private static function filterDefault(HashTable $src, HashTable $out): void
    {
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            if (boolval::isTruthy($value)) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
    }

    private static function invokeCallback(
        Frame $frame,
        ?ClosureState $closure,
        ?Internal $internal,
        ?PHP $userFn,
        ?Variable $general,
        int $mode,
        Variable $key,
        Variable $value,
    ): Variable {
        $context = $frame->vmContext;
        if (null !== $general) {
            return match ($mode) {
                StdlibConstants::ARRAY_FILTER_USE_KEY => VmCallable::invokeAsWithScope(
                    'array_filter',
                    $context,
                    $frame,
                    $general,
                    $key
                ),
                StdlibConstants::ARRAY_FILTER_USE_BOTH => VmCallable::invokeAsWithScope(
                    'array_filter',
                    $context,
                    $frame,
                    $general,
                    $value,
                    $key
                ),
                default => VmCallable::invokeAsWithScope(
                    'array_filter',
                    $context,
                    $frame,
                    $general,
                    $value
                ),
            };
        }
        if (null !== $closure) {
            return match ($mode) {
                StdlibConstants::ARRAY_FILTER_USE_KEY => VmClosureCall::invokeOne($context, $closure, $key),
                StdlibConstants::ARRAY_FILTER_USE_BOTH => VmClosureCall::invoke($context, $closure, $value, $key),
                default => VmClosureCall::invokeOne($context, $closure, $value),
            };
        }
        if (null !== $internal) {
            return match ($mode) {
                StdlibConstants::ARRAY_FILTER_USE_KEY => VmInternalCall::invoke($internal, $key),
                StdlibConstants::ARRAY_FILTER_USE_BOTH => VmInternalCall::invoke($internal, $value, $key),
                default => VmInternalCall::invoke($internal, $value),
            };
        }

        return match ($mode) {
            StdlibConstants::ARRAY_FILTER_USE_KEY => $context->runtime->vm->invokePhpFunction($userFn, $key),
            StdlibConstants::ARRAY_FILTER_USE_BOTH => $context->runtime->vm->invokePhpFunction($userFn, $value, $key),
            default => $context->runtime->vm->invokePhpFunction($userFn, $value),
        };
    }
}
