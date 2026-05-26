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
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $a->type || Variable::TYPE_STRING !== $b->type) {
            throw new \LogicException('levenshtein() requires two strings in this compiler build');
        }
        $ins = 1;
        $rep = 1;
        $del = 1;
        if ($argc >= 3) {
            $insVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $insVar->type) {
                throw new \LogicException('levenshtein() insertion cost must be an integer in this compiler build');
            }
            $ins = $insVar->toInt();
        }
        if ($argc >= 4) {
            $repVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $repVar->type) {
                throw new \LogicException('levenshtein() replacement cost must be an integer in this compiler build');
            }
            $rep = $repVar->toInt();
        }
        if ($argc >= 5) {
            $delVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $delVar->type) {
                throw new \LogicException('levenshtein() deletion cost must be an integer in this compiler build');
            }
            $del = $delVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::levenshtein($a->toString(), $b->toString(), $ins, $rep, $del));
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
        $i64 = $context->getTypeFromString('int64');
        $ins = $i64->constInt(1, false);
        $rep = $i64->constInt(1, false);
        $del = $i64->constInt(1, false);
        if ($argc >= 3) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('levenshtein() insertion cost must be an integer in this compiler build');
            }
            $ins = $this->jitLong($context, $args[2], 'levenshtein() insertion cost');
        }
        if ($argc >= 4) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
                throw new \LogicException('levenshtein() replacement cost must be an integer in this compiler build');
            }
            $rep = $this->jitLong($context, $args[3], 'levenshtein() replacement cost');
        }
        if ($argc >= 5) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[4]->type) {
                throw new \LogicException('levenshtein() deletion cost must be an integer in this compiler build');
            }
            $del = $this->jitLong($context, $args[4], 'levenshtein() deletion cost');
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'levenshtein() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'levenshtein() argument #2'));
        $fn = $context->lookupFunction('phpc_levenshtein');
        $raw = $context->builder->call($fn, $p0, $p1, $ins, $rep, $del);

        return $context->builder->sExt($raw, $i64);
    }
}
