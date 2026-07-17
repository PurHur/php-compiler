<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitJsonDecode;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_get_info() — mbstring runtime state dump (php-src ext/mbstring/mbstring.c; #20014). */
final class mb_get_info extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_get_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_get_info() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = 0 === $argc
            ? 'all'
            : VmMbstring::coerceGetInfoTypeArg($frame->calledArgs[0], 'mb_get_info', 0);
        VmMbstring::assignGetInfoResult($frame->returnVar, MbstringState::getInfo($type));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_get_info() expects at most 1 argument, %d given',
                $argc
            ));
        }

        // Thin AOT / JIT: fold from MbstringState when the type is known at compile time
        // (avoids NestedJit helper-runtime rebuild under PHP_COMPILER_AOT_USER_SCRIPT; #20014).
        $typeLit = 0 === $argc ? 'all' : JitStringArg::compileTimeLiteral($args[0]);
        if (null === $typeLit) {
            throw new \LogicException(
                'mb_get_info() type must be a compile-time string in this compiler build'
            );
        }

        return self::materialize($context, MbstringState::getInfo($typeLit));
    }

    /**
     * @param array<string, mixed>|string|int|false|null $result
     */
    private static function materialize(Context $context, array|string|int|false|null $result): Value
    {
        if (null === $result) {
            return JitJsonDecode::materializeNull($context);
        }
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        if (\is_int($result)) {
            return $context->getTypeFromString('int64')->constInt($result, true);
        }
        if (\is_string($result)) {
            return $context->builder->load($context->constantStringFromString($result));
        }

        return JitJsonDecode::materializeArray($context, $result);
    }
}
