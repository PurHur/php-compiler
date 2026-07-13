<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        $pattern = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'preg_match', 0, 'pattern');
        $subject = InternalStrictArg::resolveCoercibleStringArg($frame, 1, 'preg_match', 'subject');
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_match', $pattern);

        $flags = 0;
        $offset = 0;
        $hasMatches = isset($frame->calledArgs[2]);
        if (isset($frame->calledArgs[3])) {
            $flagsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('preg_match() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if (isset($frame->calledArgs[4])) {
            $offset = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                4,
                'preg_match',
                5,
                'offset'
            );
        }

        $hostMatches = [];
        $result = VmPreg::pregMatch($pattern, $subject, $hostMatches, $flags, $offset);

        if ($hasMatches && false !== $result) {
            $target = $frame->calledArgs[2]->resolveIndirect();
            $ht = VmPregMatches::hostMatchesToHashTable($hostMatches, $flags);
            $target->array($ht);
        }

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
        return JitPregMatchEx::invoke($context, ...$args);
    }
}
