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

/** escapeshellcmd() — escape shell metacharacters (VM; JIT/AOT via __compiler_escapeshellcmd). */
final class escapeshellcmd extends Internal
{
    public function __construct()
    {
        parent::__construct('escapeshellcmd');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('escapeshellcmd() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('escapeshellcmd() requires a string argument in this compiler build');
        }
        $frame->returnVar->string(\escapeshellcmd($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('escapeshellcmd() requires exactly one argument');
        }

        return JitEscapeshellcmd::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'escapeshellcmd() argument')
        );
    }
}
