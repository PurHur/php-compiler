<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;

/**
 * Closure body compile, self/static/parent scope, and $this LLVM param offset (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code resolveClassNameForPseudoConst}
 * through {@code instanceMethodUsesThis} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c / zend_execute_API.c class scope for self/static/parent
 * and $this binding for instance methods / Closure::call — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change.
 */
trait ClosureThisAndStaticScopeResolve
{
    /**
     * @return array<int, Variable>
     */
    private function resolveClassNameForPseudoConst(Block $block, Operand $classOp): string
    {
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Class::class requires a literal class name for JIT/AOT');
        }

        return $this->resolveJitStaticScopeClass($block, $classOp);
    }

    /**
     * Copy enclosing method/trait class onto a nested closure Func before its body is lowered.
     *
     * php-cfg leaves closure Func->class unset; VM installs it at TYPE_CLOSURE runtime (#25793).
     * AOT compiles the body first, so self::class otherwise hits PseudoClassScope::fatalInGlobalScope
     * (#26459 — __CLASS__ in traits rewrites to self::class).
     */
    private function propagateEnclosingClassOntoClosureFunc(Block $enclosing, Block $closureBody): void
    {
        if (null === $closureBody->func) {
            return;
        }
        $existing = $closureBody->func->class;
        if (null !== $existing && null !== $existing->value && '' !== (string) $existing->value) {
            return;
        }
        if (null === $enclosing->func || null === $enclosing->func->class) {
            return;
        }
        $enclosingClass = $enclosing->func->class;
        if (null === $enclosingClass->value || '' === (string) $enclosingClass->value) {
            return;
        }
        $closureBody->func->class = $enclosingClass;
    }

    /**
     * Compile a nested closure body while preserving trait composing / class scope (#26459).
     *
     * Nested {@see compileBlock} of closures can leave scope->classId pointing at a prior
     * NestedJIT helper class; keep traitComposingClassName and prefer it for self::class.
     */
    private function compileClosureBodyBlock(Block $enclosing, Block $closureBody, string $internalName): void
    {
        $this->propagateEnclosingClassOntoClosureFunc($enclosing, $closureBody);
        $savedComposing = $this->context->scope->traitComposingClassName;
        $savedClassName = $this->context->scope->className;
        $savedClassId = $this->context->scope->classId;
        if ('' === $savedComposing) {
            // Inherit composing from enclosing method compile (set in trait flatten / runQueue).
            if ('' !== $savedClassName
                && !$this->context->type->object->isTraitClass(strtolower(ltrim($savedClassName, '\\')))) {
                if ($this->context->type->object->hasDeclaredClass($savedClassName)) {
                    $this->context->scope->traitComposingClassName = $this->context->type->object->classNameForId(
                        $this->context->type->object->lookup($savedClassName)
                    );
                } else {
                    $this->context->scope->traitComposingClassName = $savedClassName;
                }
            }
        }
        try {
            $this->compileBlock($closureBody, $internalName);
        } finally {
            $this->context->scope->traitComposingClassName = $savedComposing;
            $this->context->scope->className = $savedClassName;
            $this->context->scope->classId = $savedClassId;
        }
    }

    private function resolveJitStaticScopeClass(Block $block, Operand\Literal $classOp): string
    {
        $lc = strtolower($classOp->value);
        if ('self' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalNoActiveClassScope('self');
            }
            $declaringClass = $block->func->class->value;
            $declaringLc = strtolower(ltrim($declaringClass, '\\'));
            if ($this->context->type->object->isTraitClass($declaringLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                    return $composing;
                }
                // Prefer scope->className before classId: NestedJIT leaves classId on the last
                // helper class (e.g. DirHandleJitHelper) when compiling nested closures (#26459).
                $scopeName = $this->context->scope->className;
                if ('' !== $scopeName && !$this->context->type->object->isTraitClass(strtolower(ltrim($scopeName, '\\')))) {
                    if ($this->context->type->object->hasDeclaredClass($scopeName)) {
                        return $this->context->type->object->classNameForId(
                            $this->context->type->object->lookup($scopeName)
                        );
                    }

                    return $scopeName;
                }
                if ($this->context->scope->classId > 0) {
                    $fromId = $this->context->type->object->classNameForId($this->context->scope->classId);
                    if ('' !== $fromId && !$this->context->type->object->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                        return $fromId;
                    }
                }
            }

            return $declaringClass;
        }
        if ('static' === $lc) {
            if ($this->context->scope->calledClassName !== '') {
                return $this->context->scope->calledClassName;
            }
            if (null !== $block->func && null !== $block->func->class) {
                return $block->func->class->value;
            }
            PseudoClassScope::fatalNoActiveClassScope('static');
        }
        if ('parent' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                PseudoClassScope::fatalNoActiveClassScope('parent');
            }
            $declaringClass = $block->func->class->value;
            $declaringLc = strtolower(ltrim($declaringClass, '\\'));
            if ($this->context->type->object->isTraitClass($declaringLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                    $declaringClass = $composing;
                } elseif ($this->context->scope->classId > 0) {
                    $fromId = $this->context->type->object->classNameForId($this->context->scope->classId);
                    if ('' !== $fromId && !$this->context->type->object->isTraitClass(strtolower(ltrim($fromId, '\\')))) {
                        $declaringClass = $fromId;
                    }
                } else {
                    $scopeName = $this->context->scope->className;
                    if ('' !== $scopeName && !$this->context->type->object->isTraitClass(strtolower(ltrim($scopeName, '\\')))) {
                        $declaringClass = $scopeName;
                    } else {
                        $called = $this->context->scope->calledClassName;
                        if ('' !== $called && strtolower(ltrim($called, '\\')) !== $declaringLc) {
                            $declaringClass = $called;
                        }
                    }
                }
            }
            $parentLc = $this->context->type->object->parentClassLc($declaringClass);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $classOp->value;
    }

    private function jitIsClassSameOrSubclassOf(string $classLc, string $ancestorLc): bool
    {
        $current = strtolower(ltrim($classLc, '\\'));
        $ancestorLc = strtolower(ltrim($ancestorLc, '\\'));
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            $parentLc = $this->context->type->object->parentClassLc($current);
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }

    private function blockUsesThis(Block $block): bool
    {
        foreach ($block->orig->hoistedOperands as $hoisted) {
            if ('this' === JIT\OperandName::resolve($hoisted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Free closures that read $this need an implicit __object__* param so Closure::call /
     * bindTo can prepend temporary $this (JIT.pre blockUsesThis; #26872).
     */
    private function closureBodyUsesThis(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        if (0 === (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }

        return $this->blockUsesThis($block);
    }

    /** 1 when LLVM param 0 is $this (instance method or this-using closure) (#26872). */
    private function llvmThisParamOffset(Block $block): int
    {
        return ($this->instanceMethodUsesThis($block) || $this->closureBodyUsesThis($block)) ? 1 : 0;
    }

    private function instanceMethodUsesThis(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }
        // Closures inherit func->class via propagateEnclosingClassOntoClosureFunc (#26459).
        // Only closureBodyUsesThis should add LLVM $this (#27163).
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) {
            return false;
        }
        if (null !== $block->func->class) {
            return true;
        }
        // Nested file JIT: func->class may be unset while scope carries the declaring class (#16075).
        // Do not treat leftover scope->className as applying to {main}, closures, or free functions
        // — that adds a spurious __object__* / thisParamOffset (standalone main #22638; clone-with
        // IIFE #23046; c07_method f($m->g(1),$m->g(2)) "Missing required argument 1" #23971).
        if (
            '' !== $this->context->scope->className
            && '{main}' !== $block->func->name
            && 0 === (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
        ) {
            $methodLc = strtolower($block->func->name);
            $classLc = strtolower(ltrim($this->context->scope->className, '\\'));
            $proxyLc = $classLc.'::'.$methodLc;
            if (
                $this->context->functionIsRegistered($proxyLc)
                || isset($this->context->functions[$proxyLc])
            ) {
                return true;
            }
        }

        return str_contains($block->func->getScopedName(), '::');
    }
}
