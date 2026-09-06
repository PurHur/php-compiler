<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Stmt-level side-effect builtins and emitted call/method opcode guards (#36387 / prior #36147).
 *
 * Extracted from {@see AdjacentNestedCallArgSlots} so gen-0 split-TU can hollow a
 * smaller Concern TU ({@see isStatementLevelSideEffectFuncCall} through
 * {@see emittedMethodCallOpcodesForCfgStmt}). Unary-hoisted / dead-temp helpers remain
 * in AdjacentNestedCallArgSlots.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c statement-order side effects before ZEND_SEND_* —
 * move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as AdjacentNestedCallArgSlots).
 */
trait StmtLevelSideEffectAndEmittedCallGuards
{
    private function isStatementLevelSideEffectFuncCall(Op\Expr $call): bool
    {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');

        return \in_array(
            $name,
            [
                'chmod',
                'chown',
                'chgrp',
                'unlink',
                'touch',
                'mkdir',
                'rmdir',
                'rename',
                'copy',
                'fwrite',
                'fputs',
                'ftruncate',
                // Stream position mutators — not hoisted arg producers for var_export/print_r (#25084, #16254).
                'rewind',
                'fseek',
                'fsetpos',
                'define',
                'date_sunrise',
                'date_sunset',
            ],
            true
        );
    }

    /**
     * Hoisted call-arg producers with PROFILE≥8.4 soft-null deprecation on a null literal.
     *
     * php-cfg may hoist json_decode(null) ahead of set_error_handler(); defer to the consumer
     * so user handlers observe E_DEPRECATED (Zend stmt order, #21223).
     */
    private function funcCallSoftNullDeprecationOnNullMustDeferAtConsumer(Op\Expr $call): bool
    {
        if (!$call instanceof Op\Expr\FuncCall && !$call instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            return false;
        }
        $first = $call->args[0] ?? null;
        if (!$this->callArgIsNullLiteral($first)) {
            return false;
        }
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
        if ('' === $name || !$this->funcCallNameMaySoftNullDeprecateOnProfile84($name)) {
            return false;
        }
        $first = $call->args[0] ?? null;
        if (!$this->callArgIsNullLiteral($first)) {
            return false;
        }

        return true;
    }

    /** PROFILE≥8.4 builtins that emit E_DEPRECATED on null → '' coercion (VmString trim-family). */
    private function funcCallNameMaySoftNullDeprecateOnProfile84(string $name): bool
    {
        if (!version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            return false;
        }

        return \in_array($name, [
            'json_decode',
            'json_validate',
            'unserialize',
            'trim',
            'ltrim',
            'rtrim',
            'chop',
            'strlen',
            'strtolower',
            'strtoupper',
            'strrev',
            'md5',
            'sha1',
            'hash',
            'hash_hmac',
            'base64_encode',
            'base64_decode',
            'parse_url',
            'htmlspecialchars',
            'htmlentities',
        ], true);
    }

    /**
     * Generator/Iterator resume methods — stmt-level side effects, not hoisted fwrite/var_dump arg producers (#16609, re-#13989).
     */
    private function methodCallHasStatementLevelSideEffects(Op\Expr\MethodCall $call): bool
    {
        $method = $this->staticNameFromOperand($call->name);
        if (null === $method) {
            return false;
        }

        return \in_array(strtolower($method), [
            'next',
            'send',
            'rewind',
            'throw',
        ], true);
    }

    /**
     * Iterator pointer stmts ($it->next()) before a hoisted sibling call-arg producer — not part of the chain (#13901, #17251).
     */
    private function siblingInlineCallProducerSkipsHoistedArgChain(Op $child, ?Op $nextChild = null): bool
    {
        if (
            $child instanceof Op\Expr\MethodCall
            && $this->methodCallHasStatementLevelSideEffects($child)
            && (
                $nextChild instanceof Op\Expr\FuncCall
                || $nextChild instanceof Op\Expr\NsFuncCall
                || $nextChild instanceof Op\Expr\MethodCall
                || $nextChild instanceof Op\Expr\StaticCall
            )
        ) {
            return true;
        }
        if (
            ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
            && $this->isArrayInternalPointerMutatorFuncName($this->resolveCfgFuncCallName($child))
            && (
                $nextChild instanceof Op\Expr\FuncCall
                || $nextChild instanceof Op\Expr\NsFuncCall
            )
        ) {
            return true;
        }

        return false;
    }

    /**
     * Stmt-level iterator/generator pointer advance before a sibling MethodCall inline arg (#17251, #13901).
     *
     * php-cfg: `$it->next(); var_export($it->current(), true)` hoists both MethodCalls; only current feeds arg #0.
     */
    private function methodCallIsStmtLevelDiscardPrelude(Op\Expr\MethodCall $call): bool
    {
        if (!$this->methodCallHasStatementLevelSideEffects($call)) {
            return false;
        }
        if (!property_exists($call, 'result')) {
            return false;
        }

        return empty($call->result->usages);
    }

