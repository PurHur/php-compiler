<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSimilarText;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * similar_text() — Oliver string similarity (subset of PHP; issue #2445).
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
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $a->type || Variable::TYPE_STRING !== $b->type) {
            throw new \LogicException('similar_text() requires two strings in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $percent = null;
        if ($argc >= 3) {
            $percent = 0.0;
        }
        $sim = VmString::similarText($a->toString(), $b->toString(), $percent);
        if ($argc >= 3) {
            $frame->calledArgs[2]->resolveIndirect()->float((float) $percent);
        }
        $frame->returnVar->int($sim);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringSimilarText::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('similar_text() accepts two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $doubleTy = $context->getTypeFromString('double');
        $doublePtr = $context->getTypeFromString('double*');
        $percentOut = $doublePtr->constNull();
        $percentViaTemp = false;
        if ($argc >= 3) {
            if (
                JITVariable::TYPE_NATIVE_DOUBLE === $args[2]->type
                && JITVariable::KIND_VARIABLE === $args[2]->kind
            ) {
                $percentOut = $args[2]->value;
            } else {
                $percentOut = $context->builder->alloca($doubleTy, 1, 'similar_text_percent');
                $percentViaTemp = true;
            }
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'similar_text() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'similar_text() argument #2'));
        $fn = $context->lookupFunction('phpc_similar_text');
        $raw = $context->builder->call($fn, $p0, $p1, $percentOut);
        if ($percentViaTemp) {
            self::jitStoreDouble($context, $args[2], $context->builder->load($percentOut));
        }

        return $context->builder->sExt($raw, $i64);
    }

    private static function jitStoreDouble(Context $context, JITVariable $dest, Value $doubleVal): void
    {
        if (JITVariable::TYPE_VALUE === $dest->type || JitValueBox::isValueOperand($dest)) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::valuePtrFromVariable($context, $dest),
                $doubleVal
            );

            return;
        }
        if (
            JITVariable::TYPE_NATIVE_DOUBLE === $dest->type
            && JITVariable::KIND_VARIABLE === $dest->kind
        ) {
            $context->builder->store($doubleVal, $dest->value);

            return;
        }
        throw new \LogicException(
            'similar_text() percent must be a float variable in this compiler build'
        );
    }
}
