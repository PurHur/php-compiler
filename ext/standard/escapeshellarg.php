<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('escapeshellarg() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('escapeshellarg() requires a string argument in this compiler build');
        }
        $frame->returnVar->string(\escapeshellarg($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('escapeshellarg() requires exactly one argument');
        }

        return JitEscapeshellarg::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'escapeshellarg() argument')
        );
    }
}
