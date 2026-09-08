<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_CONST_FETCH / TYPE_STATICCALL_INIT / TYPE_INSTANCEOF / TYPE_IN
 * dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_CONSTANT / ZEND_INIT_STATIC_METHOD_CALL
 * / ZEND_INSTANCEOF; zend_enum.c enum-case static scope #6408; trait
 * instanceof self → composing class #31729). Concern trait — same namespace
 * as parent so relative Frame / OpCode helpers resolve. Move-only; no new
 * C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ConstFetchStaticCallInstanceofDispatch
{
    /**
     * Execute TYPE_CONST_FETCH for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeConstFetchDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $value = null;
        if (!is_null($op->arg3)) {
            // try NS constant fetch
            $value = $this->context->constantFetch($frame->scope[$op->arg3]->toString());
        }
        if (is_null($value)) {
            $value = $this->context->constantFetch($frame->scope[$op->arg2]->toString());
        }
        if (is_null($value)) {
            // arg3 is php-cfg's namespace-qualified name (N\NAME), not bare namespace (#10510).
            $constName = null !== $op->arg3
                ? $frame->scope[$op->arg3]->toString()
                : $frame->scope[$op->arg2]->toString();
            $catchFrame = $this->dispatchVmError(
                sprintf('Undefined constant "%s"', $constName),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        $constName = null !== $op->arg3
            ? $frame->scope[$op->arg3]->toString()
            : $frame->scope[$op->arg2]->toString();
        $this->emitGlobalConstFetchDeprecation($constName, $frame);
        $frame->scope[$op->arg1]->copyFrom($value);
        $this->markScopeSlotInitialized($frame, (int) $op->arg1);

        return null;
    }

    /**
     * Execute TYPE_STATICCALL_INIT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeStaticCallInitDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $instanceScopeCall = false;
        $scopeClassName = null;
        $staticCallMethodName = '';
        $selfKeywordScope = false;
        try {
            $classOperand = $frame->scope[$op->arg1]->resolveIndirect();
            $staticCallMethodName = $frame->scope[$op->arg2]->toString();
            $parentKeywordScope = $op->staticCallParentScope;
            $enumScopeClass = VM\EnumCaseSupport::enumClassForCaseVariable($classOperand);
            if (null !== $enumScopeClass) {
                // (E::A)::staticMethod() — enum case scope resolves to enum type (#6408, zend_enum.c).
                $instanceScopeCall = true;
                $scopeClassName = $enumScopeClass->name;
                $callableName = $scopeClassName.'::'.$staticCallMethodName;
            } elseif (Variable::TYPE_OBJECT === $classOperand->type) {
                $instanceScopeCall = true;
                $scopeClassName = $classOperand->toObject()->class->name;
                $callableName = $scopeClassName.'::'.$staticCallMethodName;
            } else {
                // String (or Error) — do not stringify bool/int/null/array (#30059).
                $className = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                    $classOperand
                );
                if (!$parentKeywordScope) {
                    $parentKeywordScope = 'parent' === strtolower($className);
                }
                // Lexical self:: (php-cfg may keep the keyword) — preserve LSB (#21983).
                $selfKeywordScope = 'self' === strtolower($className);
                $lcClass = $this->resolveClassScopeName($className, $frame);
                $resolvedClassName = isset($this->context->classes[$lcClass])
                    ? $this->context->classes[$lcClass]->name
                    : $className;
                $callableName = $resolvedClassName.'::'.$staticCallMethodName;
            }
            $this->initStaticCallable(
                $frame,
                $callableName,
                $parentKeywordScope,
                $selfKeywordScope
            );
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        } catch (\LogicException $e) {
            if ($instanceScopeCall && str_starts_with($e->getMessage(), 'Call to undefined static method ')) {
                $catchFrame = $this->dispatchVmError(
                    "Call to undefined method {$scopeClassName}::{$staticCallMethodName}()",
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }

        return null;
    }

    /**
     * Execute TYPE_INSTANCEOF for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeInstanceofDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        try {
            $value = $frame->scope[$op->arg2];
            $matches = false;
            $unionEncoded = $op->instanceofUnionTypes;
            if (null !== $unionEncoded && '' !== $unionEncoded) {
                foreach (explode('|', $unionEncoded) as $typeName) {
                    if ('' === $typeName) {
                        continue;
                    }
                    if ($this->valueInstanceOfClassName($value, $typeName)) {
                        $matches = true;
                        break;
                    }
                }
            } else {
                $keyword = $op->instanceofScopeKeyword;
                if (null !== $keyword && '' !== $keyword) {
                    // Trait `instanceof self` → composing class (#31729, zend_inheritance.c).
                    $className = $this->resolveClassScopeName($keyword, $frame);
                } else {
                    $className = VM\InstanceOfClassName::resolveClassName($frame->scope[$op->arg3]);
                }
                $matches = $this->valueInstanceOfClassName($value, $className);
            }
            $frame->scope[$op->arg1]->bool($matches);
        } catch (\Error|\LogicException $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }

        return null;
    }

    /**
     * Execute TYPE_IN for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeInDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        try {
            $found = VM\InOperator::contains(
                $frame->scope[$op->arg2],
                $frame->scope[$op->arg3]
            );
            $frame->scope[$op->arg1]->bool($found);
        } catch (\TypeError $e) {
            return $this->raise($e->getMessage(), $frame);
        }

        return null;
    }
}
