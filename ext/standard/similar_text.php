<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSimilarText;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * similar_text() — similarity between two strings (subset of PHP; issue #2445).
 */
final class similar_text extends Internal
{
    public function __construct()
    {
        parent::__construct('similar_text');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('similar_text() accepts two or three arguments in this compiler build');
        }
        $s1 = self::vmStringArg($frame, 0, 'string1');
        $s2 = self::vmStringArg($frame, 1, 'string2');
        if (3 === $argc) {
            $percent = 0.0;
            $sim = VmString::similar_text($s1, $s2, $percent);
            $frame->calledArgs[2]->resolveIndirect()->float($percent);
        } else {
            $sim = VmString::similar_text($s1, $s2);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($sim);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('similar_text() accepts two or three arguments in this compiler build');
        }
        StringSimilarText::ensureLinked($context);
        $str0 = self::jitStringArg($context, $args[0], 1, 'string1');
        $str1 = self::jitStringArg($context, $args[1], 2, 'string2');
        $p0 = $this->stringDataPtr($context, $str0);
        $p1 = $this->stringDataPtr($context, $str1);
        $fn = $context->lookupFunction('phpc_similar_text');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($fn, $p0, $p1);
        $sim = $context->builder->sExt($raw, $i64);

        if (3 === $argc) {
            $this->jitWriteSimilarityPercent($context, $p0, $p1, $sim, $args[2]);
        }

        return $sim;
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireString($frame, $argIndex, 'similar_text', $paramName)->toString();
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            'similar_text',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argNumber,
        string $paramName
    ): Value {
        JitInternalStrictArg::requireString($context, $arg, 'similar_text', $paramName, $argNumber);

        return JitStringBuiltinArg::lower(
            $context,
            $arg,
            'similar_text',
            $argNumber - 1,
            $paramName
        );
    }

    private function jitWriteSimilarityPercent(
        Context $context,
        Value $strData0,
        Value $strData1,
        Value $sim,
        JITVariable $percentArg
    ): void {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $strlenFn = $context->lookupFunction('strlen');
        $len1 = $context->builder->zExt($context->builder->call($strlenFn, $strData0), $i64);
        $len2 = $context->builder->zExt($context->builder->call($strlenFn, $strData1), $i64);
        $sum = $context->builder->add($len1, $len2);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $sum, $i64->constInt(0, false));
        $simD = $context->builder->siToFp($sim, $double);
        $numer = $context->builder->fMul($simD, $double->constReal(200.0));
        $den = $context->builder->uitofp($sum, $double);
        $pct = $context->builder->select($isZero, $double->constReal(0.0), $context->builder->fDiv($numer, $den));
        $outPtr = JitValueBox::valuePtrFromVariable($context, $percentArg);
        $context->builder->call($context->lookupFunction('__value__writeDouble'), $outPtr, $pct);
    }
}
