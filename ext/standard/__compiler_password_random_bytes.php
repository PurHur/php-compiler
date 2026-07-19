<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * __compiler_password_random_bytes() — CSPRNG for password_hash() nested JIT (#9275).
 *
 * Dedicated ABI so password NestedJIT does not re-enter __compiler_random_bytes
 * while RandomBytesJitHelper is compiling (#21186 keeps user-script random_bytes honest).
 * php-src: ext/standard/random.c
 */
final class __compiler_password_random_bytes extends Internal
{
    public function __construct()
    {
        parent::__construct('__compiler_password_random_bytes');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                '__compiler_password_random_bytes() requires exactly one argument in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            '__compiler_password_random_bytes',
            0,
            'length'
        );
        $frame->returnVar->string(VmString::randomBytes($length));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                '__compiler_password_random_bytes() requires exactly one argument in this compiler build'
            );
        }

        return JitPasswordRandomBytes::generate(
            $context,
            JitLongArg::lower($context, $args[0], '__compiler_password_random_bytes() length')
        );
    }
}
