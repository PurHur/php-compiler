<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPTypes\Type;

/**
 * Property declaring-class / receiver-class resolution for JIT/AOT (#36387).
 *
 * Extracted from {@see CoerceReturnPropertyDeclaringAndByRef}:
 * {@code resolveInstanceMethodReceiverClass} through {@code externalPropertyResultUserType}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_object_handlers.c (zend_get_property_info / zend_std_read_property),
 * Zend/zend_execute.c — move-only Concern extract; no new C ABI.
 */
trait PropertyDeclaringClassResolve
{
    /** PHPCfg operand names for inventory argv Runtime spine (#11809). */
    private function resolveInstanceMethodReceiverClass(Operand $receiverOp): ?string
    {
        if ($this->context->hasVariableOp($receiverOp)) {
            $fromConstraint = $this->typedPropertyClassConstraintUserType(
                $this->context->getVariableFromOp($receiverOp)
            );
            if (null !== $fromConstraint) {
                return $fromConstraint;
            }
            $tagged = $this->context->getVariableFromOp($receiverOp)->classUserType ?? null;
            if (is_string($tagged) && '' !== ltrim($tagged, '\\')) {
                $tagLc = strtolower(ltrim($tagged, '\\'));
                if (!\in_array($tagLc, ['object', 'stdclass', 'mixed'], true)) {
                    return ltrim($tagged, '\\');
                }
            }
        }
        $userType = $receiverOp->type?->userType;
        if (is_string($userType) && '' !== ltrim($userType, '\\')) {
            return ltrim($userType, '\\');
        }
        $operandName = strtolower(JIT\OperandName::resolve($receiverOp) ?? '');
        if ('script' === $operandName) {
            return 'PHPCfg\\Script';
        }
        if (in_array($operandName, ['main', 'func'], true)) {
            return 'PHPCfg\\Func';
        }
        if (in_array($operandName, ['cfg', 'block'], true)) {
            return 'PHPCfg\\Block';
        }

        return null;
    }

    /**
     * Recover hooked-property owner when CFG userType collapsed to generic "object" (#29748).
     */
    private function resolveHookedPropertyDeclaringClass(
        Operand $obj,
        string $declaringClass,
        string $propertyName
    ): string {
        $lc = strtolower(ltrim($declaringClass, '\\'));
        if ('object' !== $lc && 'stdclass' !== $lc && '' !== $lc) {
            return $declaringClass;
        }
        if ($this->context->hasVariableOpInScopes($obj)) {
            $recv = $this->context->getVariableFromOpInScopes($obj);
            $tagged = $recv->classUserType ?? null;
            if (is_string($tagged) && '' !== $tagged && 'object' !== strtolower($tagged)) {
                return $tagged;
            }
        }
        $registry = $this->context->runtime->vmContext->propertyHookRegistry ?? [];
        $propLc = strtolower($propertyName);
        $objectType = $this->context->type->object;
        foreach ($registry as $lcClass => $props) {
            if (!is_array($props)) {
                continue;
            }
            $meta = $props[$propertyName] ?? $props[$propLc] ?? null;
            if (!is_array($meta) || (!isset($meta['get']) && !isset($meta['set']))) {
                continue;
            }
            // Prefer the Object_ display name when registered.
            if ($objectType instanceof JIT\Builtin\Type\Object_) {
                foreach ($objectType->allClassNamesById() as $className) {
                    if (strtolower(ltrim((string) $className, '\\')) === (string) $lcClass) {
                        return (string) $className;
                    }
                }
            }

            return (string) $lcClass;
        }

        return $declaringClass;
    }

