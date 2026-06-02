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

/**
 * preg_replace() — VM via VmPreg; JIT/AOT via __compiler_preg_replace (issue #1176).
 * Optional $limit (4th arg): VM (#3605); JIT/AOT deferred until native runtime supports it.
 */
final class preg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_replace() pattern');
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_replace() replacement');
        $subjectVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException(
                'preg_replace() subject must be a string in this compiler build'
            );
        }
        $limit = -1;
        if (4 === $argc) {
            $limitVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \LogicException(
                    'preg_replace() limit must be an integer in this compiler build'
                );
            }
            $limit = $limitVar->toInt();
        }
        $result = VmPreg::pregReplace($pattern, $replacement, $subjectVar->toString(), $limit);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        if ($argc >= 4) {
            throw new \LogicException(
                'preg_replace() limit is not supported in JIT/AOT in this compiler build (issue #3605)'
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
