<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Compile-time string fold / promote helpers for JIT/AOT (#36387).
 *
 * Extracted from {@see PropertyFetchCoalesceAndCompileTimeString}:
 * {@code foldCompileTimeStringFromAssign} through {@code jitNamedScopeSlotAliases}.
 * Move-only; no IR shape change.
 *
 * php-src: Zend/zend_execute.c (string/concat assign and call-arg folding) —
 * move-only Concern extract; no new C ABI.
 */
trait CompileTimeStringFoldAndPromote
{
    /**
     * Propagate compile-time callable names through TYPE_ASSIGN (first-class callables, #1363).
     */
    private function foldCompileTimeStringFromAssign(
        Block $block,
        int $sourceSlot,
        Variable $dest,
        Variable $source
    ): void {
        if (null !== $source->classUserType) {
            $dest->classUserType = $source->classUserType;
        }
        if (null !== $source->compileTimeDomNodeListLength) {
            $dest->compileTimeDomNodeListLength = $source->compileTimeDomNodeListLength;
        }
        if (null !== $source->serializePayloadClass) {
            $dest->serializePayloadClass = $source->serializePayloadClass;
        }
        if ($source->fromUnserializeObject) {
            $dest->fromUnserializeObject = true;
        }
        if (null !== $source->compileTimeString) {
            $dest->compileTimeString = $source->compileTimeString;

            return;
        }
        if (null !== $dest->compileTimeString) {
            // Catch/branch reassignment from a non-const RHS must drop stale init literals
            // (e.g. $error = '' then catch $error = 'msg' — call args kept strlen=0) (#32570).
            $dest->compileTimeString = null;
        }
        if (null !== $source->compileTimeDomTagName && null === $dest->compileTimeDomTagName) {
            $dest->compileTimeDomTagName = $source->compileTimeDomTagName;
        }
        if (null !== $source->compileTimeDomInnerXml && null === $dest->compileTimeDomInnerXml) {
            $dest->compileTimeDomInnerXml = $source->compileTimeDomInnerXml;
        }
        if (null !== $source->compileTimeDomInnerXmlParent && null === $dest->compileTimeDomInnerXmlParent) {
            $dest->compileTimeDomInnerXmlParent = $source->compileTimeDomInnerXmlParent;
        }
        if (null !== $source->compileTimeDomChildIndex && null === $dest->compileTimeDomChildIndex) {
            $dest->compileTimeDomChildIndex = $source->compileTimeDomChildIndex;
        }
        if (null !== $source->compileTimeDomNodePath && null === $dest->compileTimeDomNodePath) {
            $dest->compileTimeDomNodePath = $source->compileTimeDomNodePath;
        }
        if (null !== $source->compileTimeDomLineNo && null === $dest->compileTimeDomLineNo) {
            $dest->compileTimeDomLineNo = $source->compileTimeDomLineNo;
        }
        if (null !== $source->compileTimeDomTextData && null === $dest->compileTimeDomTextData) {
            $dest->compileTimeDomTextData = $source->compileTimeDomTextData;
        }
        if (null !== $source->compileTimeDomAttributes && null === $dest->compileTimeDomAttributes) {
            $dest->compileTimeDomAttributes = $source->compileTimeDomAttributes;
        }
        if (null !== $source->compileTimeDomGeiHtmlHit && null === $dest->compileTimeDomGeiHtmlHit) {
            $dest->compileTimeDomGeiHtmlHit = $source->compileTimeDomGeiHtmlHit;
        }
        if (null !== $source->compileTimeDomElementId && null === $dest->compileTimeDomElementId) {
            $dest->compileTimeDomElementId = $source->compileTimeDomElementId;
        }
        if (null !== $source->compileTimeDomLoadXml && null === $dest->compileTimeDomLoadXml) {
            $dest->compileTimeDomLoadXml = $source->compileTimeDomLoadXml;
        }
        if ($source->compileTimeDomHtmlLoaded && !$dest->compileTimeDomHtmlLoaded) {
            $dest->compileTimeDomHtmlLoaded = true;
        }
        if (null !== $source->compileTimeDomImportHostSxeToken && null === $dest->compileTimeDomImportHostSxeToken) {
            $dest->compileTimeDomImportHostSxeToken = $source->compileTimeDomImportHostSxeToken;
        }
        $this->foldCompileTimeStringFromSlot($block, $sourceSlot, $dest);
    }

    private function foldCompileTimeStringFromSlot(Block $block, int $slot, Variable $dest): void
    {
        if (null !== $dest->compileTimeString) {
            return;
        }
        $scopeName = JIT\OperandName::resolve($block->operandForScopeSlot($slot) ?? $block->getOperand($slot));
        if (null === $scopeName || '' === $scopeName) {
            foreach ($block->eachNamedScopeSlot() as [$name, $scopeSlot]) {
                if ($scopeSlot === $slot) {
                    $scopeName = $name;
                    break;
                }
            }
        }
        if (null !== $scopeName && '' !== $scopeName) {
            if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)) {
                return;
            }
            $resolved = $this->jitEffectiveNamedLocalCompileTimeString($block, $scopeName);
        } else {
            $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
        }
        if (null !== $resolved) {
            $dest->compileTimeString = $resolved;
        }
    }

    /**
     * Named CVs keep firstChild/lastChild open-tag stamps; ARG_SEND temps often
     * reuse lastFetched* from a later nested walk (#21644 / #34050).
     *
     * @param list<Operand|null> $operands
     * @param list<Variable>     $args
     */
    private function promoteCompileTimeDomOnCallArgs(Block $block, array $operands, array $args): void
    {
        foreach ($args as $i => $arg) {
            if (!$arg instanceof Variable) {
                continue;
            }
            $operand = $operands[$i] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $scopeName = JIT\OperandName::resolve($operand);
            if (null === $scopeName || '' === $scopeName) {
                continue;
            }
            $resolved = $this->context->resolveRefAliasName($scopeName);
            $bound = $this->context->namedVariableBindings[$resolved] ?? null;
            if (!$bound instanceof Variable || $bound === $arg) {
                continue;
            }
            // Promote on ElementId / tagName too — replaceChild clears the attrs bag so
            // later setAttribute does not shadow CreateElementAttrs, but the ARG_SEND temp
            // still needs ElementId or lastId() points at a newer createElement (#35386).
            if (
                (null === $bound->compileTimeDomAttributes || [] === $bound->compileTimeDomAttributes)
                && null === $bound->compileTimeDomElementId
                && (null === $bound->compileTimeDomTagName || '' === $bound->compileTimeDomTagName)
            ) {
                continue;
            }
            $this->syncCompileTimeDomTagName($arg, $bound, true);
            if (null !== $bound->classUserType && '' !== $bound->classUserType) {
                $arg->classUserType = $bound->classUserType;
            }
        }
    }

    /**
     * @param list<Operand|null> $operands
     * @param list<Variable> $args
     */
    /**
     * Echo prefers native {@see __string__*} allocas over empty {main} script-global boxes
     * (#36366). Builtin call args must match or strlen/htmlspecialchars read stale sidecars.
     */
    private function preferNativeStringBindingForCallArg(string $scopeName, Variable $arg): Variable
    {
        $resolved = $this->context->resolveRefAliasName($scopeName);
        $bound = $this->context->namedVariableBindings[$resolved] ?? null;
        if (
            $bound instanceof Variable
            && $bound !== $arg
            && Variable::KIND_VARIABLE === $bound->kind
            && Variable::TYPE_STRING === $bound->type
            && (
                Variable::TYPE_VALUE === $arg->type
                || $arg->functionStaticGlobal
                || Variable::TYPE_STRING !== $arg->type
            )
        ) {
            return $bound;
        }

        return $arg;
    }

    private function promoteCompileTimeStringOnCallArgs(Block $block, array $operands, array $args): void
    {
        foreach ($args as $i => $arg) {
            $operand = $operands[$i] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $slot = $block->slotForOperand($operand);
            if (null === $slot) {
                continue;
            }
            // Named string locals (native or boxed): always re-resolve — php-cfg uses distinct
            // operands per block for one CV and init compileTimeString ('' before try) survives
            // on catch reassignment (#32496, #32570, htmlspecialchars #32636 / ThrowsWeb #2076).
            if (
                (Variable::TYPE_STRING === $arg->type || Variable::TYPE_VALUE === $arg->type)
                && !$operand instanceof Operand\Literal
            ) {
                $scopeName = JIT\OperandName::resolve($operand);
                if (null !== $scopeName && '' !== $scopeName) {
                    $args[$i] = $this->preferNativeStringBindingForCallArg($scopeName, $arg);
                    $arg = $args[$i];
                    if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)
                        || $this->jitNamedLocalScopeHasConcatMutation($block, $scopeName)) {
                        $arg->compileTimeString = null;
                    } else {
                        $effective = $this->jitEffectiveNamedLocalCompileTimeString(
                            $block,
                            $scopeName
                        );
                        if (null !== $effective) {
                            if (
                                null !== $arg->compileTimeString
                                && $arg->compileTimeString !== $effective
                            ) {
                                // `.=` / loop back-edges stamp a longer runtime string on the
                                // JIT Variable than init-literal back-walk finds (#36244).
                                $arg->compileTimeString = null;
                            } else {
                                $arg->compileTimeString = $effective;
                            }
                        } elseif (null !== $arg->compileTimeString) {
                            // Loop/branch merge could not prove a literal — stale init '' must
                            // not fold strlen/htmlspecialchars (#36406).
                            $arg->compileTimeString = null;
                        }
                    }

                    continue;
                }
            }
            if (null !== $arg->compileTimeString) {
                if ($operand instanceof Operand\Literal) {
                    continue;
                }
                // Catch/branch reassignment: stale try-path '' on boxed locals (#32570).
                // Divergence must be checked before resolve — merge blocks return null from
                // resolveJitCompileTimeStringSlot when try/catch arms disagree, which skipped
                // the old guard and left strlen/htmlspecialchars folding to '' (#32636).
                $divergent = false;
                foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
                    if ($scopeSlot === $slot
                        && $this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $scopeName)
                    ) {
                        $divergent = true;
                        break;
                    }
                }
                if ($divergent) {
                    $arg->compileTimeString = null;
                    continue;
                }
                $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
                // Do not wipe a good stamp with null (misaligned argOperands used to do this) (#35234).
                if (null !== $resolved) {
                    $arg->compileTimeString = $resolved;
                }

                continue;
            }
            $this->foldCompileTimeStringFromSlot($block, $slot, $arg);
        }
    }

    /**
     * @param array<int, true> $visited
     */
    private function resolveJitCompileTimeStringSlot(Block $block, int $slot, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (VM\Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $prior->type && $prior->arg1 === $slot) {
                $classOp = $block->getOperand($prior->arg2);
                $nameOp = $block->getOperand($prior->arg3);
                if (
                    $classOp instanceof Operand\Literal
                    && $nameOp instanceof Operand\Literal
                    && 'class' === strtolower($nameOp->value)
                ) {
                    return $this->resolveClassNameForPseudoConst($block, $classOp);
                }
            }
            if (OpCode::TYPE_CONCAT === $prior->type && $prior->arg1 === $slot) {
                $left = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg2, $visited);
                $right = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
                if (null !== $left && null !== $right) {
                    return $left.$right;
                }
            }
            if (OpCode::TYPE_ASSIGN !== $prior->type) {
                continue;
            }
            if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $slot), true)) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        if (\count($block->parents) > 1) {
            // Try/catch (and ?: / foreach) merge blocks: all incoming paths must agree.
            // Picking the first parent folded catch assigns to the try-path literal (e.g.
            // $error = "" before try, "msg" in catch → strlen($error) became 0) (#32570).
            $agreed = null;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return null;
                }
                // Fresh visited per parent — shared $visited marks the slot seen on parent
                // one and makes parent two return null before walking (#32496 openssl PEM).
                $branchVisited = [];
                $resolved = $this->resolveJitCompileTimeStringSlot($parent, $slot, $branchVisited);
                if (null === $resolved) {
                    return null;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return null;
                }
            }

            return $agreed;
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return $this->jitCompileTimeStringFromNamedBindingIfStable($block, $slot);
    }

    /**
     * When {@see Block::$parents} is empty on forward-only CFG edges, slot back-edges
     * cannot reach the ASSIGN — fall back to named bindings unless try/catch (or ?:)
     * branches disagree (#32496 vs #32570).
     */
    private function jitCompileTimeStringFromNamedBindingIfStable(Block $block, int $slot): ?string
    {
        $name = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeSlot === $slot) {
                $name = $scopeName;
                break;
            }
        }
        if (null === $name || '' === $name) {
            return null;
        }
        if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($block, $name)) {
            return null;
        }
        $bound = $this->context->namedVariableBindings[
            $this->context->resolveRefAliasName($name)
        ] ?? null;
        if (null === $bound || null === $bound->compileTimeString) {
            return null;
        }

        return $bound->compileTimeString;
    }

    /**
     * True when $name was mutated via CONCAT on any block reachable walking parents.
     * Init-literal back-walk cannot soundly describe loop-carried `.=` growth (#36406).
     *
     * @param array<int, true> $visited
     */
    private function jitNamedLocalScopeHasConcatMutation(
        Block $block,
        string $name,
        array &$visited = []
    ): bool {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return false;
        }
        $visited[$id] = true;
        $slot = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $slot = $scopeSlot;
                break;
            }
        }
        if (null !== $slot) {
            $aliases = $this->jitNamedScopeSlotAliases($block, $slot);
            foreach ($block->opCodes as $prior) {
                if (
                    OpCode::TYPE_CONCAT === $prior->type
                    && \in_array($prior->arg1, $aliases, true)
                ) {
                    return true;
                }
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            if ($this->jitNamedLocalScopeHasConcatMutation($parent, $name, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when incoming CFG arms assign different compile-time strings to $name
     * (try $error="" vs catch $error="msg" — #32570).
     */
    private function jitNamedLocalHasDivergentBranchCompileTimeStrings(
        Block $block,
        string $name,
        array &$visited = []
    ): bool {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return false;
        }
        $visited[$id] = true;

        if (\count($block->parents) > 1) {
            $agreed = null;
            $sawNull = false;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return true;
                }
                $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name);
                if (null === $resolved) {
                    $sawNull = true;
                    continue;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return true;
                }
            }
            // Loop header: entry literal vs null back-edge must not fold (#36244).
            if ($sawNull && null !== $agreed) {
                return true;
            }

            return false;
        }

        // If/catch arms are single-parent blocks; divergence lives at an ancestor
        // merge (try $error="" vs catch $error="msg") (#32570, ThrowsWeb #2076).
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            if ($this->jitNamedLocalHasDivergentBranchCompileTimeStrings($parent, $name, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Last in-block CONCAT on a named CV wins over earlier init ASSIGN (#36244).
     */
    private function jitNamedLocalCompileTimeStringInBlock(Block $block, int $slot): ?string
    {
        foreach (array_reverse($block->opCodes) as $prior) {
            if (OpCode::TYPE_CONCAT !== $prior->type || $prior->arg1 !== $slot) {
                continue;
            }
            $leftOp = $block->getOperand((int) $prior->arg2);
            $rightOp = $block->getOperand((int) $prior->arg3);
            if (
                !$this->context->hasVariableOp($leftOp)
                || !$this->context->hasVariableOp($rightOp)
            ) {
                return null;
            }
            $left = $this->context->getVariableFromOp($leftOp);
            $right = $this->context->getVariableFromOp($rightOp);
            if (
                null !== ($left->compileTimeString ?? null)
                && null !== ($right->compileTimeString ?? null)
            ) {
                return $left->compileTimeString.$right->compileTimeString;
            }

            return null;
        }

        return null;
    }

    private function jitEffectiveNamedLocalCompileTimeString(
        Block $block,
        string $name,
        array &$visited = []
    ): ?string {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return null;
        }
        $visited[$id] = true;
        $slot = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $slot = $scopeSlot;
                break;
            }
        }
        if (null !== $slot) {
            $inBlock = $this->jitNamedLocalCompileTimeStringInBlock($block, $slot);
            if (null !== $inBlock) {
                return $inBlock;
            }
            foreach ($block->opCodes as $prior) {
                if (OpCode::TYPE_ASSIGN !== $prior->type) {
                    continue;
                }
                if (!\in_array($prior->arg2, $this->jitNamedScopeSlotAliases($block, $slot), true)) {
                    continue;
                }
                $branchVisited = [];
                $rhs = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $branchVisited);
                if (null !== $rhs) {
                    return $rhs;
                }
            }
        } else {
            // php-cfg catch/try arms may omit the CV from eachNamedScopeSlot (#32570).
            foreach ($block->opCodes as $prior) {
                if (OpCode::TYPE_ASSIGN !== $prior->type) {
                    continue;
                }
                $destOp = $block->getOperand($prior->arg2);
                if (null === $destOp || JIT\OperandName::resolve($destOp) !== $name) {
                    continue;
                }
                $branchVisited = [];
                $rhs = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $branchVisited);
                if (null !== $rhs) {
                    return $rhs;
                }
            }
        }
        if (\count($block->parents) > 1) {
            // Loop headers and branch merges: all incoming paths must agree (#36244, #36406).
            // Returning the first parent alone folded strlen($s) to init '' after a `.=` loop.
            $agreed = null;
            foreach ($block->parents as $parent) {
                if (!$parent instanceof Block) {
                    return null;
                }
                // Copy-on-write branch visited — fresh [] re-enters loop headers forever (#36406).
                $branchVisited = $visited;
                $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name, $branchVisited);
                if (null === $resolved) {
                    return null;
                }
                if (null === $agreed) {
                    $agreed = $resolved;
                } elseif ($agreed !== $resolved) {
                    return null;
                }
            }

            return $agreed;
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->jitEffectiveNamedLocalCompileTimeString($parent, $name, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        $bound = $this->context->namedVariableBindings[
            $this->context->resolveRefAliasName($name)
        ] ?? null;
        // Stale init '' on script-global boxes survives `.=` — never treat as proof (#36406).
        if (null !== $bound && null !== $bound->compileTimeString && '' !== $bound->compileTimeString) {
            return $bound->compileTimeString;
        }

        return null;
    }

    /**
     * php-cfg may bind distinct {@see Operand} objects to different scope slots for one CV
     * name (#72). Call sites can reference a different slot than the TYPE_ASSIGN dest
     * (openssl_x509_parse($pem) after `$pem = <<<'PEM'…` — #32496).
     *
     * @return list<int>
     */
    private function jitNamedScopeSlotAliases(Block $block, int $slot): array
    {
        $name = null;
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeSlot === $slot) {
                $name = $scopeName;
                break;
            }
        }
        if (null === $name || '' === $name) {
            return [$slot];
        }
        $aliases = [];
        foreach ($block->eachNamedScopeSlot() as [$scopeName, $scopeSlot]) {
            if ($scopeName === $name) {
                $aliases[] = $scopeSlot;
            }
        }

        return [] !== $aliases ? $aliases : [$slot];
    }
}
