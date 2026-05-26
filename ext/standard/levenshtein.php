<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringLevenshtein;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * levenshtein() — edit distance between two strings (subset of PHP; issue #2406).
 */
final class levenshtein extends Internal
{
    public function __construct()
    {
        parent::__construct('levenshtein');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('levenshtein() accepts two to five arguments in this compiler build');
        }
        $s1 = $frame->calledArgs[0]->resolveIndirect();
        $s2 = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $s1->type || Variable::TYPE_STRING !== $s2->type) {
            throw new \LogicException('levenshtein() requires two strings in this compiler build');
        }
        $ins = 1;
        $repl = 1;
        $del = 1;
        if ($argc >= 3) {
            $insArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $insArg->type) {
                throw new \LogicException('levenshtein() insertion cost must be an integer in this compiler build');
            }
            $ins = $insArg->toInt();
        }
        if ($argc >= 4) {
            $replArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $replArg->type) {
                throw new \LogicException('levenshtein() replacement cost must be an integer in this compiler build');
            }
            $repl = $replArg->toInt();
        }
        if ($argc >= 5) {
            $delArg = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $delArg->type) {
                throw new \LogicException('levenshtein() deletion cost must be an integer in this compiler build');
            }
            $del = $delArg->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::levenshtein(
            $s1->toString(),
            $s2->toString(),
            $ins,
            $repl,
            $del
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringLevenshtein::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('levenshtein() accepts two to five arguments in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $ins = $i32->constInt(1, false);
        $repl = $i32->constInt(1, false);
        $del = $i32->constInt(1, false);
        if ($argc >= 3) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('levenshtein() insertion cost must be an integer in this compiler build');
            }
            $ins = $context->builder->trunc(
                $this->jitLong($context, $args[2], 'levenshtein() insertion cost'),
                $i32
            );
        }
        if ($argc >= 4) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
                throw new \LogicException('levenshtein() replacement cost must be an integer in this compiler build');
            }
            $repl = $context->builder->trunc(
                $this->jitLong($context, $args[3], 'levenshtein() replacement cost'),
                $i32
            );
        }
        if ($argc >= 5) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[4]->type) {
                throw new \LogicException('levenshtein() deletion cost must be an integer in this compiler build');
            }
            $del = $context->builder->trunc(
                $this->jitLong($context, $args[4], 'levenshtein() deletion cost'),
                $i32
            );
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'levenshtein() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'levenshtein() argument #2'));
        $fn = $context->lookupFunction('levenshtein');
        $raw = $context->builder->call($fn, $p0, $p1, $ins, $repl, $del);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
