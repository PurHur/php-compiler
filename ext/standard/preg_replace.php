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

/** preg_replace() — VM via VmPreg; JIT/AOT via __compiler_preg_replace (issue #1176). */
final class preg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \LogicException(
                'preg_replace() requires exactly three arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_replace() pattern');
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_replace() replacement');
        $subjectVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException(
                'preg_replace() subject must be a string in this compiler build'
            );
        }
        $result = VmPreg::pregReplace($pattern, $replacement, $subjectVar->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'preg_replace() requires exactly three arguments in this compiler build'
            );
        }

        return JitPregReplace::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_replace() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_replace() replacement'),
            JitStringArg::lower($context, $args[2], 'preg_replace() subject')
        );
    }
}
