<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Nested VM-helper method init and instance/$this resolution (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code jitInstanceMethodReceiverVariable}
 * through {@code resolveThisVariable} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute_API.c / zend_object_handlers.c method lookup and
 * $this binding — move-only Concern extract; no new C ABI and no opcode/IR
 * shape change.
 */
trait NestedVmHelperAndThisResolve
{
    private function jitInstanceMethodReceiverVariable(Variable $receiverVar): Variable
    {
        if (Variable::TYPE_VALUE !== $receiverVar->type) {
            return $receiverVar;
        }
        $objVal = JIT\ClosureHelper::loadObjectFromCallable($this->context, $receiverVar);
        $objVar = new Variable(
            $this->context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $objVal
        );
        $objVar->addref();

        return $objVar;
    }

    /**
     * Nested loadHTML helper compile can leave method-call receiver temps without script-global alias (#17954).
     */
    private function isNestedJitHelperScopeClassName(string $className): bool
    {
        $lc = strtolower(ltrim($className, '\\'));

        return str_ends_with($lc, 'jithelper')
            || str_starts_with($lc, 'phpcompiler\\ext\\')
            || str_starts_with($lc, 'phpcompiler\\jit\\builtin\\')
            || str_starts_with($lc, 'phpcompiler\\vm\\');
    }

    private function resolveUserScriptDomDocumentReceiver(
        Block $block,
        Operand $receiverOp,
        string $declaringClassLc,
        string $methodLc,
        Variable $receiverVar
    ): Variable {
        if (!JIT\DomInstanceMethodJit::shouldDeferToVmClassMethodLowering()) {
            return $receiverVar;
        }
        if ('domdocument' !== $declaringClassLc) {
            return $receiverVar;
        }
        if (!\in_array($methodLc, ['loadhtml', 'getelementbyid', 'createelement'], true)) {
            return $receiverVar;
        }
        if (null !== $receiverVar->valueBoxAliasPtr || $receiverVar->functionStaticGlobal) {
            return $receiverVar;
        }

        $name = JIT\OperandName::resolve($receiverOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                return $this->context->namedVariableBindings[$resolved];
            }
        }

