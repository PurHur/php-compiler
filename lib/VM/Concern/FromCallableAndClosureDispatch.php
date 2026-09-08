<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_FROM_CALLABLE / TYPE_CLOSURE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies
 * (php-src Zend/zend_closures.c Closure::fromCallable / zend_create_closure;
 * Zend/zend_vm_def.h ZEND_DECLARE_LAMBDA_FUNCTION). Concern trait — same
 * namespace as parent so relative Frame / OpCode / Block helpers resolve.
 * Capture binding lives in {@see ClosureBindAndFunctionStatic}. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait FromCallableAndClosureDispatch
{
    /**
     * Execute FROM_CALLABLE / CLOSURE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeFromCallableAndClosureDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_FROM_CALLABLE:
            if (isset($frame->scope[$op->arg2])) {
                $callable = $frame->scope[$op->arg2]->resolveIndirect();
            } elseif (isset($frame->block->constants[$op->arg2])) {
                $callable = $frame->block->constants[$op->arg2];
            } else {
                throw new \LogicException('TYPE_FROM_CALLABLE missing callable slot');
            }
            try {
                $entry = VM\ClosureSupport::fromCallable(
                    $this->context,
                    $frame,
                    $callable,
                    $op->fromCallableScope,
                    $op->fromCallableApi
                );
                $frame->scope[$op->arg1]->object($entry);
            } catch (\TypeError $e) {
                // TypeError extends Error — must precede catch (\Error) (#27138).
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }

            return null;
        case OpCode::TYPE_CLOSURE:
            if (null === $op->block1) {
                $frame->scope[$op->arg1]->null();

                return null;
            }
            $funcName = null !== $op->block1->func
                ? $op->block1->func->name
                : '{closure}';
            $closureFunc = new Func\PHP($funcName, $op->block1);
            $closureFunc->sourceLocation = $op->sourceLocation;
            if ([] !== $op->parameterMetadata) {
                $closureFunc->parameterMetadata = $op->parameterMetadata;
            }
            if ([] !== $op->attributeNames) {
                $closureFunc->attributeNames = $op->attributeNames;
            }
            if ([] !== $op->attributeEntries) {
                $closureFunc->attributeEntries = $op->attributeEntries;
            }
            $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
            $state = new ClosureState($closureFunc, $captures);
            $state->applyDefinitionSite($op->sourceLocation, $op->block1);
            $rich = ClosureRichDisplayName::preferFromOp($op, $op->block1);
            if (null !== $rich && '' !== $rich) {
                $state->richDisplayName = $rich;
            }
            if (
                (null === $state->boundScopeClass || '' === $state->boundScopeClass)
                && null !== $op->closureDeclaringClass
                && '' !== $op->closureDeclaringClass
            ) {
                $state->boundScopeClass = $op->closureDeclaringClass;
            }
            if (
                null !== $frame->block->func
                && null !== $frame->block->func->class
                && null !== $frame->block->func->class->value
                && '' !== $frame->block->func->class->value
            ) {
                // Scope (ce) = declaring class; called_scope (LSB) = creation called class
                // (#25793, zend_closures.c / zend_object_handlers.c).
                $declaring = $frame->block->func->class->value;
                if (null !== $op->block1->func) {
                    $op->block1->func->class = $frame->block->func->class;
                }
                $state->boundScopeClass = $declaring;
                $called = $this->inferCalledClass($frame);
                if (null !== $called && '' !== $called) {
                    $state->boundCalledScopeClass = $called;
                }
                $isStaticClosure = null !== $op->block1->func
                    && (($op->block1->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                if (!$isStaticClosure) {
                    $thisVar = $this->resolveCallerThis($frame);
                    if (null !== $thisVar) {
                        $bound = new Variable();
                        $bound->copyFrom($thisVar->resolveIndirect());
                        $state->boundThis = $bound;
                    }
                }
            }
            $frame->scope[$op->arg1]->object($state->wrapObject($this->context));

            return null;
        default:
            throw new \LogicException('executeFromCallableAndClosureDispatch: unexpected opcode '.$op->type);
        }
    }
}
