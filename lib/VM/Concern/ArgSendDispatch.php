<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ARG_SEND dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner ARG_SEND case body
 * (php-src Zend/zend_vm_def.h ZEND_SEND_VAL / ZEND_SEND_VAR / ZEND_SEND_REF /
 * ZEND_SEND_VAL_EX / ZEND_SEND_VAR_EX / ZEND_SEND_USER; named-arg send via
 * definition-order param index #19697; unbound local SEND_REF #10403).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ArgSendDispatch
{
    /**
     * Execute TYPE_ARG_SEND for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeArgSendDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
        if (null !== $catchFrame) {
            $frame = $catchFrame;
            return $frame;
        }
        $argSlot = (int) $op->arg1;
        // Implicit $this / new() prefix occupies low call-arg indices (#6739, #11844).
        $argIndex = \count($frame->callArgs) + \count($frame->callArgEntries);
        $value = $this->resolveOutgoingCallArgValue($frame, $argSlot);
        // Named sends use definition-order param index for ZEND_SEND_REF (count: $n skips limit, #19697).
        if (
            null !== $op->arg2
            && null === $op->arg3
            && isset($frame->block->constants[$op->arg2])
            && $frame->call instanceof Func\Internal
        ) {
            $namedParam = $frame->block->constants[$op->arg2]->toString();
            $calleeName = $frame->builtinCalleeQualifiedMethod ?? $frame->call->getName();
            $paramNames = BuiltinParamNames::paramNamesForInternalFunction($calleeName) ?? [];
            $namedIdx = BuiltinParamNames::lookupNamedParamIndex(
                $paramNames,
                $namedParam,
                $calleeName
            );
            if (false !== $namedIdx) {
                $argIndex = \count($frame->callArgs) + $namedIdx;
            }
        }
        $needsRef = $this->outgoingCallArgNeedsReference($frame, $argIndex, $value);
        if (!$needsRef) {
            $this->warnUndefinedVariableForScopeRead($frame, $argSlot);
        }
        if (
            !$needsRef
            && $this->isUnboundLocalScopeRead($frame, $argSlot)
        ) {
            $resolved = $value->resolveIndirect();
            if ($resolved->isUndefined()) {
                $sent = new Variable();
                $sent->null();
                $value = $sent;
            }
        } elseif ($needsRef && $this->isUnboundLocalScopeRead($frame, $argSlot)) {
            // Zend creates CV on ZEND_SEND_REF; no E_WARNING on later reads (#10403).
            $this->markScopeSlotInitialized($frame, $argSlot);
        }
        if (!$needsRef) {
            $snapshot = new Variable();
            if ($value->isIndirect()) {
                // CV/indirect send-by-value must not share cells with the snapshot (#16331).
                $snapshot->copyFrom($value->resolveIndirect());
            } else {
                $snapshot->duplicateFrom($value);
            }
            $value = $snapshot;
        }
        if (null !== $op->arg3) {
            $frame->callArgEntries[] = ['u', $value, $needsRef ? null : $argSlot];
            return null;
        }
        if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
            $frame->callArgEntries[] = [
                'n',
                $frame->block->constants[$op->arg2]->toString(),
                $value,
                $needsRef ? null : $argSlot,
            ];
        } else {
            $frame->callArgEntries[] = ['p', $value, $needsRef ? null : $argSlot];
        }
        return null;
    }
}
