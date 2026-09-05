<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;
use PHPCompiler\BuiltinParamNames;

/**
 * Outgoing call resolve / invoke and named/unpack arg shaping (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code densifyInternalCallArgs}
 * through {@code prependImplicitThisOperandForStaticInstanceCall} (~600 lines)
 * so the hub shrinks toward split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute_API.c / Zend/zend_API.c call binding (named args,
 * unpack, implicit $this for instance methods) — move-only Concern extract; no
 * new C ABI and no opcode/IR shape change.
 */
trait ResolveJitOutgoingCall
{
    /**
     * @param array<int, JIT\Variable> $callArgs
     *
     * @return list<JIT\Variable>
     */
    private function densifyInternalCallArgs(CoreFunc\Internal $call, array $callArgs): array
    {
        [$paramNames] = $this->jitCalleeParamMetadata($call);
        if ([] === $paramNames) {
            return $callArgs;
        }

        return JIT\NamedOptionalCallArgs::densifyForSpread($this->context, $callArgs, \count($paramNames));
    }

    /**
     * Save outer FUNCCALL_INIT state before a nested INIT overwrites it (VM #15217; AOT #27242).
     */
    private function saveJitPendingOutboundCall(): void
    {
        if (null === $this->context->scope->toCall) {
            return;
        }
        $this->context->scope->pendingOutboundCallRestore[] = [
            'toCall' => $this->context->scope->toCall,
            'args' => $this->context->scope->args,
            'argOperands' => $this->context->scope->argOperands,
            'callArgsIncludeReceiver' => $this->context->scope->callArgsIncludeReceiver,
        ];
    }

    private function clearJitOutgoingCallState(): void
    {
        $this->context->scope->toCall = null;
        $this->context->scope->args = [];
        $this->context->scope->argOperands = [];
        $this->context->scope->callArgsIncludeReceiver = false;
    }

    private function restoreJitPendingOutboundCall(): void
    {
        if ([] === $this->context->scope->pendingOutboundCallRestore) {
            return;
        }
        $saved = array_pop($this->context->scope->pendingOutboundCallRestore);
        $this->context->scope->toCall = $saved['toCall'];
        $this->context->scope->args = $saved['args'];
        $this->context->scope->argOperands = $saved['argOperands'];
        $this->context->scope->callArgsIncludeReceiver = (bool) ($saved['callArgsIncludeReceiver'] ?? false);
    }

