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

/** preg_match_all() — VM via host PCRE; JIT/AOT via __compiler_preg_match_all (issue #1179). */
final class preg_match_all extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_match_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('preg_match_all() requires 2 to 5 arguments in this compiler build');
        }
        if ($argc >= 3) {
            throw new \LogicException(
                'preg_match_all() with $matches by-reference is not supported in VM in this compiler build (issue #107)'
            );
        }
        $patternVar = $frame->calledArgs[0]->resolveIndirect();
        $subjectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $patternVar->type || Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException('preg_match_all() pattern and subject must be strings in this compiler build');
        }

        $result = VmPreg::pregMatchAll($patternVar->toString(), $subjectVar->toString());

        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(
                'preg_match_all() in JIT/AOT supports exactly two arguments (no $matches, flags, or offset) in this compiler build'
            );
        }

        return JitPregMatchAll::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_match_all() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_match_all() subject')
        );
    }
}
