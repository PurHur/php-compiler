<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('escapeshellcmd() requires exactly one argument');
        }
        $command = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'escapeshellcmd', 0, 'command');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($command): void {
            $ret->string(\escapeshellcmd($command));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('escapeshellcmd() requires exactly one argument');
        }

        return JitEscapeshellcmd::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'escapeshellcmd', 0, 'command')
        );
    }
}
