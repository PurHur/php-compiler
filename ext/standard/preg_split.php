<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_split() pattern', 0);
        $subject = VmReflection::stringArg($frame->calledArgs[1], 'preg_split() subject', 1);
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_split', $pattern);
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
        $parts = VmPreg::pregSplit($pattern, $subject, $limit, $flags);
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
        $patternLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $subjectLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        if (null !== $patternLit && null !== $subjectLit) {
            $limit = -1;
            $flags = 0;
            if ($argc >= 3) {
                if (JITVariable::TYPE_INTEGER !== $args[2]->type) {
                    throw new \LogicException(
                        'preg_split() limit must be an integer in this compiler build'
                    );
                }
                $limit = (int) $args[2]->value->toLong();
            }
            if (4 === $argc) {
                if (JITVariable::TYPE_INTEGER !== $args[3]->type) {
                    throw new \LogicException(
                        'preg_split() flags must be an integer in this compiler build'
                    );
                }
                $flags = (int) $args[3]->value->toLong();
            }
            $parts = VmPreg::pregSplit($patternLit, $subjectLit, $limit, $flags);
            if (false === $parts) {
                return $context->getTypeFromString('bool')->constInt(0, false);
            }
            $ht = VmPreg::splitPartsToHashTable($parts, $flags);

            return $context->constantArrayFromVmHashTable(
                'preg_split_'.md5($patternLit."\0".$subjectLit."\0".$limit."\0".$flags),
                $ht
            );
        }
        $limit = $context->getTypeFromString('int64')->constInt(-1, true);
        $flags = $context->getTypeFromString('int64')->constInt(0, false);
        if ($argc >= 3) {
            $limit = JitLongArg::lower($context, $args[2], 'preg_split() limit');
        }
        if (4 === $argc) {
            $flags = JitLongArg::lower($context, $args[3], 'preg_split() flags');
        }

        return JitPregSplit::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'preg_split', 0, 'pattern'),
            JitStringBuiltinArg::lower($context, $args[1], 'preg_split', 1, 'subject'),
            $limit,
            $flags
        );
    }
}
