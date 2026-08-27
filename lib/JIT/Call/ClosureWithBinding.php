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
 *
 * When {@see $closureObject} is set (cross-function `$f = $obj->m(); $f()`), reload
 * bound `$this` from the Closure heap slots — the create-time snapshot Variable may
 * point at a method-local alloca that is dead after return (#35456, peer #28612).
 */
final class ClosureWithBinding implements Call
{
    public function __construct(
        private readonly Call $inner,
        private readonly Variable $boundThis,
        private readonly Variable $boundScope,
        private readonly ?Variable $closureObject = null,
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

    /** Closure object whose `__closure_bound_this` should be read at invoke (#35456). */
    public function closureObject(): ?Variable
    {
        return $this->closureObject;
    }

    /** Prefer heap-bound `$this` from a Closure object Variable (#35456). */
    public function withClosureObject(Variable $closureObject): self
    {
        $inner = $this->inner;
        while ($inner instanceof self) {
            $inner = $inner->inner();
        }

        return new self(
            $inner,
            $this->boundThis,
            $this->boundScope,
            $closureObject
        );
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if (null !== $this->closureObject) {
            $obj = $context->helper->loadValue($this->closureObject);

            return ClosureBindHelper::wrapCallWithBindingFromObject(
                $context,
                $obj,
                $this->inner,
                ...$args
            );
        }

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
