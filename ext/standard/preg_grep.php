<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** preg_grep() — VM via VmPreg; JIT/AOT via JitPregGrep (issue #1180). */
final class preg_grep extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_grep');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('preg_grep() requires two or three arguments in this compiler build');
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_grep() pattern', 0);
        VmPregFailure::warnPatternCompileFailure($frame, 'preg_grep', $pattern);
        $array = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('preg_grep() second argument must be an array in this compiler build');
        }
        $invert = false;
        if (3 === $argc) {
            $flags = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flags->type) {
                throw new \LogicException('preg_grep() flags must be an integer in this compiler build');
            }
            if (0 !== $flags->toInt() && 1 !== $flags->toInt()) {
                throw new \LogicException(
                    'preg_grep() flags must be 0 or PREG_GREP_INVERT (1) in this compiler build'
                );
            }
            $invert = 1 === $flags->toInt();
        }
        $src = $array->toArray();
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $value = $value->resolveIndirect();
            self::rejectNonStringHaystackValue($value);
            $match = VmPreg::pregMatch($pattern, $value->toString());
            if (false === $match) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            $keep = 1 === $match;
            if ($invert) {
                $keep = !$keep;
            }
            if ($keep) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($out);
        }
    }

    /**
     * Zend php_preg_grep: haystack elements must convert to string (#5639).
     */
    private static function rejectNonStringHaystackValue(Variable $value): void
    {
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
            throw new \Error(
                'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
            );
        }
        if (Variable::TYPE_STRING !== $value->type) {
            throw new \LogicException(
                'preg_grep() array values must be strings in this compiler build'
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('preg_grep() requires two or three arguments in this compiler build');
        }
        $invert = $context->constantFromBool(false);
        if (3 === $argc) {
            $flags = JitLongArg::lower($context, $args[2], 'preg_grep() flags');
            $i64 = $context->getTypeFromString('int64');
            $one = $i64->constInt(1, true);
            $invert = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $flags, $one);
        }

        return JitPregGrep::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_grep() pattern'),
            $args[1],
            $invert
        );
    }
}
