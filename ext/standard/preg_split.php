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

/** preg_split() — VM via VmPreg; JIT/AOT via __compiler_preg_split (issue #1178, #3639). */
final class preg_split extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(
                'preg_split() expects 2 to 4 arguments in this compiler build'
            );
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_split() pattern');
        $subjectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException(
                'preg_split() subject must be a string in this compiler build'
            );
        }
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            $limitVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \LogicException(
                    'preg_split() limit must be an integer in this compiler build'
                );
            }
            $limit = $limitVar->toInt();
        }
        if (4 === $argc) {
            $flagsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException(
                    'preg_split() flags must be an integer in this compiler build'
                );
            }
            $flags = $flagsVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parts = VmPreg::pregSplit($pattern, $subjectVar->toString(), $limit, $flags);
        if (false === $parts) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmPreg::splitPartsToHashTable($parts, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(
                'preg_split() expects 2 to 4 arguments in this compiler build'
            );
        }
        if ($argc >= 3) {
            throw new \LogicException(
                'preg_split() limit and flags are not supported in JIT/AOT in this compiler build (issue #3639)'
            );
        }

        return JitPregSplit::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_split() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_split() subject')
        );
    }
}
