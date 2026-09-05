<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPTypes\Type;
use PHPLLVM;

/**
 * Block PHP lowering and closure precompile / return-proxy bookkeeping (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileBlockPhpLowering}
 * through {@code isClosureNativeInvokeName} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c (function/closure compile), Zend/zend_closures.c
 * (closure invoke / binding), Zend/zend_execute_API.c (call proxies) — move-only
 * Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait CompileBlockPhpLoweringAndClosurePrep
{
    private function compileBlockPhpLowering(
        string $internalName,
        Block $block,
        ?string $logicalName,
        ?string $funcName
    ): PHPLLVM\Value {
        // Note: edit-scaffold keep-path reuses unchanged member LLVM bodies via
        // {@see CompileCache::isKeptUserSymbol()} below — never early-return on
        // restored helpers (NestedJIT must rebind; that bug yielded empty stdout) (#36387).
        $args = [];
        $rawTypes = [];
        $argVars = [];
        $returnsByRef = false;
        $isVoidReturn = false;
        if (!is_null($block->func)) {
            $returnsByRef = $this->cfgFunctionReturnsByRef($block->func);
            $callbackType = $returnsByRef
                ? '__value__*'
                : ($this->cfgFunctionReturnCallbackType($block->func) ?? '__value__');
            $methodLc = strtolower($block->func->name);
            if (
                '__construct' === $methodLc
                || '__destruct' === $methodLc
                || str_ends_with($methodLc, '::__destruct')
            ) {
                $callbackType = 'void';
            }
            // M5 argv NestedJIT of PHPCfg\Parser::parse: untyped CFG defaults to __value__
            // return + mixed params, but RuntimeParseM5Native calls
            // parse(__object__*, __string__*, __string__*) -> __object__* (Script) (#27426).
            if ($this->isM5NestedJitPhpCfgParserParse($logicalName)) {
                $callbackType = '__object__*';
            }
            // Capture before appending `(*)(…)` — elision registry must see void returns
            // with typed params (`void(*)(__string__*)`), not only bare `void` (#36386).
            $isVoidReturn = 'void' === $callbackType;
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($logicalName ?? $internalName)] = $callbackType;

            if ($this->instanceMethodUsesThis($block) || $this->closureBodyUsesThis($block)) {
                $rawTypes[] = Type::object();
                $args[] = $this->context->getTypeFromString('__object__*');
            }
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($args as $type) {
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
            }
            foreach ($block->func->params as $idx => $param) {
                $rawType = $this->rawTypeFromCfgParam($param);
                $type = $this->llvmTypeForCfgParam($param, $block, $idx);
                // M5 argv NestedJIT of Runtime::parse: keep string formals as __string__*
                // even if CFG marks them mixed after prepare-skip branches — callers pass
                // __string__* from file_get_contents (#26756).
                if (
                    $this->shouldUseM5DriverHostCompile()
                    && JIT\NestedJitCompileScope::isActive()
                    && null !== $logicalName
                    && str_ends_with(strtolower($logicalName), '\\runtime::parse')
                ) {
                    $declName = null;
                    if ($param->declaredType instanceof Op\Type\Literal) {
                        $declName = strtolower($param->declaredType->name);
                    }
                    if (
                        'string' === $declName
                        || Type::TYPE_STRING === ($rawType->type ?? null)
                    ) {
                        $type = $this->context->getTypeFromString('__string__*');
                        $rawType = Type::string();
                    }
                }
                // PHPCfg\Parser::parse($code, $fileName) — no declared types in vendor;
                // force __string__* so RuntimeParseM5Native call sites type-check (#27426).
                if ($this->isM5NestedJitPhpCfgParserParse($logicalName)) {
                    $type = $this->context->getTypeFromString('__string__*');
                    $rawType = Type::string();
                }
                // M5ParserAstPeer::parse(string $code, …) — keep first formal as __string__* (#27426).
                if ($this->isM5NestedJitM5ParserAstPeerParse($logicalName) && 0 === $idx) {
                    $type = $this->context->getTypeFromString('__string__*');
                    $rawType = Type::string();
                }
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            foreach (JIT\ClosureHelper::orderedCaptureSlots($block) as $_captureSlot) {
                $captureType = $this->context->getTypeFromString('__value__*');
                $callbackType .= $callbackSep . '__value__*';
                $callbackSep = ', ';
                $rawTypes[] = Type::mixed();
                $args[] = $captureType;
            }
            if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
                $args = $this->normalizeSelfHostNativeCallArgTypes($args, $logicalName);
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $isVarArgs = false;

        // Keep-path: reuse unchanged user LLVM body from edit-scaffold. Helpers are never
        // in keptUserSymbols — NestedJIT early-return there emptied AOT stdout (#36387).
        if (
            JIT\CompileCache::isEditScaffoldActive()
            && !JIT\NestedJitCompileScope::isActive()
            && JIT\CompileCache::isKeptUserSymbol($internalName)
        ) {
            $existing = $this->context->module->getNamedFunction($internalName);
            if ($existing instanceof PHPLLVM\Value\Function_) {
                $cfgParamCount = null !== $block->func ? count($block->func->params) : 0;
                $thisParamOffset = $this->llvmThisParamOffset($block);
                foreach ($args as $idx => $arg) {
                    $varType = Variable::getTypeFromType($rawTypes[$idx]);
                    $cfgIdx = $idx - $thisParamOffset;
                    $cfgParam = ($cfgIdx >= 0 && $cfgIdx < $cfgParamCount)
                        ? $block->func->params[$cfgIdx]
                        : null;
                    $llvmParamTy = $this->context->getStringFromType($arg);
                    if ('__value__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_VALUE;
                    } elseif ('__object__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_OBJECT;
                    } elseif ('__hashtable__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_HASHTABLE;
                    } elseif ('__string__*' === $llvmParamTy) {
                        $varType = Variable::TYPE_STRING;
                    }
                    if (null !== $cfgParam && $cfgParam->variadic) {
                        $varType = Variable::TYPE_HASHTABLE;
                    }
                    $argVars[] = new Variable($this->context, $varType, Variable::KIND_VALUE, $existing->getParam($idx));
                }

                $lcname = strtolower($logicalName ?? $internalName);
                $this->context->functions[$lcname] = $existing;
                $this->context->functionLlvmSymbols[$lcname] = $internalName;
                $this->context->activeFunction = $lcname;
                if (JIT\CompileCache::isRecording()) {
                    JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                }
                if (!is_null($funcName)) {
                    $lcname = strtolower($funcName);
                    $this->context->activeFunction = $lcname;
                    $this->context->functions[$lcname] = $existing;
                    $this->context->functionLlvmSymbols[$lcname] = $internalName;
                    if (JIT\CompileCache::isRecording()) {
                        JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                    }
                    $defaultArgs = $this->collectParamDefaults($block);
                    $variadicArgIndex = null;
                    if (null !== $block->variadicParamIndex) {
                        $variadicArgIndex = $block->variadicParamIndex;
                        if ($this->llvmThisParamOffset($block) > 0) {
                            ++$variadicArgIndex;
                        }
                    }
                    $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                        $existing,
                        VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($block->func, $funcName, $block),
                        $args,
                        $defaultArgs,
                        $variadicArgIndex,
                        $this->paramTypeConstraintsForNativeCall($block),
                        $this->paramIntersectionConstraintsForNativeCall($block),
                        $this->paramDnfConstraintsForNativeCall($block),
                        $this->paramClassConstraintsForNativeCall($block),
                        $this->paramByRefForNativeCall($block),
                        $block->paramNames,
                        $block->variadicParamIndex,
                        $this->paramImplicitNullableForNativeCall($block),
                        Block::usesFuncArgsIntrospection($block),
                        $this->collectPromotedRuntimeNewDefaultProps($block)
                    );
                    JIT\NoDiscardCallGuard::registerCallee($this->context, $funcName, $block);
                    JIT\DeprecatedCallGuard::registerCallee($this->context, $funcName, $block);
                    if (
                        $isVoidReturn
                        && Block::isEffectFreeVoidCalleeBody($block)
                        && !$block->noDiscard
                        && null === $block->deprecated
                        && !Block::usesFuncArgsIntrospection($block)
                    ) {
                        $this->context->discardedCallElisionVoidNatives[$lcname] = true;
                    }
                    if ($returnsByRef) {
                        $this->markFunctionReturnsByRef($lcname, $funcName ?? '');
                    }
                }

                // Body already in module — do not queue re-lower.
                return $existing;
            }
        }

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        $cfgParamCount = null !== $block->func ? count($block->func->params) : 0;
        // $args/$rawTypes are LLVM-shaped (optional $this at 0). CFG params are not —
        // indexing func->params[$llvmIdx] mis-attributes a trailing variadic onto the
        // preceding formal (Context typed as HT, ...$args left as object) and fails
        // module verify on writeHashtable/setObjectAt (#24429 ext/ds DsFactoryFunction::call).
        $thisParamOffset = $this->llvmThisParamOffset($block);
        foreach ($args as $idx => $arg) {
            $varType = Variable::getTypeFromType($rawTypes[$idx]);
            $cfgIdx = $idx - $thisParamOffset;
            $cfgParam = ($cfgIdx >= 0 && $cfgIdx < $cfgParamCount)
                ? $block->func->params[$cfgIdx]
                : null;
            $llvmParamTy = $this->context->getStringFromType($arg);
            if ('__value__*' === $llvmParamTy) {
                $varType = Variable::TYPE_VALUE;
            } elseif ('__object__*' === $llvmParamTy) {
                $varType = Variable::TYPE_OBJECT;
            } elseif ('__hashtable__*' === $llvmParamTy) {
                $varType = Variable::TYPE_HASHTABLE;
            } elseif ('__string__*' === $llvmParamTy) {
                $varType = Variable::TYPE_STRING;
            }
            if (
                null !== $cfgParam
                && JIT\NestedJitCompileScope::isActive()
                && $this->isCfgVmVariableParamType(
                    $this->declaredTypeFromCfgParam($cfgParam)
                )
            ) {
                $varType = Variable::TYPE_VALUE;
            }
            if (
                null !== $cfgParam
                && JIT\NestedJitCompileScope::isActive()
                && $this->isCfgVmHashTableParamType(
                    $this->declaredTypeFromCfgParam($cfgParam)
                )
            ) {
                $varType = Variable::TYPE_HASHTABLE;
            }
            if (null !== $cfgParam && $cfgParam->variadic) {
                $varType = Variable::TYPE_HASHTABLE;
            }
            $argVars[] = new Variable($this->context, $varType, Variable::KIND_VALUE, $func->getParam($idx));
        }

        $lcname = strtolower($logicalName ?? $internalName);
        $this->context->functions[$lcname] = $func;
        $this->context->functionLlvmSymbols[$lcname] = $internalName;
        $this->context->activeFunction = $lcname;
        if (JIT\CompileCache::isRecording()) {
            if (JIT\NestedJitCompileScope::isActive()) {
                JIT\CompileCache::recordHelperLogical($lcname, $internalName);
            } else {
                JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
            }
        }
        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->activeFunction = $lcname;
            $this->context->functions[$lcname] = $func;
            $this->context->functionLlvmSymbols[$lcname] = $internalName;
            if (JIT\CompileCache::isRecording()) {
                if (JIT\NestedJitCompileScope::isActive()) {
                    JIT\CompileCache::recordHelperLogical($lcname, $internalName);
                } else {
                    JIT\CompileCache::recordUserLlvmSymbol($internalName, $block);
                }
            }
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $variadicArgIndex = null;
                if (null !== $block->variadicParamIndex) {
                    $variadicArgIndex = $block->variadicParamIndex;
                    if ($this->llvmThisParamOffset($block) > 0) {
                        ++$variadicArgIndex;
                    }
                }
                $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                    $func,
                    VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($block->func, $funcName, $block),
                    $args,
                    $defaultArgs,
                    $variadicArgIndex,
                    $this->paramTypeConstraintsForNativeCall($block),
                    $this->paramIntersectionConstraintsForNativeCall($block),
                    $this->paramDnfConstraintsForNativeCall($block),
                    $this->paramClassConstraintsForNativeCall($block),
                    $this->paramByRefForNativeCall($block),
                    $block->paramNames,
                    $block->variadicParamIndex,
                    $this->paramImplicitNullableForNativeCall($block),
                    Block::usesFuncArgsIntrospection($block),
                    $this->collectPromotedRuntimeNewDefaultProps($block)
                );
                JIT\NoDiscardCallGuard::registerCallee($this->context, $funcName, $block);
                JIT\DeprecatedCallGuard::registerCallee($this->context, $funcName, $block);
                if (
                    $isVoidReturn
                    && Block::isEffectFreeVoidCalleeBody($block)
                    && !$block->noDiscard
                    && null === $block->deprecated
                    && !Block::usesFuncArgsIntrospection($block)
                ) {
                    $this->context->discardedCallElisionVoidNatives[$lcname] = true;
                }
            }
            if ($returnsByRef) {
                $this->markFunctionReturnsByRef($lcname, $funcName ?? '');
            }
        }

        $this->precompileClosuresBeforeQueue($block);
        // CFG-only no-throw analysis must run at enqueue — `{main}` lowers method
        // calls before runQueue fills bodies, so body-time analyzeAndRecord is too
        // late for call-site ex_stack / pending-throw elision (#36386).
        if (null !== $block->func && '{main}' !== $block->func->name) {
            $analyzeName = $logicalName ?? $block->func->getScopedName();
            JIT\NoThrowCallElision::analyzeAndRecord(
                $this->context,
                $block,
                strtolower((string) $analyzeName)
            );
        }
        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()' && !Block::containsNonLiteralEvalOpcodes($block)) {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }

    /**
     * Compile nested TYPE_CLOSURE bodies before the enclosing function is queued so
     * `{closure}_N` proxies exist when main lowers `$f = m(); $f()` (#34868).
     *
     * Without this, FUNCCALL_INIT runs before runQueue, closureCandidates() is empty,
     * resolveIndirectCall returns null, and the invoke becomes a null call.
     *
     * Skip when this block also DECLARE_CLASS/INTERFACE/TRAIT/ENUM: precompile would
     * lower STATIC_PROPERTY_FETCH / class-const before TYPE_DECLARE_* runs, baking
     * "undeclared static property" into top-level closures (#34896 leftover of #34868).
     * Method bodies never DECLARE_CLASS — precompile there still covers #34868.
     * Top-level closures compile later at TYPE_CLOSURE (after declares in runQueue).
     */
    private function precompileClosuresBeforeQueue(Block $block): void
    {
        if ($this->blockDeclaresClassLike($block)) {
            return;
        }
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_CLOSURE !== $op->type || null === $op->block1) {
                continue;
            }
            if (null !== $op->closurePrecompiledInternalName) {
                continue;
            }
            if ($this->shouldStubClosureLowering()) {
                continue;
            }
            // Fiber::suspend callbacks must use compileResumeFunction at TYPE_CLOSURE —
            // precompileClosureBodyBlock hits FiberSuspendStatic outside resume context
            // (#34868 interaction with #4019; AOT fiber_suspend.phpt).
            if (JIT\FiberHelper::blockContainsFiberSuspend($op->block1)) {
                continue;
            }
            $internalName = JIT\ClosureHelper::nextInternalName();
            $op->closurePrecompiledInternalName = $internalName;
            $this->compileClosureBodyBlock($block, $op->block1, $internalName);
            $lcname = strtolower($internalName);
            if (!isset($this->context->functionProxies[$lcname])) {
                continue;
            }
            $proxy = $this->context->functionProxies[$lcname];
            // Captures wrap must wait until TYPE_CLOSURE (enclosing locals exist then).
            // Recording bare Native here is OK: FUNCCALL_INIT / resolveCall promote
            // `Class::{closure}` Native to RuntimeIndirect so $this reloads from the heap
            // (#35456). TYPE_CLOSURE also refreshes with ClosureWithBinding when present.
            $n = \count($block->opCodes);
            for ($j = $i + 1; $j < $n; ++$j) {
                $next = $block->opCodes[$j];
                if (OpCode::TYPE_RETURN === $next->type && null !== $next->arg1
                    && (int) $next->arg1 === (int) $op->arg1
                ) {
                    $this->recordReturnedClosureProxyForBlock($block, $proxy);
                    break;
                }
                if (OpCode::TYPE_CLOSURE === $next->type || OpCode::TYPE_RETURN === $next->type) {
                    break;
                }
            }
        }
    }

    /** True when the block declares a class-like before runQueue (#34896). */
    private function blockDeclaresClassLike(Block $block): bool
    {
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_DECLARE_CLASS === $op->type
                || OpCode::TYPE_DECLARE_INTERFACE === $op->type
                || OpCode::TYPE_DECLARE_TRAIT === $op->type
                || OpCode::TYPE_DECLARE_ENUM === $op->type
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param JIT\Call $proxy */
    private function recordReturnedClosureProxyForBlock(Block $block, $proxy): void
    {
        if (null === $block->func) {
            return;
        }
        $funcName = $block->func->name ?? null;
        if (!is_string($funcName) || '' === $funcName || '{main}' === $funcName) {
            return;
        }
        $lc = strtolower($funcName);
        if (null !== $block->func->class && is_string($block->func->class->value ?? null)) {
            $classLc = strtolower(ltrim((string) $block->func->class->value, '\\'));
            if ('' !== $classLc) {
                $lc = $classLc.'::'.$lc;
            }
        }
        $this->context->functionReturnedClosureCall[$lc] = $proxy;
    }

    /** Native invoke names for closures: `{closure}_N` or `Class::{closure}` (#35456). */
    private static function isClosureNativeInvokeName(string $name): bool
    {
        $lc = strtolower($name);

        return str_starts_with($lc, '{closure}_')
            || str_contains($lc, '::{closure}')
            || '{closure}' === $lc;
    }
}
