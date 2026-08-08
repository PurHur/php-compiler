<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringHex2bin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * hex2bin() for strings (subset of PHP; JIT/AOT via Hex2binJitHelper PHP).
 *
 * php-src ext/standard/string.c — arity 1 only; no $strict (#27763).
 */
final class hex2bin extends Internal
{
    private const MSG_ODD_LENGTH = 'Hexadecimal input string must have an even length';

    private const MSG_INVALID_HEX = 'Input string must be hexadecimal string';

    private const WARN_ODD_LENGTH = 'hex2bin(): '.self::MSG_ODD_LENGTH;

    private const WARN_INVALID_HEX = 'hex2bin(): '.self::MSG_INVALID_HEX;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('hex2bin() expects at least 1 argument, 0 given');
        }
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('hex2bin() expects exactly 1 argument, %d given', $argc)
            );
        }
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'hex2bin', 0, 'string');
        $len = VmString::byteLength($data);
        if ($len > 0 && 0 !== ($len & 1)) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::WARN_ODD_LENGTH,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        $result = VmString::hex2bin($data, false);
        if (false === $result) {
            if ($len > 0 && null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    self::WARN_INVALID_HEX,
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });

            return;
        }
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'hex2bin() expects at least 1 argument, 0 given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('hex2bin() expects exactly 1 argument, %d given', $argc)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Fold compile-time literals (peer base64_decode #26890) — keeps AOT fixtures off the
        // runtime value-box === false path when args are constants (#27008).
        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        }
        if (null !== $literal) {
            $result = VmString::hex2bin($literal, false);
            if (false === $result) {
                return $context->constantFromBool(false);
            }

            return $context->builder->load(
                $context->constantStringFromString($result)
            );
        }

        StringHex2bin::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);
        $strictI8 = $context->getTypeFromString('int8')->constInt(0, false);
        $context->builder->call(
            $context->lookupFunction('__compiler_hex2bin'),
            self::jitStringArg($context, $args[0]),
            $strictI8,
            $outPtr
        );

        return $outPtr;
    }

    /** Soft-null on 8.4 forward profile (#21209, ext/standard/string.c hex2bin). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'hex2bin', 0, 'string');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'hex2bin', 0, 'string');
    }
}
