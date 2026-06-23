<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * random_bytes() — CSPRNG via OS (VM: VmRandomNative/VmRandomPure; JIT/AOT: RandomBytesJitHelper PHP).
 */
final class random_bytes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $length = self::parseLength($v);
        $frame->returnVar->string(VmString::randomBytes($length));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('random_bytes() requires exactly one argument');
        }
        $length = JitRandomBytesArg::lowerLength($context, $args[0]);

        return JitRandomBytes::generate($context, $length);
    }

    /**
     * Z_PARAM_LONG length (ext/standard/random.c; #4626 numeric-string, #6160 enum TypeError).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    private static function parseLength(Variable $var): int
    {
        return VmMath::parseIntBuiltinArg($var, 'random_bytes', 1, 'length');
    }
}