    private function resolvePropertyDeclaringClass(Operand $obj, Block $block, ?string $propName): string
    {
        // `$this->prop` resolves property names in the *method* scope (Child), not the CFG
        // userType on `$this` (often the parent). Using the parent as declaringClass made
        // isInvisibleParentPrivateFetch treat the receiver as ParentOnly and read the parent's
        // private slot under AOT (#19005 / #19011 VM+JIT parity).
        $recvName = strtolower(JIT\OperandName::resolve($obj) ?? '');
        if (
            'this' === $recvName
            && null !== $block->func
            && null !== $block->func->class
        ) {
            return ltrim((string) $block->func->class->value, '\\');
        }

        $declaringClass = $obj->type->userType ?? null;
        if (null !== $declaringClass && '' !== $declaringClass) {
            $pseudoLc = strtolower(ltrim($declaringClass, '\\'));
            // Explicit `mixed $o` stamps userType "mixed" — same runtime object dispatch as
            // untyped params (#34721). Do not look up a ClassEntry named "mixed".
            if ('mixed' === $pseudoLc) {
                $declaringClass = 'object';
                $pseudoLc = 'object';
            }
            // self/parent: compile-time scope is enough. static: scope->calledClassName is the
            // declaring class at JIT time, not get_called_class() — leave "static" for runtime
            // property dispatch by __object__.class_id (#31937).
            if (\in_array($pseudoLc, ['self', 'parent'], true)) {
                $declaringClass = $this->resolveJitStaticScopeClass(
                    $block,
                    new Operand\Literal($declaringClass)
                );
            }
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $operandName = strtolower(JIT\OperandName::resolve($obj) ?? '');
            if ('script' === $operandName) {
                $declaringClass = 'PHPCfg\\Script';
            } elseif (in_array($operandName, ['main', 'func'], true)) {
                $declaringClass = 'PHPCfg\\Func';
            } elseif (in_array($operandName, ['cfg', 'block'], true)) {
                $declaringClass = 'PHPCfg\\Block';
            }
        }
        if ((null === $declaringClass || '' === $declaringClass) && null !== $propName) {
            $declaringClass = $this->externalPropertyDeclaringClassFallback(
                $this->context->scope->className,
                $propName
            );
        }
        if (null === $declaringClass && null !== $block->func && null !== $block->func->class) {
            // Only `$this->prop` may use the enclosing class. `$t = $this->o; $t->x` and
            // `($this->o)->x` are SSA temps whose CFG userType is often null — using
            // func->class wrote A::$x (or OOMed via synthetic object) (#34395).
            $recvName = strtolower(JIT\OperandName::resolve($obj) ?? '');
            if ('this' === $recvName) {
                $declaringClass = $block->func->class->value;
            }
        }
        // Prior nullsafe on the same CV leaves CFG userType generic "object" even after
        // `$c = new C` refreshed classUserType on the binding (#32749, #29748 pattern).
        // `$n = $el->childNodes; $n->length` — CFG userType stays DOMNode while the fetch
        // result is tagged DOMNodeList (#20501).
        if ($this->context->hasVariableOpInScopes($obj)) {
            $recv = $this->context->getVariableFromOpInScopes($obj);
            $tagged = $recv->classUserType ?? null;
            if (is_string($tagged) && '' !== $tagged && 'object' !== strtolower(ltrim($tagged, '\\'))) {
                $declaringClass = ltrim($tagged, '\\');
            }
        }
        if (null !== $declaringClass && '' !== $declaringClass && '' !== $this->context->scope->className) {
            $funcClassLc = strtolower(ltrim($declaringClass, '\\'));
            $scopeClassLc = strtolower(ltrim($this->context->scope->className, '\\'));
            if (
                $this->context->type->object->isTraitClass($funcClassLc)
                && !$this->context->type->object->isTraitClass($scopeClassLc)
            ) {
                $declaringClass = $this->context->scope->className;
            }
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $recvName = strtolower(JIT\OperandName::resolve($obj) ?? '');
            if ('this' === $recvName && $this->context->scope->className !== '') {
                $declaringClass = $this->context->scope->className;
            } else {
                $declaringClass = 'object';
            }
        }
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338, #26863).
        if (0 === strcasecmp(ltrim($declaringClass, '\\'), 'simplemxml_element')) {
            $declaringClass = 'SimpleXMLElement';
        }
        // Prior ?-> on null leaves CFG userType null/object while the live CV holds a user
        // class after reassignment — do not stdClass-remap (#32749, same as #29748 hooks).
        $declaringClass = $this->recoverPropertyDeclaringClassFromReceiverVar($obj, $declaringClass);

        return $declaringClass;
    }

    /**
     * Runtime receiver class for visibility / undefined-property warnings on `$this->prop`.
     *
     * CFG userType on `$this` is often the parent; zend_fetch_property uses the object's
     * class for the invisible-parent-private check (#19005).
     */
    private function resolvePropertyFetchReceiverClassName(Operand $obj, Block $block, string $fallback): string
    {
        if ($this->propertyFetchOperandIsThis($obj, $block)) {
            if (null !== $block->func?->class) {
                return ltrim((string) $block->func->class->value, '\\');
            }
            if ('' !== $this->context->scope->className) {
                return ltrim($this->context->scope->className, '\\');
            }
        }
        if ($this->context->hasVariableOpInScopes($obj)) {
            $recv = $this->context->getVariableFromOpInScopes($obj);
            $tagged = $recv->classUserType ?? null;
            if (is_string($tagged) && '' !== $tagged && 'object' !== strtolower(ltrim($tagged, '\\'))) {
                return ltrim($tagged, '\\');
            }
        }

        return $fallback;
    }

    private function propertyFetchOperandIsThis(Operand $obj, Block $block): bool
    {
        if ('this' === strtolower(JIT\OperandName::resolve($obj) ?? '')) {
            return true;
        }
        if (!$this->instanceMethodUsesThis($block)) {
            return false;
        }
        foreach ($block->orig->hoistedOperands ?? [] as $hoisted) {
            if ('this' !== strtolower(JIT\OperandName::resolve($hoisted) ?? '')) {
                continue;
            }
            if ($hoisted === $obj) {
                return true;
            }
            $cur = $obj;
            while ($cur instanceof Operand\Temporary && null !== $cur->original) {
                if ($cur->original === $hoisted) {
                    return true;
                }
                $cur = $cur->original;
            }
        }

        return false;
    }

