<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * random_bytes() — CSPRNG via OS (VM: /dev/urandom; JIT/AOT: libc getrandom).
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
     * Z_PARAM_LONG length — reject enum cases before int-only check (#6160, ext/standard/random.c).
     *
     * @throws \TypeError when an enum case operand is passed (php-src-strict)
     */
    private static function parseLength(Variable $var): int
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
            $given = null !== $enumClass ? $enumClass->name : 'object';
            throw new \TypeError(sprintf(
                'random_bytes(): Argument #1 ($length) must be of type int, %s given',
                $given
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException('random_bytes() only supports integers in this compiler build');
        }

        return $var->toInt();
    }
}
