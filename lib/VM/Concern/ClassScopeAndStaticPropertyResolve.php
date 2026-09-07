<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * Class-scope keywords + static property storage resolve for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resolveStaticClassName} through
 * {@code inferCalledClass} (php-src Zend/zend_execute.c FETCH_CLASS /
 * FETCH_STATIC_PROP; Zend/zend_inheritance.c trait {@code parent} late-bind —
 * #5477, #4668, #31747, #31912, #3673). Concern trait — same namespace as parent
 * so relative Frame / Block helpers resolve. Move-only; no new C ABI.
 */
trait ClassScopeAndStaticPropertyResolve
{
    protected function resolveStaticClassName(string $className, Frame $frame): string
    {
        return $this->resolveClassScopeName($className, $frame);
    }

    /**
     * Resolve the class for $operand::$prop when the left side is a class name or instance (#5477).
     */
    protected function resolveStaticPropertyClassLc(Variable $classOperand, Frame $frame): string
    {
        $classOperand = $classOperand->resolveIndirect();
        if (Variable::TYPE_OBJECT === $classOperand->type) {
            return strtolower($classOperand->toObject()->class->name);
        }

        return $this->resolveStaticClassName($classOperand->toString(), $frame);
    }

    /**
     * Static property storage for $class::$prop, walking ancestors (Zend inheritance; #4668).
     */
    protected function resolveStaticPropertyStorage(string $classLc, string $propLc): ?Variable
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticProperties[$propLc])) {
                return $entry->staticProperties[$propLc];
            }
            if (null === $entry->parentLc) {
                break;
            }
            $currentLc = $entry->parentLc;
        }

        return null;
    }

    /** True when $cell is a class static property slot (not a frame local). */
    private function isStaticPropertyStorageCell(Variable $cell): bool
    {
        foreach ($this->context->classes as $entry) {
            foreach ($entry->staticProperties as $storage) {
                if ($storage === $cell) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Late-bind trait `parent` param/return typehints to the composing class parent (#31747).
     *
     * Trait methods keep the lexical keyword on the shared Block (Reflection); type checks
     * resolve against the using class like zend_inheritance.c trait method copy.
     */
    public function resolveParentTypeHintClassLc(): ?string
    {
        $frame = $this->executingFrame;
        if (null === $frame) {
            return null;
        }
        try {
            return $this->resolveClassScopeName('parent', $frame);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Display name for TypeError expected type when resolving trait `parent` (#31747). */
    public function resolveParentTypeHintClassName(): ?string
    {
        $parentLc = $this->resolveParentTypeHintClassLc();
        if (null === $parentLc || '' === $parentLc) {
            return null;
        }
        $entry = $this->context->classes[$parentLc] ?? null;

        return null !== $entry && '' !== $entry->name ? $entry->name : $parentLc;
    }

    protected function resolveClassScopeName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            return $this->declaringClassLc($frame, 'self');
        }
        if ('static' === $lcClass) {
            return $this->lateStaticClassLc($frame);
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'parent');
            if (!isset($this->context->classes[$declaring])) {
                PseudoClassScope::fatalNoActiveClassScope('parent');
            }
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    protected function declaringClassLc(Frame $frame, string $scopeKeyword = 'self'): string
    {
        $boundScope = $this->boundClosureScopeClassLc($frame);
        if (null !== $boundScope) {
            return $boundScope;
        }
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            $funcClassValue = $frame->block->func->class->value;
            $funcClassLc = strtolower($funcClassValue);
            if ('parent' === $scopeKeyword || 'self' === $scopeKeyword) {
                $funcIsTrait = ($this->context->classes[$funcClassLc] ?? null)?->isTrait ?? false;
                if ($funcIsTrait) {
                    return VM\TraitSelfClassScope::resolveComposingClassLc(
                        $funcClassValue,
                        true,
                        $frame->calledClass,
                        $funcClassLc,
                        strtolower($frame->block->func->name),
                        fn (string $classLc, string $method): ?string => $this->context->classes[$classLc]->traitMethodSources[$method] ?? null,
                        fn (string $classLc): ?string => $this->context->classes[$classLc]->parentLc ?? null,
                        fn (string $classLc): bool => ($this->context->classes[$classLc] ?? null)?->isTrait ?? false,
                    );
                }
            }

            return $funcClassLc;
        }
        // eval/include {main}: declaring class copied from the caller (#31912).
        if (null !== $frame->scopeClass && '' !== $frame->scopeClass) {
            return strtolower($frame->scopeClass);
        }
        // Bound closure scope (Closure::bind/bindTo $newScope) — #3673.
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        PseudoClassScope::fatalNoActiveClassScope($scopeKeyword);
    }

    protected function lateStaticClassLc(Frame $frame): string
    {
        return VM\LateStaticBinding::resolveLateStaticClassLc(
            $frame->calledClass,
            $this->declaringClassLc($frame, 'static')
        );
    }

    protected function inferCalledClass(Frame $frame): ?string
    {
        if (null !== $frame->staticCallClass) {
            $called = $frame->staticCallClass;
            $frame->staticCallClass = null;

            return $called;
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $receiver->toObject()->class->name;
            }
        }

        return $frame->calledClass;
    }
}
