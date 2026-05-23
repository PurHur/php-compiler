<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** preg_match() — VM via host PCRE; JIT/AOT via __compiler_preg_match (issue #93). */
final class preg_match extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('preg_match() requires 2 to 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc >= 3) {
            throw new \LogicException(
                'preg_match() with $matches by-reference is not supported in VM in this compiler build (issue #107)'
            );
        }
        $patternVar = $frame->calledArgs[0]->resolveIndirect();
        $subjectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $patternVar->type || Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException('preg_match() pattern and subject must be strings in this compiler build');
        }

        $result = VmPreg::pregMatch($patternVar->toString(), $subjectVar->toString());

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
                'preg_match() in JIT/AOT supports exactly two arguments (no $matches, flags, or offset) in this compiler build'
            );
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('preg_match() pattern and subject must be strings in this compiler build');
        }

        return JitPregMatch::invoke(
            $context,
            $context->helper->loadValue($args[0]),
            $context->helper->loadValue($args[1])
        );
    }
}
