<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Closure invoke with bindTo/bind scope and $this (issue #4192, Zend zend_closures.c).
 */
final class ClosureWithBinding implements Call
{
    public function __construct(
        private readonly Call $inner,
        private readonly Variable $boundThis,
        private readonly Variable $boundScope,
    ) {
    }

    public function inner(): Call
    {
        return $this->inner;
    }

    /** Bound $this snapshot (may be null-capture for unbound). */
    public function boundThis(): Variable
    {
        return $this->boundThis;
    }

    /** Prior class scope string (empty when unbound). */
    public function boundScope(): Variable
    {
        return $this->boundScope;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $savedCalled = $context->scope->calledClassName;
        $scopeName = ClosureBindHelper::compileTimeScopeName($this->boundScope);
        if ('' !== $scopeName) {
            $context->scope->calledClassName = $scopeName;
        }
        $innerArgs = ClosureBindHelper::prependBoundThisForInvoke(
            $context,
            ClosureBindHelper::unwrapInnerCall($this->inner),
            $this->boundThis,
            $args
        );
        $result = $this->inner->call($context, ...$innerArgs);
        $context->scope->calledClassName = $savedCalled;

        return $result;
    }
}