        $slot = $block->slotForOperand($receiverOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null === $scopeName || '' === $scopeName) {
                    continue;
                }
                $resolved = $this->context->resolveRefAliasName($scopeName);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    return $this->context->namedVariableBindings[$resolved];
                }
            }
        }

        return $receiverVar;
    }

    /** Nested JIT: VM HashTable/Variable helpers for php-in-PHP ext helpers (#12910). */
    private function tryInitNestedVmHelperMethodCall(
        string $declaringClassLc,
        string $methodLc,
        Variable $receiverVar
    ): bool {
        if (!JIT\NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()) {
            return false;
        }
        // Prefer TYPE_HASHTABLE / HashTable class before ObjectEntry — both expose
        // compareSpaceship; wrong bridge fails NestedJIT module verify (#21109).
        if (
            JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod($methodLc)
            && (
                'phpcompiler\\vm\\hashtable' === $declaringClassLc
                || Variable::TYPE_HASHTABLE === $receiverVar->type
            )
        ) {
            if (!JIT\NestedVmHashTableMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\hashtable::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            JIT\NestedVmObjectMethodLlvm::isNestedObjectMethod($methodLc)
            && (
                'phpcompiler\\vm\\objectentry' === $declaringClassLc
                || 'object' === $declaringClassLc
                || (
                    Variable::TYPE_OBJECT === $receiverVar->type
                    && Variable::TYPE_HASHTABLE !== $receiverVar->type
                )
            )
        ) {
            if (!JIT\NestedVmObjectMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\objectentry::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod($methodLc)) {
            // Leaked NestedJIT receiver userType (enums etc.) — catch-all after Object (#21109).
            if (!JIT\NestedVmHashTableMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\hashtable::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            JIT\NestedContextMethodLlvm::isNestedContextMethod($methodLc)
            && ('phpcompiler\\vm\\context' === $declaringClassLc || 'object' === $declaringClassLc)
        ) {
            if (!JIT\NestedContextMethodLlvm::ensureMethod($this->context, $methodLc)) {
                return false;
            }
            $proxyName = 'phpcompiler\\vm\\context::'.$methodLc;
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (
            'coercevariabletostring' === $methodLc
            && ('phpcompiler\\vm' === $declaringClassLc || 'object' === $declaringClassLc)
        ) {
            $proxyName = 'phpcompiler\\vm::coercevariabletostring';
            if (!$this->context->functionIsRegistered($proxyName)) {
                $this->context->functionProxies[$proxyName] = new JIT\Call\VmCoerceVariableToString();
            }
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [$receiverVar];

            return true;
        }
        if (!JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod($methodLc)) {
            return false;
        }
        // Bare `Variable` (use-import) must match FQCN — same as isCfgVmVariableParamType (#20785).
        // NestedJIT helper className fallback (DomCreateElementJitHelper etc.): still accept
        // when the receiver is a value-box Variable param (#22678 AOT append/replaceChild).
        // Also accept *JitHelper declaringClass when NestedJIT leaked scope->className onto
        // `new Variable()` temps ($x->null() → ArrayReduceJitHelper::null, #24117).
        // Same leak on NestedJIT'd Vm* SSOT classes: `$outVars[$i]->byRefTarget()` →
        // VmSscanf::byreftarget (#27663 fscanf/vfscanf AOT).
        if (
            'phpcompiler\\vm\\variable' !== $declaringClassLc
            && 'object' !== $declaringClassLc
            && 'variable' !== $declaringClassLc
            && !str_ends_with($declaringClassLc, '\\vm\\variable')
            && !(Variable::TYPE_VALUE === $receiverVar->type)
            && !str_ends_with($declaringClassLc, 'jithelper')
            && !preg_match('/\\\\vm[a-z0-9_]*$/', $declaringClassLc)
        ) {
            return false;
        }
        if (!JIT\NestedVmVariableMethodLlvm::ensureMethod($this->context, $methodLc)) {
            return false;
        }
        $proxyName = 'phpcompiler\\vm\\variable::'.$methodLc;
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [$receiverVar];

        return true;
    }

    /** Lazily register Runtime inventory-argv stubs on helloworld bin/compile.php (#12036). */
    private function tryInitInventoryArgvRuntimeParseHelperCall(
        string $methodLc,
        Variable $dispatchReceiver
    ): bool {
        if (!$this->shouldEnsureInventoryArgvParseHelperStubs()) {
            return false;
        }
        $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
        if ('standalone' === $methodLc && null !== $stubBlock) {
            $logical = 'PHPCompiler\\Runtime::standalone';
            $lc = strtolower($logical);
            if (!$this->context->functionIsRegistered($lc)) {
                $this->emitM3EmitTuRuntimeStandaloneStubNative(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
            if ($this->context->functionIsRegistered($lc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
                $this->context->scope->args = [$dispatchReceiver];

                return true;
            }
        }
        if (('parseandcompile' === $methodLc || 'parseandcompileemitsmoke' === $methodLc) && null !== $stubBlock) {
            $this->ensureM3EmitTuRuntimeParseAndCompileDeclBeforeQueue(
                ['parseandcompile' => true, 'parseandcompileemitsmoke' => true],
                $stubBlock
            );
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if ($this->context->functionIsRegistered($lc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
                $this->context->scope->args = [$dispatchReceiver];

                return true;
            }
        }
        static $allowed = [
            'detectfilestricttypes' => true,
            'resetparsernameresolverstate' => true,
            'formatparseandcompilenulldetail' => true,
            'emitparseandcompilenulldiagnostic' => true,
            'recordlastparsefailure' => true,
            'formatphpparsererrorcontext' => true,
            'emitparsecompilefailurestderr' => true,
            'setdebug' => true,
            'setaotdebugsymbols' => true,
        ];
        if (!isset($allowed[$methodLc])) {
            return false;
        }
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (!$this->context->functionIsRegistered($lc)) {
            $this->ensureM3EmitTuRuntimeParseSpineDeps();
        }
        if (!$this->context->functionIsRegistered($lc)) {
            return false;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($lc);
        $this->context->scope->args = [$dispatchReceiver];

        return true;
    }

    /**
     * Map instance method names on one class to proxies for `$obj->$m()` (#36380 / #34084).
     *
     * Peer of {@see buildRuntimeStaticMethodCandidatesByMethodName} for `Class::$m()` (#34937)
     * and {@see \PHPCompiler\VM\VmFromCallable} bound-array callables (#36382).
     *
     * @return array<string, JIT\Call> lowercase method => proxy
     */
    private function buildRuntimeInstanceMethodCandidatesByMethodName(string $declaredLc): array
    {
        $declaredLc = strtolower(ltrim($declaredLc, '\\'));
        if ('' === $declaredLc || 'object' === $declaredLc) {
            return [];
        }
        if (!$this->context->type->object->hasDeclaredClass($declaredLc)) {
            return [];
        }
        $classId = $this->context->type->object->lookup($declaredLc);
        $candidates = [];
        foreach ($this->context->type->object->allMethodNamesForClassId($classId, 0) as $display) {
            $methodLc = strtolower((string) $display);
            if ('__construct' === $methodLc || '__destruct' === $methodLc) {
                continue;
            }
            if ($this->context->type->object->hasMethod($classId, $methodLc)) {
                $vis = $this->context->type->object->methodVisibility($classId, $methodLc);
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            }
            $proxyName = $this->softResolveJitInstanceMethodProxyName($declaredLc, $methodLc);
            if (null === $proxyName || !$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            $proxy = $this->context->resolveFunctionProxy($proxyName);
            // User methods are Native; arity-strict Call stubs abort when every arm is emitted
            // with this site's argc (peer VmFromCallable / #36382).
            if (!($proxy instanceof JIT\Call\Native) && !($proxy instanceof JIT\Call\Vararg)) {
                continue;
            }
            $candidates[$methodLc] = $proxy;
        }
        ksort($candidates);

        return $candidates;
    }

    /**
     * Soft walk of extends/trait proxies — never throws on missing SPL stubs (#36380).
     */
    private function softResolveJitInstanceMethodProxyName(string $classLc, string $methodLc): ?string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        if ('simplemxml_element' === $current) {
            $current = 'simplexmlelement';
        }
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($this->context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            if ($this->context->type->object->hasDeclaredClass($current)) {
                $cid = $this->context->type->object->lookup($current);
                $traitLc = $this->context->type->object->traitMethodSource($cid, $methodLc);
                if (null !== $traitLc) {
                    $traitProxy = strtolower($traitLc).'::'.$methodLc;
                    if ($this->context->functionIsRegistered($traitProxy)) {
                        return $traitProxy;
                    }
                }
            }
            $parent = $this->context->type->object->parentClassLc($current);
            if (null === $parent || '' === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * @return array<int, JIT\Call> class id => invoke proxy
     */
    private function buildRuntimeInstanceMethodCandidatesByClassId(string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($this->context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            // Instance dispatch must not invoke static methods that share a short name
            // (HashTable::add vs OutputRewriteVarsJitHelper::add) (#23468).
            if ($this->context->type->object->hasMethod($classId, $methodLc)) {
                $vis = $this->context->type->object->methodVisibility($classId, $methodLc);
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            }
            $proxyName = $this->resolveJitInstanceMethodProxyName($classLc, $methodLc);
            if (!$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            // Static methods are not instance-dispatch targets (zend_execute). Including
            // them mixes e.g. OutputRewriteVarsJitHelper::add into HashTable->$add (#23468).
            if ($this->context->type->object->hasDeclaredClass($classLc)) {
                $vis = $this->context->type->object->methodVisibility(
                    $this->context->type->object->lookup($classLc),
                    $methodLc
                );
                if (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                    continue;
                }
            } elseif (
                // Proxy without visibility metadata: still exclude known static rewrite-var helper.
                'phpcompiler\\ext\\standard\\outputrewritevarsjithelper' === $classLc
            ) {
                continue;
            }
            $candidates[$classId] = $this->context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    /**
     * Subtype-filtered instance dispatch when the declared receiver type has no body
     * (interface / abstract method). Mirrors Zend `zend_std_get_method` (#36382).
     *
     * @return array<int, JIT\Call> class id => invoke proxy
     */
    private function buildRuntimeInstanceMethodCandidatesForDeclaredType(
        string $declaredLc,
        string $methodLc
    ): array {
        $declaredLc = strtolower(ltrim($declaredLc, '\\'));
        if ('' === $declaredLc || 'object' === $declaredLc) {
            return $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
        }
        $allowed = array_flip($this->context->type->object->classIdsInstanceOf($declaredLc));
        if ([] === $allowed) {
            return [];
        }
        $candidates = [];
        foreach ($this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc) as $classId => $call) {
            if (isset($allowed[$classId])) {
                $candidates[$classId] = $call;
            }
        }

        return $candidates;
    }

    /**
     * Safe `__construct` candidates for `new $class` (#27156).
     *
     * Custom Call proxies (LimitIteratorConstruct, …) validate PHP arg counts while
     * emitting every switch arm — skip those. Classes without a constructor get
     * {@see JIT\Call\NoOpConstruct} so stdClass does not abort when Exception is also present.
     *
     * @return array<int, JIT\Call>
     */
    private function buildRuntimeNewConstructCandidatesByClassId(): array
    {
        $object = $this->context->type->object;
        $candidates = [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            if (null !== JIT\InstantiableClassJitGuard::userInstantiationErrorMessage($object, $classId)) {
                continue;
            }
            $classLc = strtolower(ltrim($className, '\\'));
            $proxyName = $this->resolveJitInstanceMethodProxyName($classLc, '__construct');
            if ($this->context->functionIsRegistered($proxyName)) {
                $proxy = $this->context->resolveFunctionProxy($proxyName);
                if ($this->isSafeRuntimeNewConstructProxy($proxy)) {
                    $candidates[$classId] = $proxy;
                    continue;
                }
            }
            $candidates[$classId] = new JIT\Call\NoOpConstruct();
        }

        return $candidates;
    }

    private function isSafeRuntimeNewConstructProxy(JIT\Call $proxy): bool
    {
        return $proxy instanceof JIT\Call\Native
            || $proxy instanceof JIT\Call\ExceptionConstruct
            || $proxy instanceof JIT\Call\SensitiveParameterValueConstruct
            || $proxy instanceof JIT\Call\Vararg
            || $proxy instanceof CoreFunc\Internal;
    }

    /**
     * Resolve lowered instance method proxy, walking extends chain (#101, Zend zend_inheritance).
     */
    private function resolveJitInstanceMethodProxyName(string $classLc, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338, #26911).
        if ('simplemxml_element' === $current) {
            $current = 'simplexmlelement';
            $classLc = 'simplexmlelement';
        }
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$methodLc;
            if ($this->context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            if ($this->context->type->object->hasDeclaredClass($current)) {
                $classId = $this->context->type->object->lookup($current);
                $traitLc = $this->context->type->object->traitMethodSource($classId, $methodLc);
                if (null !== $traitLc) {
                    $traitProxy = $traitLc.'::'.$methodLc;
                    if ($this->context->functionIsRegistered($traitProxy)) {
                        return $traitProxy;
                    }
                }
            }
            $parent = $this->context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return strtolower(ltrim($classLc, '\\')).'::'.$methodLc;
    }

    /**
     * ext/dom internal classes inherit DOMNode methods without LLVM parentClassLc (#18951).
     */
    private function resolveDomSubclassInstanceMethodProxy(string $classLc, string $methodLc, string $proxyName): string
    {
        if ($this->context->functionIsRegistered($proxyName)) {
            return $proxyName;
        }
        $classLc = strtolower(ltrim($classLc, '\\'));
        if ('dom\\htmldocument' === $classLc || str_ends_with($classLc, '\\htmldocument')) {
            $livingProxy = 'dom\\htmldocument::'.strtolower($methodLc);
            JIT\DomInstanceMethodJit::ensureProxy($this->context, $livingProxy);
            if ($this->context->functionIsRegistered($livingProxy)) {
                return $livingProxy;
            }
        }
        if (!str_starts_with($classLc, 'dom') || 'domnode' === $classLc) {
            return $proxyName;
        }
        JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::'.$methodLc);
        $nodeProxy = 'domnode::'.strtolower($methodLc);
        if ($this->context->functionIsRegistered($nodeProxy)) {
            return $nodeProxy;
        }
        if ('createdocumentfragment' === strtolower($methodLc)) {
            JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::createdocumentfragment');
            $docProxy = 'domdocument::createdocumentfragment';
            if ($this->context->functionIsRegistered($docProxy)) {
                return $docProxy;
            }
        }
        if ('appendchild' === strtolower($methodLc)) {
            JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocumentfragment::appendchild');
            $fragmentProxy = 'domdocumentfragment::appendchild';
            if ($this->context->functionIsRegistered($fragmentProxy)) {
                return $fragmentProxy;
            }
        }

        return $proxyName;
    }

    private function resolveThisVariable(Block $block): ?Variable
    {
        if (null === $block->func || null === $block->func->cfg) {
            if (null !== $this->context->implicitThisArgument) {
                return $this->context->implicitThisArgument;
            }

            return null;
        }
        foreach ($block->func->cfg->hoistedOperands as $hoisted) {
            if ('this' !== JIT\OperandName::resolve($hoisted)) {
                continue;
            }
            if ($this->context->hasVariableOpInScopes($hoisted)) {
                return $this->context->getVariableFromOpInScopes($hoisted);
            }
            // Hoisted $this not materialized yet — fall through to LLVM param 0.
            break;
        }

        if (null !== $this->context->implicitThisArgument) {
            return $this->context->implicitThisArgument;
        }

        return null;
    }
}