    /**
     * chmod(); substr(sprintf('%o', fileperms($path)), -N) — run stmt-level side effects before hoisted producers (#16480).
     *
     * @param list<Op> $cfgChildren
     */
    private function ensureStatementLevelSideEffectsBeforeChainStartCompiled(
        Block $block,
        int $chainStartIndex,
        array $cfgChildren
    ): void {
        if ($chainStartIndex <= 0) {
            return;
        }
        for ($k = 0; $k < $chainStartIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                !($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                || !$this->isStatementLevelSideEffectFuncCall($stmt)
            ) {
                continue;
            }
            if ($this->emittedFuncCallOpcodesForCfgStmt($block, $stmt)) {
                continue;
            }
            foreach ($this->compileExpr($stmt, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    /**
     * @param Op\Expr\FuncCall|Op\Expr\NsFuncCall $call
     */
    private function emittedFuncCallOpcodesForCfgStmt(Block $block, Op\Expr $call): bool
    {
        $name = strtolower($this->resolveCfgFuncCallName($call) ?? '');
        if ('' === $name) {
            return false;
        }
        $defineName = null;
        if ('define' === $name) {
            $arg0 = $call->args[0] ?? null;
            if ($arg0 instanceof Operand) {
                $lit = $this->staticNameFromOperand($arg0);
                if (null !== $lit) {
                    $defineName = strtolower($lit);
                }
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
                if ($name === $initName) {
                    return true;
                }
                continue;
            }
            // define('LIT', …) lowers to TYPE_DECLARE_GLOBAL_CONST with no FUNCCALL_INIT (#204).
            // Side-effect replay before hoisted var_export/defined() must not re-emit it (#32039).
            if (
                'define' === $name
                && OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type
            ) {
                if (null === $defineName) {
                    return true;
                }
                $declared = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
                if ($declared === $defineName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function ensureSideEffectsBeforeSubstrNestedSprintfCompiled(
        Block $block,
        int $callIndex,
        array $cfgChildren
    ): void {
        for ($k = 0; $k < $callIndex; ++$k) {
            $stmt = $cfgChildren[$k] ?? null;
            if (
                !($stmt instanceof Op\Expr\FuncCall || $stmt instanceof Op\Expr\NsFuncCall)
                || !$this->isStatementLevelSideEffectFuncCall($stmt)
            ) {
                continue;
            }
            if ($this->emittedFuncCallOpcodesForCfgStmt($block, $stmt)) {
                continue;
            }
            foreach ($this->compileExpr($stmt, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    private function isNamedVariableOperand(Operand $arg): bool
    {
        $name = Block::resolveVariableName($arg);
        if (null !== $name && '' !== $name) {
            return true;
        }

        return $arg instanceof Operand\Variable
            && $arg->name instanceof Operand\Literal
            && is_string($arg->name->value)
            && '' !== $arg->name->value;
    }

    /**
     * Empty-usages createElement/appendChild (etc.) before PropertyFetch/ConstFetch + consumer are
     * prior statements, not importNode/replaceChild inline args.
     *
     * @param list<Op> $cfgChildren
     */
    private function emptyUsagesDomMutationIsPriorStatementBeforeConsumer(
        Op\Expr\MethodCall $child,
        int $childIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        $method = strtolower($this->staticNameFromOperand($child->name) ?? '');
        if (!\in_array($method, [
            'appendchild',
            'insertbefore',
            'replacechild',
            'removechild',
            'append',
            'prepend',
            'createelement',
            'createelementns',
            'createtextnode',
            'createcomment',
        ], true)) {
            return false;
        }
        for ($j = $childIndex + 1; $j < $consumerIndex; ++$j) {
            $mid = $cfgChildren[$j] ?? null;
            if (
                $mid instanceof Op\Expr\PropertyFetch
                || $mid instanceof Op\Expr\NullsafePropertyFetch
            ) {
                // `$el->childNodes->item(N)` — PropertyFetch feeds item(), not a prior
                // statement separator. Skipping createElement here made both ARG_SENDs
                // bind item() (#34436 / peer #34405 statement skip).
                if ($this->propertyFetchFeedsCallProducerBeforeConsumer(
                    $mid,
                    $j,
                    $consumerIndex,
                    $cfgChildren
                )) {
                    continue;
                }

                return true;
            }
            if (
                $mid instanceof Op\Expr\ConstFetch
                || $mid instanceof Op\Expr\ClassConstFetch
            ) {
                return true;
            }
        }

        return false;
    }

    /** Whether this MethodCall's METHODCALL_INIT was already lowered onto $block. */
    private function emittedMethodCallOpcodesForCfgStmt(Block $block, Op\Expr\MethodCall $call): bool
    {
        $method = strtolower($this->staticNameFromOperand($call->name) ?? '');
        if ('' === $method) {
            return false;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_METHODCALL_INIT !== $op->type) {
                continue;
            }
            $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg2, $block) ?? '');
            // METHODCALL_INIT arg2 is the method name slot; also try arg1 when layout differs.
            if ('' === $initName) {
                $initName = strtolower($this->resolveCompileTimeStringSlot((int) $op->arg1, $block) ?? '');
            }
            if ($method === $initName) {
                return true;
            }
        }

        return false;
    }
}
