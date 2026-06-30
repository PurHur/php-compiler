<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_match_all() pattern', 0);
        $subject = VmReflection::stringArg($frame->calledArgs[1], 'preg_match_all() subject', 1);
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_match_all', $pattern);

        $flags = 0;
        $offset = 0;
        $hasMatches = $argc >= 3;
        if ($argc >= 4) {
            $flagsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('preg_match_all() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 5) {
            $offset = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                4,
                'preg_match_all',
                4,
                'offset'
            );
        }

        $hostMatches = [];
        $result = VmPreg::pregMatchAll($pattern, $subject, $hostMatches, $flags, $offset);

        if ($hasMatches) {
            $target = $frame->calledArgs[2];
            if (false === $result) {
                $target->null();
            } else {
                $target = $target->resolveIndirect();
                $ht = VmPregMatches::hostMatchAllToHashTable($hostMatches, $flags);
                $target->array($ht);
            }
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
        return JitPregMatchAllEx::invoke($context, ...$args);
    }
}
