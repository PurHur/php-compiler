<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\JitOpensslRandomPseudoBytes;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_cipher_iv_length() — cipher IV length probe (php-src ext/openssl/openssl.c; #7331).
 *
 * VM: VmOpenssl + OpensslCipherRegistry. JIT/AOT: compile-time literal baking via JitOpensslCipherIvLength.
 */
final class openssl_cipher_iv_length extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cipher_iv_length');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_cipher_iv_length() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $cipher = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'openssl_cipher_iv_length',
            0,
            'cipher_algo'
        );
        VmString::rejectEmptyBuiltinStringArg($cipher, 'openssl_cipher_iv_length', 0, 'cipher_algo');
        $length = VmOpenssl::cipher_iv_length($cipher, $frame);
        if (false === $length) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($length);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'openssl_cipher_iv_length() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'openssl_cipher_iv_length', 0, 'cipher_algo');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        if ('' === ($args[0]->compileTimeString ?? null)) {
            $err = BasicBlockHelper::append($context, 'openssl_cipher_iv_len_empty_err');
            $after = BasicBlockHelper::append($context, 'openssl_cipher_iv_len_empty_after');
            $context->builder->branch($err);
            $context->builder->positionAtEnd($err);
            JitOpensslRandomPseudoBytes::emitEmptyCipherAlgoError(
                $context,
                'openssl_cipher_iv_length(): Argument #1 ($cipher_algo) must not be empty'
            );
            $context->builder->positionAtEnd($after);
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitOpensslCipherIvLength::invoke($context, $args[0]);
    }
}
