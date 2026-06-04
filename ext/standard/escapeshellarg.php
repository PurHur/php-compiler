<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('escapeshellarg() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arg = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'escapeshellarg', 0, 'arg');
        $frame->returnVar->string(\escapeshellarg($arg));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('escapeshellarg() requires exactly one argument');
        }

        return JitEscapeshellarg::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'escapeshellarg', 0, 'arg')
        );
    }
}
