<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * Return-type enforcement helpers for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code functionReturnsByRef} through
 * {@code returnTypeCallableName} (php-src Zend/zend_execute.c zend_verify_return_type;
 * Zend/zend_compile.c FLAG_RETURNS_REF; Generator wrapper return labels #16141/#26468).
 * Companion to {@see DeprecationNoticeEmit}. Concern trait — same namespace as parent
 * so relative Frame / OpCode / Block helpers resolve. Move-only; no new C ABI.
 */
trait ReturnTypeEnforce
{
    private function functionReturnsByRef(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;

        return null !== $func
            && (($func->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    protected function instanceMethodReturnsByRef(ObjectEntry $object, string $methodName): bool
    {
        $methodLc = strtolower($methodName);
        if (!$this->hasInstanceMethod($object->class, $methodLc)) {
            return false;
        }
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl
            && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** True when ArrayAccess::offsetGet is declared `&offsetGet` (zend_vm_def.h ZEND_PRE/POST_INC). */
    public function arrayAccessOffsetGetReturnsByRef(ObjectEntry $object): bool
    {
        return $this->instanceMethodReturnsByRef($object, 'offsetGet');
    }

    private function resolveVmReturnValue(Frame $frame, OpCode $op): Variable
    {
        $slot = $op->arg1;
        if (null === $slot) {
            return new Variable(Variable::TYPE_NULL);
        }
        if (isset($frame->scope[$slot])) {
            $scopeVar = $frame->scope[$slot];
            VM\TypedPropertyCheck::assertReadable($scopeVar);
            $resolved = $scopeVar->resolveIndirect();
            if (!$resolved->isUndefined()) {
                return $resolved;
            }
        }
        $operand = $frame->block->getOperand($slot);
        if ($operand instanceof \PHPCfg\Operand\Literal && isset($frame->block->constants[$slot])) {
            return $frame->block->constants[$slot];
        }
        if (isset($frame->block->constants[$slot])) {
            return $frame->block->constants[$slot];
        }

        return new Variable(Variable::TYPE_NULL);
    }

    private function enforceReturnType(Frame $frame, ?Variable $value): void
    {
        if ($this->context->suppressReturnTypeCheckDepth > 0) {
            return;
        }
        $block = $frame->block;
        if (null === $block) {
            return;
        }
        if ($block->returnTypeNever) {
            // Zend-shaped callable label ({closure}), not php-cfg {anonymous}#N (#30020).
            TypeCheck::assertNeverReturn($this->returnTypeCallableName($frame));

            return;
        }
        if ($block->returnTypeVoid) {
            TypeCheck::assertVoidReturn($value);

            return;
        }
        if (null === $value && $this->declaredReturnTypeRequiresValue($block)) {
            $expected = TypeCheck::expectedReturnTypeLabelForNoneReturned($block);
            // Zend resolves `: static` to the late-bound class in the TypeError (#26486).
            if ($block->returnTypeStatic) {
                $lc = $this->lateStaticClassLc($frame);
                if (isset($this->context->classes[$lc])) {
                    $expected = $this->context->classes[$lc]->name;
                }
            }
            TypeCheck::assertNoneReturned(
                $this->returnTypeCallableName($frame),
                $expected
            );
        }
        if ($block->returnTypeStatic) {
            TypeCheck::assertStaticReturn(
                $value,
                $this->lateStaticClassLc($frame),
                $this->context,
                $this->returnTypeCallableName($frame)
            );

            return;
        }
        if (null !== $block->returnDnfConstraints && null !== $value) {
            // `: iterable` as Traversable|array DNF on generators — wrapper type only (#26468 / #29888).
            if ($this->generatorHasTraversableReturnTypeLabel($block)) {
                return;
            }
            DnfCheck::assertMatches(
                $value,
                $block->returnDnfConstraints,
                $this->context,
                'Return value',
                null,
                $block->strictTypes,
                $this->returnTypeCallableName($frame)
            );

            return;
        }
        if (null !== $block->returnClassConstraint && null !== $value) {
            // Wrapper return types apply at invoke time, not getReturn() (#16141, #26468).
            if ($this->generatorHasTraversableReturnTypeLabel($block)) {
                return;
            }
            TypeCheck::assertObjectReturn(
                $value,
                $block->returnClassConstraint,
                $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint,
                $this->returnTypeCallableName($frame)
            );

            return;
        }
        // `: Generator`/`: Iterator`/`: Traversable`/`: iterable`/`: object` apply at call time
        // (wrap object), not on generator body completion / getReturn() (#16141, #26468).
        if ($this->generatorHasTraversableReturnTypeLabel($block)) {
            return;
        }
        if (null === $block->returnTypeConstraint) {
            return;
        }
        // Return type checks use the declaring function's strict_types (zend_verify_return_type).
        TypeCheck::coerceReturn(
            $value,
            $block->strictTypes,
            $block->returnTypeConstraint,
            $block->returnLiteralBoolType,
            $this->returnTypeCallableName($frame)
        );
    }

    private function generatorHasTraversableReturnTypeLabel(Block $block): bool
    {
        if (!$block->isGenerator) {
            return false;
        }
        $returnLabel = ltrim(
            $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint ?? '',
            '\\'
        );
        if ('' === $returnLabel) {
            return false;
        }

        // Zend: these declare the Generator wrapper at invoke, not getReturn() (#16141, #26468).
        // Bare `: iterable` keeps returnDeclaredTypeLabel=iterable with Traversable|array DNF (#29888).
        return in_array($returnLabel, ['Generator', 'Iterator', 'Traversable', 'iterable', 'object'], true);
    }

    private function declaredReturnTypeRequiresValue(Block $block): bool
    {
        if ($block->returnTypeMixed) {
            return true;
        }
        if ($block->returnTypeStatic) {
            return true;
        }
        if ($this->generatorHasTraversableReturnTypeLabel($block)) {
            return false;
        }
        if (null !== $block->returnDnfConstraints) {
            return true;
        }
        if (null !== $block->returnClassConstraint) {
            return true;
        }
        if (null !== $block->returnTypeConstraint) {
            return true;
        }

        return false;
    }

    private function returnTypeCallableName(Frame $frame): ?string
    {
        $block = $frame->block;
        // Prefer ClosureState / rich Block name so return TypeErrors match Zend 8.4 (#30076).
        if (null !== $frame->closureCall || null !== $frame->pendingClosureInvoke
            || (null !== $block && null !== $block->closureRichDisplayName && '' !== $block->closureRichDisplayName)
        ) {
            return VM\ParamArgumentCountError::formatUserFunctionName(
                VM\ParamArgumentCountError::resolveFunctionName($frame)
            );
        }
        $func = $block->func ?? null;
        if (null === $func) {
            return null;
        }

        return VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($func, null, $block);
    }
}
