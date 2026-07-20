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

/** escapeshellcmd() — escape shell metacharacters (VM; JIT/AOT via __compiler_escapeshellcmd). */
final class escapeshellcmd extends Internal
{
    public function __construct()
    {
        parent::__construct('escapeshellcmd');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('escapeshellcmd() expects exactly 1 argument, '.$argc.' given');
        }
        // php-src ext/standard/exec.c — Z_PARAM_STR; null → E_DEPRECATED + '' on 8.4 (#21221, re-#19333)
        $command = VmString::trimFamilyStringArgForFrame($frame, 0, 'escapeshellcmd', 0, 'command');
        VmString::rejectNullByteBuiltinStringArg($command, 'escapeshellcmd', 0, 'command');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($command): void {
            $ret->string(VmEscapeshell::escapeshellcmd($command));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('escapeshellcmd() expects exactly 1 argument, '.\count($args).' given');
        }

        if ($context->callerStrictTypes) {
            return JitEscapeshellcmd::invoke(
                $context,
                JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[0],
                    'escapeshellcmd',
                    0,
                    'command'
                )
            );
        }

        // Soft-null → DEP + escapeshellcmd("") without ProcessRuntime IR (#21221 / #21199).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'escapeshellcmd', 0, 'command');

            return self::returnConstantQuoted($context, VmEscapeshell::escapeshellcmd(''));
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null !== $lit) {
            VmString::rejectNullByteBuiltinStringArg($lit, 'escapeshellcmd', 0, 'command');

            return self::returnConstantQuoted($context, VmEscapeshell::escapeshellcmd($lit));
        }

        return JitEscapeshellcmd::invoke(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'escapeshellcmd',
                0,
                'command'
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
