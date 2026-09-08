<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * Mark `new` objects constructed and restore pending outbound call state (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code markObjectConstructedIfLeavingConstruct}
 * through {@code restorePendingOutboundCallAfterInlineNew} (php-src Zend/zend_execute.c
 * object construction completion / ZEND_NEW; nested FUNCCALL_INIT in call args must not
 * clobber pending outbound call state — #15217, #17970). Concern trait — same namespace
 * as parent so relative Frame helpers resolve. Move-only; no new C ABI.
 */
trait ConstructMarkAndPendingOutboundCall
{
    private function markObjectConstructedIfLeavingConstruct(Frame $frame): void
    {
        if (!$this->isConstructFrame($frame)) {
            return;
        }
        if (empty($frame->calledArgs)) {
            return;
        }
        $thisArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thisArg->type) {
            return;
        }
        $thisArg->toObject()->constructed = true;
    }

    private function markPendingNewObjectConstructed(Frame $frame): void
    {
        if (empty($frame->callArgs)) {
            return;
        }
        $objVar = $frame->callArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        $objVar->toObject()->constructed = true;
    }

    private function isConstructFrame(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    /**
     * Inline `new` or nested FUNCCALL_INIT in a call arg overwrites pending outbound call state (#15217, #17970).
     */
    private function savePendingOutboundCallForInlineNew(Frame $frame): void
    {
        if (null === $frame->call) {
            return;
        }
        $frame->pendingOutboundCallRestore[] = [
            'call' => $frame->call,
            'callArgs' => $frame->callArgs,
            'callArgEntries' => $frame->callArgEntries,
            'callSiteLine' => $frame->callSiteLine,
            'builtinCalleeQualifiedMethod' => $frame->builtinCalleeQualifiedMethod,
        ];
    }

    private function restorePendingOutboundCallAfterInlineNew(Frame $frame): void
    {
        if ([] === $frame->pendingOutboundCallRestore) {
            return;
        }
        $saved = array_pop($frame->pendingOutboundCallRestore);
        $frame->call = $saved['call'];
        $frame->callArgs = $saved['callArgs'];
        $frame->callArgEntries = $saved['callArgEntries'];
        $frame->callSiteLine = $saved['callSiteLine'];
        $frame->builtinCalleeQualifiedMethod = $saved['builtinCalleeQualifiedMethod'];
    }
}
