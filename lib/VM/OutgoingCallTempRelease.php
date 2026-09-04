<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectLifetime;

/**
 * Outgoing call-arg temp release (#36403).
 */
trait OutgoingCallTempRelease
{
    /**
     * Zend fcall end — drop by-value send snapshots and dead inline call-arg temps (#11602).
     */
    private function clearOutgoingCallState(Frame $frame, ?int $keepReturnSlot = null): void
    {
        $this->releaseOutgoingCallArgTemps($frame, $keepReturnSlot);
        $frame->callArgs = [];
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = null;
        // Drop stale call-site line so later opcodes (e.g. dynamic property E_DEPRECATED)
        // resolve via the current opcode source line, not the prior call (#21953).
        $frame->callSiteLine = 0;
    }

    private function releaseOutgoingCallArgTemps(Frame $frame, ?int $keepReturnSlot = null): void
    {
        foreach ($frame->callArgEntries as $entry) {
            if ('u' === $entry[0]) {
                $slot = $entry[2] ?? null;
                // By-ref sends store the CV (slot null) — must not releaseRef the live object (#25097).
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[1]);
                }
            } elseif ('n' === $entry[0]) {
                $slot = $entry[3] ?? null;
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[2]);
                }
            } else {
                $slot = $entry[2] ?? null;
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[1]);
                }
            }
            if (!is_int($slot) || $slot === $keepReturnSlot || $frame->block->isNamedVariableSlot($slot)) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableAliasesObjectPropertyCell($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableAliasesFunctionStaticCell($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableIsGeneratorYieldStorage($frame->scope[$slot])) {
                continue;
            }
            // Unhandled match arms re-read the scrutinee on JUMPIF targets after the probe call (#13955).
            if ($frame->block->scopeSlotReadInJumpTargets($slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }
}