    /**
     * When CFG collapsed receiver userType to generic object/null, use JIT classUserType.
     */
    private function recoverPropertyDeclaringClassFromReceiverVar(Operand $obj, string $declaringClass): string
    {
        $lc = strtolower(ltrim($declaringClass, '\\'));
        if (!\in_array($lc, ['object', 'stdclass', ''], true)) {
            return $declaringClass;
        }
        $tagged = null;
        if ($this->context->hasVariableOpInScopes($obj)) {
            $recv = $this->context->getVariableFromOpInScopes($obj);
            $tagged = $recv->classUserType ?? null;
        }
        // `$b = new B` stamps classUserType on the named binding; later SSA temps for the
        // same CV often keep CFG userType "object" without copying the tag (#34382 / #32749).
        if ((!is_string($tagged) || '' === $tagged || 'object' === strtolower(ltrim($tagged, '\\')))
            && null !== ($resolved = JIT\OperandName::resolve($obj))
            && isset($this->context->namedVariableBindings[$resolved])
        ) {
            $bound = $this->context->namedVariableBindings[$resolved];
            $tagged = $bound->classUserType ?? null;
        }
        if (!is_string($tagged) || '' === $tagged) {
            // Child-view temps from `$sxe->child` are TYPE_VALUE with a host token
            // but no classUserType until stampSimpleXmlElementUserType (#35834).
            if ($this->context->hasVariableOpInScopes($obj)) {
                $recv = $this->context->getVariableFromOpInScopes($obj);
                if ($this->context->extensionLowering->isTrackedSimpleXmlReceiver($recv)) {
                    return 'SimpleXMLElement';
                }
            }

            return $declaringClass;
        }
        $tagLc = strtolower(ltrim($tagged, '\\'));
        if ('' === $tagLc || \in_array($tagLc, ['object', 'stdclass'], true)) {
            if ($this->context->hasVariableOpInScopes($obj)) {
                $recv = $this->context->getVariableFromOpInScopes($obj);
                if ($this->context->extensionLowering->isTrackedSimpleXmlReceiver($recv)) {
                    return 'SimpleXMLElement';
                }
            }

            return $declaringClass;
        }

        return ltrim($tagged, '\\');
    }

    /** True when `$obj` is an unserialize() O: result without a concrete classUserType (#34602). */
    private function receiverIsFromUnserializeObject(Operand $obj): bool
    {
        if ($this->context->hasVariableOpInScopes($obj)) {
            $recv = $this->context->getVariableFromOpInScopes($obj);
            if ($recv->fromUnserializeObject) {
                $tag = strtolower(ltrim((string) ($recv->classUserType ?? ''), '\\'));
                if ('' === $tag || \in_array($tag, ['object', 'stdclass'], true)) {
                    return true;
                }
            }
        }
        $resolved = JIT\OperandName::resolve($obj);
        if (null === $resolved || '' === $resolved) {
            return false;
        }
        $bound = $this->context->namedVariableBindings[$this->context->resolveRefAliasName($resolved)] ?? null;
        if (!$bound instanceof Variable || !$bound->fromUnserializeObject) {
            return false;
        }
        $tag = strtolower(ltrim((string) ($bound->classUserType ?? ''), '\\'));

        return '' === $tag || \in_array($tag, ['object', 'stdclass'], true);
    }

    private function externalPropertyDeclaringClassFallback(string $scopeClass, string $propName): ?string
    {
        if (!str_starts_with(strtolower($scopeClass), 'phpcompiler\\')) {
            return null;
        }
        $lcProp = strtolower($propName);
        if ('main' === $lcProp) {
            return 'PHPCfg\\Script';
        }
        if ('cfg' === $lcProp) {
            return 'PHPCfg\\Func';
        }

        return null;
    }

    private function applyExternalPropertyResultType(Operand $result, string $declaringClass, string $propName): void
    {
        $userType = $this->externalPropertyResultUserType($declaringClass, $propName);
        if (null === $userType) {
            return;
        }
        $result->type = Type::object($userType);
        if ($this->context->hasVariableOp($result)) {
            $this->context->getVariableFromOp($result)->classUserType = $userType;
        }
    }

    private function externalPropertyResultUserType(string $class, string $name): ?string
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        $lcName = strtolower($name);
        if (str_starts_with($lcClass, 'phpcfg\\script') && 'main' === $lcName) {
            return 'PHPCfg\\Func';
        }
        if (str_starts_with($lcClass, 'phpcfg\\func') && 'cfg' === $lcName) {
            return 'PHPCfg\\Block';
        }
        if ('domdocument' === $lcClass && 'documentelement' === $lcName) {
            return 'DOMElement';
        }
        if (
            str_starts_with($lcClass, 'dom')
            && \in_array($lcName, ['firstchild', 'lastchild', 'nextsibling', 'previoussibling'], true)
        ) {
            // Result is a child node; AOT materializes elements/text as DOMElement (#32315).
            return 'DOMElement';
        }

        return null;
    }
}