    /**
     * METHODCALL_INIT may bind DateTimeZone::getOffset for any `$x->getOffset()`.
     * DateTime(Immutable)::getOffset() has no datetime arg — rewrite to that proxy (#30761).
     *
     * @param array<int, Variable> $callArgs
     */
    private function rewritePendingDateTimeGetOffsetIfNeeded(array $callArgs): void
    {
        if (empty($this->context->scope->pendingDateTimeZoneGetOffset)) {
            return;
        }
        if (
            $this->context->scope->toCall instanceof JIT\Call\DateTimeZoneGetOffset
            && \count($callArgs) < 2
            && $this->context->functionIsRegistered('datetime::getoffset')
        ) {
            $recv = $callArgs[0] ?? null;
            $hint = is_object($recv) ? strtolower((string) ($recv->classUserType ?? '')) : '';
            $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                'datetimeimmutable' === $hint
                    ? 'datetimeimmutable::getoffset'
                    : 'datetime::getoffset'
            );
        }
        $this->context->scope->pendingDateTimeZoneGetOffset = false;
    }

    /**
     * Dispatch a resolved call, preserving named-arg parameter indices for Native (#23972).
     *
     * @param array<int, Variable> $callArgs
     */
    private function invokeJitCall(JIT\Call $toCall, array $callArgs): \PHPLLVM\Value
    {
        JIT\DeprecatedCallGuard::emitBeforeCall($this->context, $toCall);
        // Leaf-recursive no-throw callees (fibo_r): skip uncaught-trace frames + pending
        // throw checks — they cannot appear on an exception path (#36386).
        $noThrowCallee = JIT\NoThrowCallElision::calleeIsNoThrow($this->context, $toCall, $callArgs);
        $identity = JIT\NoThrowCallElision::tryEmitTrivialIdentity($this->context, $toCall, $callArgs);
        if (null !== $identity) {
            return $identity;
        }
        $trackUncaught = !$noThrowCallee
            && JIT\Builtin\UncaughtThrowPrinter::shouldTrackCall($this->context, $toCall);
        if ($trackUncaught) {
            JIT\Builtin\UncaughtThrowPrinter::emitPushFrame($this->context, $toCall);
        }
        if ($toCall instanceof JIT\Call\Native) {
            $result = $toCall->callWithArgMap($this->context, $callArgs);
        } else {
            // Named optional middle params (DOMDocument::saveXML options:) stay sparse until
            // here; array_values alone would drop the omitted $node slot (#31396 / #32018).
            if (isset($toCall->paramNames) && \is_array($toCall->paramNames) && [] !== $toCall->paramNames) {
                $callArgs = JIT\NamedOptionalCallArgs::densifyForSpread(
                    $this->context,
                    $callArgs,
                    1 + \count($toCall->paramNames)
                );
            }
            $result = $toCall->call($this->context, ...array_values($callArgs));
        }
        if ($trackUncaught) {
            JIT\Builtin\UncaughtThrowPrinter::emitPopFrame($this->context);
        }
        // Enum::from() (and other callees) set throw-pending then return; catch here (#24219).
        if (!$noThrowCallee) {
            JIT\TryCatchHelper::emitCheckPendingThrowAfterCall($this->context);
        }

        return $result;
    }

    /**
     * Flatten ARG_SEND list; unpack entries merge into one packed list (issue #1361).
     *
     * @param list<Variable|array{unpack: Variable}> $argEntries
     *
     * @return list<Variable>
     */
    private function finalizeJitCallArgs(array $argEntries): array
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return [JIT\HashTableHelper::mergeCallArgEntries($this->context, $argEntries)];
            }
        }

        $out = [];
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['named'])) {
                $out[] = $entry['value'];
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>, 2: bool}
     */
    private function resolveJitOutgoingCall(JIT\Call $toCall, array $argEntries, array $argOperands): array
    {
        $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
        $this->context->callSiteOutgoingUserArgCount = max(0, \count($argEntries) - $prefixLen);

        if (null !== $this->context->scope->magicCallMethodName) {
            $methodName = $this->context->scope->magicCallMethodName;
            $this->context->scope->magicCallMethodName = null;
            $rewritten = JIT\MagicMethodDispatch::rewriteOutgoingMagicCallArgs(
                $this->context,
                $methodName,
                $argEntries,
                $argOperands
            );
            // Clear after rewrite — rewrite reads magicCallIsStatic (#27517).
            $this->context->scope->magicCallIsStatic = false;
            if (null !== $rewritten) {
                return [$rewritten[0], $rewritten[1], false];
            }
        }

        if ($this->jitCallArgsHaveUnpack($argEntries)) {
            // Instance methods / constructors prepend $this (NEW result). Named-arg
            // resolution already slices that prefix (#11844); unpack must too or
            // mergeCallArgEntries packs $this into the HT and CallUnpackExpand
            // either mis-indexes or drops user args → ACE "0 passed" (#34468).
            $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
            $prefix = \array_slice($argEntries, 0, $prefixLen);
            $prefixOperands = \array_slice($argOperands, 0, $prefixLen);
            $userEntries = \array_slice($argEntries, $prefixLen);
            $userOperands = \array_slice($argOperands, $prefixLen);
            $prefixVars = [];
            foreach ($prefix as $entry) {
                if ($entry instanceof Variable) {
                    $prefixVars[] = $entry;
                } elseif (\is_array($entry) && isset($entry['value']) && $entry['value'] instanceof Variable) {
                    $prefixVars[] = $entry['value'];
                }
            }

            [$paramNames, $variadicIndex] = $this->jitCalleeParamMetadata($toCall);
            $functionName = $this->jitInternalBuiltinFunctionName($toCall);
            // Prefer the block being lowered — INIT_ARRAY for ...[1,2] often lives in a
            // successor after a prior ?: / JUMPIF, not the function entry (jitEnclosingBlock).
            // Entry-only lookup drops the unpack → call_user_func forwards 0 args (#35105).
            $unpackBlock = $this->context->jitCurrentBlock ?? $this->context->jitEnclosingBlock;
            $namedUnpack = JIT\CallUnpackHelper::tryResolveCompileTimeNamedUnpack(
                $unpackBlock,
                $userEntries,
                $userOperands,
                $paramNames,
                $variadicIndex,
                $this,
                $functionName
            );
            if (null !== $namedUnpack) {
                if (
                    $toCall instanceof JIT\Call\Native
                    && 1 === \count($namedUnpack[0])
                    && Variable::TYPE_HASHTABLE === $namedUnpack[0][0]->type
                ) {
                    $expanded = JIT\CallUnpackExpand::expandPackedForNative(
                        $this->context,
                        $namedUnpack[0][0],
                        $toCall
                    );
                    if (null !== $expanded) {
                        $full = array_merge($prefixVars, $expanded);

                        return [$full, array_merge($prefixOperands, array_fill(0, \count($expanded), null)), false];
                    }
                }
                $full = array_merge($prefixVars, $namedUnpack[0]);

                return [$full, array_merge($prefixOperands, $namedUnpack[1]), false];
            }

            $callArgs = $this->finalizeJitCallArgs($userEntries);
            if (
                $toCall instanceof JIT\Call\Native
                && 1 === \count($callArgs)
                && Variable::TYPE_HASHTABLE === $callArgs[0]->type
            ) {
                $expanded = JIT\CallUnpackExpand::expandPackedForNative(
                    $this->context,
                    $callArgs[0],
                    $toCall
                );
                if (null !== $expanded) {
                    $full = array_merge($prefixVars, $expanded);

                    return [$full, array_merge($prefixOperands, array_fill(0, \count($expanded), null)), false];
                }
            }
            $full = array_merge($prefixVars, $callArgs);

            return [
                $full,
                array_merge($prefixOperands, $userOperands),
                false,
            ];
        }

        if ($this->jitCallArgsHaveNamed($argEntries)) {
            [$paramNames, $variadicIndex] = $this->jitCalleeParamMetadata($toCall);
            if ([] !== $paramNames) {
                $prefixLen = $this->jitNamedCallArgPrefixLength($toCall, $argEntries);
                $prefix = \array_slice($argEntries, 0, $prefixLen);
                $prefixOperands = \array_slice($argOperands, 0, $prefixLen);
                $userEntries = \array_slice($argEntries, $prefixLen);
                $userOperands = \array_slice($argOperands, $prefixLen);
                $calleeNative = $toCall instanceof JIT\Call\Native ? $toCall : null;
                $builtinName = $this->jitInternalBuiltinFunctionName($toCall);
                $compileTime = JIT\NamedArgs::tryCompileTimeResolveOutgoing(
                    $userEntries,
                    $userOperands,
                    $paramNames,
                    $variadicIndex,
                    $builtinName,
                    $this,
                    $calleeNative,
                    null !== $builtinName
                );
                if (null !== $compileTime) {
                    [$userArgs, $userOps] = $compileTime;
                } else {
                    try {
                        [$userArgs, $userOps] = JIT\NamedArgs::resolveOutgoing(
                            $userEntries,
                            $userOperands,
                            $paramNames,
                            $variadicIndex,
                            $builtinName,
                            $this->context,
                            null !== $builtinName
                        );
                    } catch (\ArgumentCountError $e) {
                        // Defer Zend call-binding errors to runtime so try/catch works (#23449).
                        JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                            $this->context,
                            $e->getMessage()
                        );

                        return [[], [], true];
                    } catch (\Error $e) {
                        // Defer unknown named-parameter binding to runtime (#24508, #23490).
                        if (!str_starts_with($e->getMessage(), 'Unknown named parameter $')) {
                            throw $e;
                        }
                        JIT\ExceptionBridge::emitErrorAndAbort($this->context, $e->getMessage());

                        return [[], [], true];
                    }
                }
                $callArgs = $prefix;
                foreach ($userArgs as $idx => $value) {
                    $callArgs[$prefixLen + (int) $idx] = $value;
                }
                $callOperands = $prefixOperands;
                foreach ($userOps as $idx => $operand) {
                    $callOperands[$prefixLen + (int) $idx] = $operand;
                }

                return [$callArgs, $callOperands, false];
            }
        }

        return [
            $this->finalizeJitCallArgs($argEntries),
            $argOperands,
            false,
        ];
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     */
    private function jitCallArgsHaveNamed(array $argEntries): bool
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['named'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     */
    private function jitCallArgsHaveUnpack(array $argEntries): bool
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return true;
            }
        }

        return false;
    }

    /** @internal CallUnpackHelper compile-time named unpack (#5031). */
    public function jitVariableFromVmConstantForCallUnpack(VM\Variable $vm): Variable
    {
        return $this->jitVariableFromVmConstant($vm);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function jitCalleeParamMetadata(JIT\Call $toCall): array
    {
        if ($toCall instanceof JIT\Call\Native) {
            if ([] !== $toCall->paramNames) {
                return [$toCall->paramNames, $toCall->namedArgsVariadicIndex];
            }
            $names = BuiltinParamNames::paramNamesForInternalFunction($toCall->name)
                ?? BuiltinParamNames::forClassMethod($toCall->name);

            return [$names ?? [], BuiltinParamNames::variadicParamIndexForFunction($toCall->name)];
        }
        if ($toCall instanceof CoreFunc\Internal) {
            $name = $toCall->getName();
            // VmClassMethod Internals are registered under bare names ('bind'); prefer
            // Closure::… stub tables when the active proxy key is qualified (#24591).
            // Fall through to InternalArgInfo via paramNamesForInternalFunction (#25182).
            $qualified = $this->jitQualifiedProxyNameForCall($toCall);
            if (null !== $qualified) {
                $names = BuiltinParamNames::paramNamesForInternalFunction($qualified);
                if (null !== $names) {
                    return [
                        $names,
                        BuiltinParamNames::variadicParamIndexForFunction($qualified),
                    ];
                }
            }
            $names = BuiltinParamNames::paramNamesForInternalFunction($name)
                ?? BuiltinParamNames::forClassMethod($name);

            return [
                $names ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
            ];
        }
        // Custom Call proxies (Fiber::__construct, WeakReference::create, …) (#24592).
        if (isset($toCall->paramNames) && \is_array($toCall->paramNames) && [] !== $toCall->paramNames) {
            $variadic = $toCall->namedArgsVariadicIndex ?? null;

            return [$toCall->paramNames, \is_int($variadic) ? $variadic : null];
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall) {
            // Closure::$c->call(newThis: …) / bindTo — candidate set is class-id keyed (#24591).
            $qualified = 'closure::'.$toCall->methodLc;
            $names = BuiltinParamNames::forClassMethod($qualified);
            if (null !== $names) {
                return [
                    $names,
                    BuiltinParamNames::variadicParamIndexForFunction($qualified),
                ];
            }
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            $names = BuiltinParamNames::forClassMethod($qualified)
                ?? BuiltinParamNames::paramNamesForInternalFunction($qualified);
            if (null !== $names && [] !== $names) {
                return [
                    $names,
                    BuiltinParamNames::variadicParamIndexForFunction($qualified),
                ];
            }
        }
        if (isset($toCall->name) && \is_string($toCall->name) && '' !== $toCall->name) {
            $names = BuiltinParamNames::forClassMethod($toCall->name)
                ?? BuiltinParamNames::paramNamesForInternalFunction($toCall->name);

            return [$names ?? [], BuiltinParamNames::variadicParamIndexForFunction($toCall->name)];
        }

        return [[], null];
    }

    /** Reverse-lookup class::method proxy key for a dedicated JIT Call object (#24591). */
    private function jitQualifiedProxyNameForCall(JIT\Call $toCall): ?string
    {
        foreach ($this->context->functionProxies as $proxyName => $proxy) {
            if ($proxy !== $toCall) {
                continue;
            }
            $name = (string) $proxyName;
            if (str_contains($name, '::')) {
                return strtolower($name);
            }
        }

        return null;
    }

    private function jitInternalBuiltinFunctionName(JIT\Call $toCall): ?string
    {
        if ($toCall instanceof JIT\Call\Native) {
            return $toCall->name;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return $this->jitQualifiedProxyNameForCall($toCall) ?? $toCall->getName();
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            return $qualified;
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall) {
            $qualified = 'closure::'.$toCall->methodLc;
            if (null !== BuiltinParamNames::forClassMethod($qualified)) {
                return $qualified;
            }
        }
        if (isset($toCall->name) && \is_string($toCall->name) && '' !== $toCall->name) {
            return $toCall->name;
        }

        return null;
    }

    /**
     * Leading $this / NEW result args must not participate in named-arg index resolution (#11844).
     *
     * @param list<Variable|array<string, mixed>> $argEntries
     */
    private function jitNamedCallArgPrefixLength(JIT\Call $toCall, array $argEntries): int
    {
        if ([] === $argEntries || \is_array($argEntries[0])) {
            return 0;
        }
        if (isset($toCall->namedArgsReceiverPrefix) && \is_int($toCall->namedArgsReceiverPrefix)) {
            return max(0, $toCall->namedArgsReceiverPrefix);
        }
        if ($toCall instanceof JIT\Call\Native && [] !== $toCall->argTypes) {
            return '__object__*' === $this->context->getStringFromType($toCall->argTypes[0]) ? 1 : 0;
        }
        // Instance-method proxies prepend $this before user args (Closure::call/bindTo, #24591).
        // DOM JIT Call\Dom* helpers are always instance methods (#25182).
        if ($toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            || $toCall instanceof JIT\Call\ClosureBindTo
            || str_starts_with($toCall::class, 'PHPCompiler\\JIT\\Call\\Dom')
        ) {
            return 1;
        }
        $qualified = $this->jitQualifiedProxyNameForCall($toCall);
        if (null !== $qualified) {
            if (str_ends_with($qualified, '::call') || str_ends_with($qualified, '::bindto')) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Static parent::__construct() from an instance method passes only declared params;
     * the callee LLVM signature may still include implicit $this when blockUsesThis().
     *
     * @param array<int, Variable> $args
     *
     * @return array<int, Variable>
     */
    private function prependImplicitThisForStaticInstanceCall(
        Block $block,
        JIT\Call $toCall,
        array $args
    ): array {
        if ($toCall instanceof JIT\Call\RuntimeIndirectStaticMethodCall && $toCall->bindCallerThis) {
            $thisVar = $this->resolveThisVariable($block);
            if (null === $thisVar) {
                return $args;
            }
            array_unshift($args, $thisVar);

            return $args;
        }
        if (!$toCall instanceof JIT\Call\Native) {
            return $args;
        }
        if ([] === $toCall->argTypes) {
            return $args;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $args;
        }
        // TYPE_NEW / $obj->m() already seeded the receiver — never double-prepend
        // (that shifted typed args and broke Slim Request construction, #36382).
        // Do NOT use minimumPositionalArgCountWithReceiver(): parent::__construct($a,$b,$c,$d)
        // with explicit nullable optionals has count >= minimum while still missing $this
        // (AppFactory::create → App::__construct, #36382).
        if ($this->context->scope->callArgsIncludeReceiver) {
            return $args;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $args;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return $args;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar) {
            return $args;
        }

        array_unshift($args, $thisVar);

        return $args;
    }

    /**
     * @param list<Variable|array{unpack: Variable}> $args
     * @param list<Operand|null> $operands
     *
     * @return list<Operand|null>
     */
    private function prependImplicitThisOperandForStaticInstanceCall(
        Block $block,
        JIT\Call\Native $toCall,
        array $operands
    ): array {
        if ([] === $toCall->argTypes) {
            return $operands;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $operands;
        }
        if ($this->context->scope->callArgsIncludeReceiver) {
            return $operands;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $operands;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return $operands;
        }
        if (null === $this->resolveThisVariable($block)) {
            return $operands;
        }

        array_unshift($operands, null);

        return $operands;
    }
}
