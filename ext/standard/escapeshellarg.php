<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** escapeshellarg() — shell-safe quoting (VM; JIT/AOT via __compiler_escapeshellarg). */
final class escapeshellarg extends Internal
{
    public function __construct()
    {
        parent::__construct('escapeshellarg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('escapeshellarg() expects exactly 1 argument, '.$argc.' given');
        }
        // php-src ext/standard/exec.c — Z_PARAM_STR; null → E_DEPRECATED + '' on 8.4 (#21221, re-#19333)
        $arg = VmString::trimFamilyStringArgForFrame($frame, 0, 'escapeshellarg', 0, 'arg');
        VmString::rejectNullByteBuiltinStringArg($arg, 'escapeshellarg', 0, 'arg');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($arg): void {
            $ret->string(VmEscapeshell::escapeshellarg($arg));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('escapeshellarg() expects exactly 1 argument, '.\count($args).' given');
        }

        if ($context->callerStrictTypes) {
            return JitEscapeshellarg::invoke(
                $context,
                JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[0],
                    'escapeshellarg',
                    0,
                    'arg'
                )
            );
        }

        // Soft-null → DEP + escapeshellarg("") without ProcessRuntime IR (#21221 / #21199).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'escapeshellarg', 0, 'arg');

            return self::returnConstantQuoted($context, VmEscapeshell::escapeshellarg(''));
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null !== $lit) {
            VmString::rejectNullByteBuiltinStringArg($lit, 'escapeshellarg', 0, 'arg');

            return self::returnConstantQuoted($context, VmEscapeshell::escapeshellarg($lit));
        }

        return JitEscapeshellarg::invoke(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'escapeshellarg',
                0,
                'arg'
            )
        );
    }

    private static function returnConstantQuoted(Context $context, string $quoted): Value
    {
        return JitValueBox::coerceToValuePtrForStore(
            $context,
            $context->builder->load($context->constantStringFromString($quoted))
        );
    }
}
