<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Block;

/**
 * Static method-call resolve / init / late-static binding for JIT/AOT (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: self-host static stubs through
 * {@code emitJitLateStaticCallSiteBinding} so the hub shrinks toward the 20k
 * size-budget target (Concern trait; same namespace as parent).
 */
trait InitJitStaticCall
{
    private function isSelfHostSuperglobalsClassLc(string $classLc): bool
    {
        $classLc = strtolower(ltrim($classLc, '\\'));

        return 'superglobals' === $classLc || str_ends_with($classLc, '\\superglobals');
    }

    private function tryResolveSelfHostSuperglobalsStaticCall(string $className, string $methodName): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        if (!$this->isSelfHostSuperglobalsClassLc($declaringClassLc)) {
            return false;
        }
        $methodLc = strtolower($methodName);
        $fullLower = ('superglobals' === $declaringClassLc ? 'phpcompiler\\web\\superglobals' : $declaringClassLc)
            .'::'.$methodLc;
        if ('populatefromenvironment' === $methodLc) {
            JIT\Builtin\SuperglobalRefreshRuntime::ensureLinked($this->context);
            if (!$this->context->functionIsRegistered('__superglobals__refresh')) {
                JIT\SuperglobalInit::declareRefresh($this->context);
            }
            $this->context->scope->toCall = $this->context->resolveFunctionProxy('__superglobals__refresh');

            return true;
        }
        if (!$this->isSuperglobalsRealLoweringMethod($fullLower)
            && !str_ends_with($fullLower, '::issuperglobalname')) {
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($fullLower);

            return true;
        }

        return false;
    }

    /**
     * Lower Progress::{noteFunction,notePhase,noteEntry} to __phpc_progress_note when the PHP
     * method is not yet queued (self-host spine compile order — #8560, #6748).
     */
    private function tryResolveProgressStaticCall(string $className, string $methodName): bool
    {
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        if ('phpcompiler\\jit\\progress' !== $declaringClassLc && 'jit\\progress' !== $declaringClassLc) {
            return false;
        }
        $methodLc = strtolower($methodName);
        if (!in_array($methodLc, ['notefunction', 'notephase', 'noteentry'], true)) {
            return false;
        }
        JIT\Builtin\ProgressNoteRuntime::ensureLinked($this->context);
        $proxy = 'phpcompiler\\jit\\progress::'.strtolower($methodName);
        if (!$this->context->functionIsRegistered($proxy)) {
            return false;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxy);

        return true;
    }

    /**
     * IncludePathResolver::resolve may compile after the caller static call is lowered (#816, bootstrap-aot-link).
     */
    private function tryResolveIncludePathResolverStaticCall(string $className, string $methodName): bool
    {
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        if (!$this->isIncludePathResolverRealLoweringMethod($declaringClassLc.'::'.$methodLc)) {
            return false;
        }
        JIT\Builtin\StringIncludePathResolver::ensureLinked($this->context);
        $proxy = $this->resolveJitStaticMethodProxyName($declaringClassLc, $methodLc);
        if (!$this->context->functionIsRegistered($proxy)) {
            $this->context->functionProxies[$proxy] = new JIT\Call\IncludePathResolverResolve();
        }
        if (!$this->context->functionIsRegistered($proxy)) {
            return false;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxy);

        return true;
    }

    private function resolveJitStaticMethodProxyName(string $classLc, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        $visited = [];
        $current = strtolower(ltrim($classLc, '\\'));
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
            $parentLc = $this->context->type->object->parentClassLc($current);
            if (null === $parentLc) {
                break;
            }
            $current = $parentLc;
        }

        return strtolower(ltrim($classLc, '\\')).'::'.$methodLc;
    }

    /**
     * Zend zend_std_get_static_method: reject instance methods on Class::name() (#5339).
     */
    private function assertJitStaticMethodCallable(
        string $calledClassLc,
        string $methodLc,
        string $calledClassName,
        string $methodDisplay
    ): void {
        $message = $this->nonStaticClassMethodCallableMessage(
            $calledClassLc,
            $methodLc,
            $calledClassName,
            $methodDisplay
        );
        if (null !== $message) {
            throw new \LogicException($message);
        }
    }

    /**
     * Error text when a Class::method callable names a non-static method (zend_execute_API.c).
     */
    private function nonStaticClassMethodCallableMessage(
        string $calledClassLc,
        string $methodLc,
        string $calledClassName,
        string $methodDisplay
    ): ?string {
        if ($this->context->type->object->isEnumClassLc(strtolower(ltrim($calledClassLc, '\\')))
            && 'cases' === $methodLc) {
            return null;
        }
        $visited = [];
        $current = strtolower(ltrim($calledClassLc, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            if ($this->context->type->object->hasDeclaredClass($current)) {
                $classId = $this->context->type->object->lookup($current);
                if ($this->context->type->object->hasMethod($classId, $methodLc)) {
                    $vis = $this->context->type->object->methodVisibility($classId, $methodLc);
                    if (0 === ($vis & \PHPCfg\Func::FLAG_STATIC)) {
                        $declaringName = $this->context->type->object->classNameForId($classId);

                        return 'Non-static method '.$declaringName.'::'.$methodDisplay.'() cannot be called statically';
                    }

                    return null;
                }
            }
            $parent = $this->context->type->object->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    private function initJitStaticCall(
        Block $block,
        int $classOpIdx,
        int $nameOpIdx,
        bool $parentScope = false,
        bool $fromDynamicCallable = false
    ): void {
        $classOp = $block->getOperand($classOpIdx);
        $nameOp = $block->getOperand($nameOpIdx);
        // Scope can lose Literal operands while slot constants remain (sockets/vm spine
        // chunks under SPINE_CHUNK — getOperand null for both class+name, #24429).
        if (!$classOp instanceof Operand\Literal && isset($block->constants[$classOpIdx])) {
            $const = $block->constants[$classOpIdx];
            // Promote string/object only — bool/int/null/array must Error (#30059).
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $const->type) {
                $classOp = new Operand\Literal($const->toString());
            } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $const->type) {
                $classOp = new Operand\Literal($const->toObject()->class->name);
            } else {
                JIT\InstanceOfHelper::emitInvalidClassOperandError($this->context);
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];
                $this->context->scope->argOperands = [];

                return;
            }
        }
        if (!$nameOp instanceof Operand\Literal && isset($block->constants[$nameOpIdx])) {
            $nameOp = new Operand\Literal($block->constants[$nameOpIdx]->toString());
        }
        if (!$nameOp instanceof Operand\Literal) {
            // `Class::$m()` — fold compile-time string like METHODCALL_INIT (#34937).
            $nameVar = $this->context->getVariableFromOp($nameOp);
            $this->foldCompileTimeStringFromSlot($block, $nameOpIdx, $nameVar);
            if (null !== $nameVar->compileTimeString && '' !== $nameVar->compileTimeString) {
                $nameOp = new Operand\Literal($nameVar->compileTimeString);
            }
        }
        if (!$nameOp instanceof Operand\Literal) {
            if (\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('object::__unknownStatic');
                $this->context->scope->args = [];

                return;
            }
            // Runtime method name: NamedClass::$m() / static::$m() / $class::$m() (#34937).
            $nameVar = $this->context->getVariableFromOp($nameOp);
            if ($classOp instanceof Operand\Literal) {
                $selfScope = 'self' === strtolower((string) $classOp->value);
                $staticScope = 'static' === strtolower((string) $classOp->value);
                $className = $this->resolveJitStaticScopeClass($block, $classOp);
                $declaringClassLc = strtolower($className);
                if (
                    $staticScope
                    && !$parentScope
                    && JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)
                ) {
                    $candidates = $this->buildMethodNameToIndirectStaticCandidates(
                        $block,
                        false
                    );
                } else {
                    $candidates = $this->buildRuntimeStaticMethodCandidatesByMethodName(
                        $declaringClassLc
                    );
                    if (!$parentScope && !$selfScope && $this->context->type->object->hasDeclaredClass($declaringClassLc)) {
                        $this->context->scope->lateStaticCallClassId = $this->context->type->object->lookup(
                            $declaringClassLc
                        );
                    }
                }
                if ([] === $candidates) {
                    throw new \LogicException(
                        'Call to undefined method '.$className.'::{runtime}()'
                    );
                }
                $this->context->scope->toCall = new JIT\Call\RuntimeVariableStaticMethodCall(
                    $nameVar,
                    $candidates
                );
                $this->context->scope->args = [];

                return;
            }
            // `$class::$m()` — both operands runtime (#34937).
            $classVar = $this->context->getVariableFromOp($classOp);
            if (
                JIT\Variable::TYPE_OBJECT !== $classVar->type
                && JIT\Variable::TYPE_STRING !== $classVar->type
                && JIT\Variable::TYPE_VALUE !== $classVar->type
                && \PHPCompiler\VM\InstanceOfJitHelper::jitRhsTypeIsInvalidClass($classVar->type)
            ) {
                JIT\InstanceOfHelper::emitInvalidClassOperandError($this->context);
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];
                $this->context->scope->argOperands = [];

                return;
            }
            $candidates = $this->buildMethodNameToIndirectStaticCandidates(
                $block,
                false,
                $classVar,
                $classOp
            );
            if ([] === $candidates) {
                throw new \LogicException('Call to undefined method {runtime}::{runtime}()');
            }
            $this->context->scope->toCall = new JIT\Call\RuntimeVariableStaticMethodCall(
                $nameVar,
                $candidates
            );
            $this->context->scope->args = [];

            return;
        }
        if (!$classOp instanceof Operand\Literal) {
            // `$class::method()` / non-literal class operand — fall through under
            // SPINE_CHUNK / external-only like unresolved instance methods (#24496).
            if (\PHPCompiler\AOT\ExternalMethodBind::allowUnresolvedMethodFallthrough(
                $this->context,
                'object',
                null
            )) {
                $proxyName = 'object::'.strtolower((string) $nameOp->value);
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [];

                return;
            }
            // Runtime variable classname: resolve via emitResolveClassId guards (#30059).
            $classVar = $this->context->getVariableFromOp($classOp);
            if (
                JIT\Variable::TYPE_OBJECT !== $classVar->type
                && JIT\Variable::TYPE_STRING !== $classVar->type
                && JIT\Variable::TYPE_VALUE !== $classVar->type
                && \PHPCompiler\VM\InstanceOfJitHelper::jitRhsTypeIsInvalidClass($classVar->type)
            ) {
                JIT\InstanceOfHelper::emitInvalidClassOperandError($this->context);
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];
                $this->context->scope->argOperands = [];

                return;
            }
            $methodLc = strtolower((string) $nameOp->value);
            $candidates = $this->buildRuntimeStaticMethodCandidatesByClassId($methodLc, false);
            if ([] === $candidates) {
                throw new \LogicException('Call to undefined method '.$nameOp->value.'()');
            }
            // `$obj::method()` / `$class::method()` — ZEND_INIT_STATIC_METHOD_CALL (#31967).
            $this->context->scope->toCall = new JIT\Call\RuntimeIndirectStaticMethodCall(
                $methodLc,
                $candidates,
                $block,
                false,
                $classVar,
                $classOp
            );
            $this->context->scope->args = [];

            return;
        }
        $selfScope = 'self' === strtolower((string) $classOp->value);
        $staticScope = 'static' === strtolower((string) $classOp->value);
        $className = $this->resolveJitStaticScopeClass($block, $classOp);
        $declaringClassLc = strtolower($className);
        $methodLc = strtolower($nameOp->value);
        // NestedJIT helper compile: fold isActive() → true so AOT-linked helpers keep
        // the __compiler_* branch (VmPasswordPure / secureRandomBytes) (#26773).
        if (
            JIT\NestedJitCompileScope::isActive()
            && 'isactive' === $methodLc
            && (
                'phpcompiler\\jit\\nestedjitcompilescope' === $declaringClassLc
                || str_ends_with($declaringClassLc, '\\nestedjitcompilescope')
                || 'nestedjitcompilescope' === $declaringClassLc
            )
        ) {
            $this->context->scope->toCall = new JIT\Call\NestedJitCompileScopeIsActiveTrue();
            $this->context->scope->args = [];

            return;
        }
        if ($this->context->compilingFiberResume && 'fiber' === $declaringClassLc && 'suspend' === $methodLc) {
            // Resume continuation: FUNCCALL_EXEC_RETURN loads resume_argument (#26801).
            $this->context->scope->toCall = null;
            $this->context->scope->args = [];
            $this->context->scope->argOperands = [];
            $this->context->scope->fiberSuspendResultPending = true;

            return;
        }
        $declaringClassId = $this->context->type->object->lookup($className);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = $this->context->scope->className;
        }
        $callerInstanceMethod = $this->instanceMethodUsesThis($block);
        $directParentLc = null !== $callerClassLc
            ? $this->context->type->object->parentClassLc($callerClassLc)
            : null;
        // Zend INIT_STATIC_METHOD_CALL: allow non-static when caller $this is instanceof
        // the called class (self::/static::/parent:: + compatible named Class::) (#28050, #1858).
        // Dynamic $fn() / ['Class','m']() must Error — no instance-scope bind (#32299 / #31968).
        $instanceScopeAllowsNonStatic = !$fromDynamicCallable
            && $callerInstanceMethod
            && null !== $callerClassLc
            && $this->jitIsClassSameOrSubclassOf($callerClassLc, $declaringClassLc);
        if (!$instanceScopeAllowsNonStatic) {
            if ($fromDynamicCallable) {
                $nonStaticMsg = $this->nonStaticClassMethodCallableMessage(
                    $declaringClassLc,
                    $methodLc,
                    $className,
                    $nameOp->value
                );
                if (null !== $nonStaticMsg) {
                    $this->context->scope->toCall = new JIT\Call\EmitCatchableError($nonStaticMsg);
                    $this->context->scope->args = [];

                    return;
                }
            } else {
                $this->assertJitStaticMethodCallable($declaringClassLc, $methodLc, $className, $nameOp->value);
            }
        }
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $bindCallerThisForNonStatic = $instanceScopeAllowsNonStatic
            && (0 === ($visFlags & \PHPCfg\Func::FLAG_STATIC));
        $parentScopeAllows = false;
        if (null !== $callerClassLc) {
            if (null !== $directParentLc && $directParentLc === $declaringClassLc) {
                $parentScopeAllows = MethodVisibility::parentScopeAllows(
                    $visFlags,
                    $callerClassLc,
                    $declaringClassLc,
                    $declaringClassLc,
                    fn (string $classLc, string $ancestorLc): bool => $this->jitIsClassSameOrSubclassOf($classLc, $ancestorLc)
                );
            }
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $declaringClassLc,
            $className,
            $nameOp->value,
            $parentScopeAllows,
            null,
            null,
            '__construct' === $methodLc
        );
        $proxyName = $this->resolveJitStaticMethodProxyName($declaringClassLc, $methodLc);
        // Register XMLWriter static factory proxies before functionIsRegistered (#35890 / #35895).
        if (JIT\XmlWriterInstanceMethodJit::isXmlWriterInstanceMethodProxy($proxyName)
            && JIT\XmlWriterInstanceMethodJit::isUserScriptAot()
        ) {
            JIT\XmlWriterInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Register XMLReader static factory proxies before functionIsRegistered (#35900 / #27299).
        if (JIT\XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($proxyName)
            && JIT\XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            JIT\XmlReaderInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Static generator methods register a resume creator under class::method, not an
        // ordinary callable proxy — mirror METHODCALL_INIT (#35147) for Class::g() (#35153 /
        // Zend zend_generators.c; re-#4938 false fatal removed so this path is reachable).
        $genResume = null;
        $genWalk = $declaringClassLc;
        $genSeen = [];
        while ('' !== $genWalk && 'object' !== $genWalk && !isset($genSeen[$genWalk])) {
            $genSeen[$genWalk] = true;
            $genResume = JIT\GeneratorHelper::creatorResumeName(
                $this->context,
                $genWalk.'::'.$methodLc
            );
            if (null !== $genResume) {
                break;
            }
            $parentLc = $this->context->type->object->parentClassLc($genWalk);
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            $genWalk = $parentLc;
        }
        if (null !== $genResume) {
            $this->context->scope->generatorResumeCallee = $genResume;
            // Non-null toCall required: EXEC_RETURN null-short-circuits before generatorResumeCallee.
            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
            $this->context->scope->args = [];

            return;
        }
        // Per-user-module VmClosureInvoke::invokeVariable needs NestedClosureInvoke (#24156).
        if (
            'invokevariable' === $methodLc
            && str_ends_with($declaringClassLc, 'vmclosureinvoke')
            && isset($this->context->functionProxies[JIT\NestedClosureInvokeLlvm::PROXY])
            && $this->context->functionProxies[JIT\NestedClosureInvokeLlvm::PROXY] instanceof JIT\Call\NestedClosureInvoke
        ) {
            $this->context->scope->toCall = $this->context->functionProxies[JIT\NestedClosureInvokeLlvm::PROXY];
            $this->context->scope->args = [];

            return;
        }
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ($this->context->type->object->isEnumClassLc($declaringClassLc)
                && \in_array($methodLc, ['cases', 'from', 'tryfrom'], true)) {
                $this->context->type->object->finishEnumClass($declaringClassId);
            }
        }
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ($this->context->type->object->isExternalOnlyClass($declaringClassId)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [];

                return;
            }
            // Zend FFI is not lowered in self-host AOT bundles (#2633).
            if ($this->shouldUseSelfHostJitStubs() && 'ffi' === $declaringClassLc) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                    $className.'::'.$nameOp->value
                );
                $this->context->scope->args = [];

                return;
            }
            // bin/compile.php process capture: lower LinkerProcessPolyfill::run() to the AOT builtin
            // phpc_run_command() (proc_open is not lowered; #2779). This applies to any native AOT/JIT
            // compilation path (not only self-host stubs).
            if ('phpcompiler\\aot\\linkerprocesspolyfill' === $declaringClassLc && 'run' === $methodLc) {
                if (!$this->context->functionIsRegistered('phpc_run_command')) {
                    throw new \LogicException(
                        'phpc_run_command internal missing for LinkerProcessPolyfill::run lowering (#2779)'
                    );
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('phpc_run_command');
                $this->context->scope->args = [];

                return;
            }
            if ($this->tryResolveSelfHostSuperglobalsStaticCall($className, $nameOp->value)) {
                $this->context->scope->args = [];

                return;
            }
            if ($this->tryResolveProgressStaticCall($className, $nameOp->value)) {
                $this->context->scope->args = [];

                return;
            }
            if ($this->tryResolveIncludePathResolverStaticCall($className, $nameOp->value)) {
                $this->context->scope->args = [];

                return;
            }
            // Missing __construct on Class::__construct() / parent::__construct() is
            // "Cannot call constructor" — never __callStatic (zend_object_handlers.c, #25909).
            // Spine split-TU: parent may live outside the chunk (e.g. VmClassMethod for
            // ext/ds NestedJIT method classes) — fall through to ExternalMethod so the
            // probe can reach the stub report instead of aborting (#24429).
            if ('__construct' === $methodLc) {
                if (\PHPCompiler\AOT\ExternalMethodBind::allowUnresolvedMethodFallthrough(
                    $this->context,
                    $declaringClassLc,
                    $declaringClassId
                )) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [];

                    return;
                }
                throw new \LogicException('Cannot call constructor');
            }
            if (JIT\MagicMethodDispatch::tryInitMagicCallStatic(
                $this->context,
                $declaringClassLc,
                $nameOp->value
            )) {
                return;
            }
            // Zend zend_execute_API.c — no "static" token; keep source casing (#27921).
            throw new \LogicException("Call to undefined method {$className}::{$nameOp->value}()");
        }
        // AOT/standalone: `static::method()` must dispatch from get_called_scope(), not the
        // declaring class baked in at compile time (#24169). Compile-time resolve of `static`
        // to the enclosing class is exactly `self::` — wrong for subclass overrides.
        if (
            $staticScope
            && !$parentScope
            && JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)
        ) {
            $candidates = $this->buildRuntimeStaticMethodCandidatesByClassId(
                $methodLc,
                $bindCallerThisForNonStatic
            );
            if ([] === $candidates) {
                throw new \LogicException("Call to undefined method {$className}::{$nameOp->value}()");
            }
            $this->context->scope->toCall = new JIT\Call\RuntimeIndirectStaticMethodCall(
                $methodLc,
                $candidates,
                $block,
                $bindCallerThisForNonStatic
            );
            $this->context->scope->args = [];

            return;
        }
        // parent:: / self:: dispatch to resolved code but must not clobber late-static scope
        // (#12245 parent, #21983 self). Named Class:: and static:: (non-standalone) rebind LSB.
        if (!$parentScope && !$selfScope) {
            $this->context->scope->lateStaticCallClassId = $declaringClassId;
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [];
    }

    /**
     * Map every known class id to the static method proxy resolved from that class (#24169).
     *
     * @param bool $allowInstanceMethods Include non-static methods when caller has compatible
     *                                   $this (static:: from instance method, #28050).
     *
     * @return array<int, JIT\Call>
     */
    private function buildRuntimeStaticMethodCandidatesByClassId(
        string $methodLc,
        bool $allowInstanceMethods = false
    ): array {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($this->context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            $proxyName = $this->resolveJitStaticMethodProxyName($classLc, $methodLc);
            if (!$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            // Prefer static methods; skip instance-only names that share a short name.
            // When $allowInstanceMethods (static:: from instance, #28050), invert: only
            // non-static proxies (caller $this is prepended at the call site).
            if (!$this->context->type->object->hasDeclaredClass($classLc)) {
                continue;
            }
            $ownerId = $this->context->type->object->lookup($classLc);
            // Lazy dom/ext proxies register on functionIsRegistered — skip classes that do
            // not actually declare the method ($obj::method() / #31967).
            if (!$this->context->type->object->hasMethod($ownerId, $methodLc)) {
                continue;
            }
            $vis = $this->context->type->object->methodVisibility($ownerId, $methodLc);
            $ownerIsStatic = (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC));
            if ($allowInstanceMethods) {
                if ($ownerIsStatic) {
                    continue;
                }
                $resolvedLc = explode('::', $proxyName, 2)[0];
                if ($this->context->type->object->hasDeclaredClass($resolvedLc)) {
                    $resolvedId = $this->context->type->object->lookup($resolvedLc);
                    $resolvedVis = $this->context->type->object->methodVisibility($resolvedId, $methodLc);
                    if (0 !== ($resolvedVis & \PHPCfg\Func::FLAG_STATIC)) {
                        continue;
                    }
                }
            } elseif (!$ownerIsStatic) {
                // May still be inherited as static from a parent — check resolved owner.
                $resolvedLc = explode('::', $proxyName, 2)[0];
                if ($this->context->type->object->hasDeclaredClass($resolvedLc)) {
                    $resolvedId = $this->context->type->object->lookup($resolvedLc);
                    $resolvedVis = $this->context->type->object->methodVisibility($resolvedId, $methodLc);
                    if (0 === ($resolvedVis & \PHPCfg\Func::FLAG_STATIC)) {
                        continue;
                    }
                }
            }
            $candidates[$classId] = $this->context->resolveFunctionProxy($proxyName);
        }

        return $candidates;
    }

    /**
     * Map static method names on one class to their proxies for `Class::$m()` (#34937).
     *
     * @return array<string, JIT\Call> lowercase method => proxy
     */
    private function buildRuntimeStaticMethodCandidatesByMethodName(string $classLc): array
    {
        $classLc = strtolower(ltrim($classLc, '\\'));
        if (!$this->context->type->object->hasDeclaredClass($classLc)) {
            return [];
        }
        $classId = $this->context->type->object->lookup($classLc);
        $candidates = [];
        foreach ($this->context->type->object->allMethodNamesForClassId($classId, 0) as $display) {
            $methodLc = strtolower($display);
            $proxyName = $this->resolveJitStaticMethodProxyName($classLc, $methodLc);
            if (!$this->context->functionIsRegistered($proxyName)) {
                continue;
            }
            // Prefer static methods; skip instance-only names (peer buildRuntimeStaticMethodCandidatesByClassId).
            $resolvedLc = explode('::', $proxyName, 2)[0];
            if ($this->context->type->object->hasDeclaredClass($resolvedLc)) {
                $resolvedId = $this->context->type->object->lookup($resolvedLc);
                if ($this->context->type->object->hasMethod($resolvedId, $methodLc)) {
                    $resolvedVis = $this->context->type->object->methodVisibility($resolvedId, $methodLc);
                    if (0 === ($resolvedVis & \PHPCfg\Func::FLAG_STATIC)) {
                        continue;
                    }
                }
            }
            $candidates[$methodLc] = $this->context->resolveFunctionProxy($proxyName);
        }
        ksort($candidates);

        return $candidates;
    }

    /**
     * For `static::$m()` / `$class::$m()`: each method name nests a class-id dispatch (#34937).
     *
     * @return array<string, JIT\Call> lowercase method => RuntimeIndirectStaticMethodCall
     */
    private function buildMethodNameToIndirectStaticCandidates(
        Block $block,
        bool $allowInstanceMethods = false,
        ?Variable $runtimeClassVar = null,
        ?\PHPCfg\Operand $runtimeClassOp = null
    ): array {
        $methodNames = [];
        foreach ($this->context->type->object->allClassNamesById() as $classId => $_className) {
            foreach ($this->context->type->object->allMethodNamesForClassId($classId, 0) as $display) {
                $methodLc = strtolower($display);
                $proxyName = $this->resolveJitStaticMethodProxyName(
                    strtolower(ltrim((string) $_className, '\\')),
                    $methodLc
                );
                if (!$this->context->functionIsRegistered($proxyName)) {
                    continue;
                }
                $resolvedLc = explode('::', $proxyName, 2)[0];
                if ($this->context->type->object->hasDeclaredClass($resolvedLc)) {
                    $resolvedId = $this->context->type->object->lookup($resolvedLc);
                    if ($this->context->type->object->hasMethod($resolvedId, $methodLc)) {
                        $vis = $this->context->type->object->methodVisibility($resolvedId, $methodLc);
                        $isStatic = (0 !== ($vis & \PHPCfg\Func::FLAG_STATIC));
                        if (!$allowInstanceMethods && !$isStatic) {
                            continue;
                        }
                        if ($allowInstanceMethods && $isStatic) {
                            continue;
                        }
                    }
                }
                $methodNames[$methodLc] = true;
            }
        }
        $out = [];
        foreach (array_keys($methodNames) as $methodLc) {
            $byClass = $this->buildRuntimeStaticMethodCandidatesByClassId(
                $methodLc,
                $allowInstanceMethods
            );
            if ([] === $byClass) {
                continue;
            }
            $out[$methodLc] = new JIT\Call\RuntimeIndirectStaticMethodCall(
                $methodLc,
                $byClass,
                $block,
                $allowInstanceMethods,
                $runtimeClassVar,
                $runtimeClassOp
            );
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<Variable> $callArgs
     */
    private function emitJitLateStaticCallSiteBinding(array $callArgs): void
    {
        if (!JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)) {
            return;
        }
        // Store call-site class before Internal early-out — get_called_class() reads phpc_late_static_class_id (#4255).
        if (null !== $this->context->scope->lateStaticCallClassId) {
            JIT\LateStaticBindingHelper::emitStoreClassId(
                $this->context,
                $this->context->constantFromInteger($this->context->scope->lateStaticCallClassId, 'int64')
            );
            $this->context->scope->lateStaticCallClassId = null;
        }
        $toCall = $this->context->scope->toCall;
        if (
            $toCall instanceof CoreFunc\Internal
            || $toCall instanceof JIT\Call\Native
            || $toCall instanceof JIT\Call\ExternalMethod
            || $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            || $toCall instanceof JIT\Call\RuntimeIndirectStaticMethodCall
        ) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $receiver = $callArgs[0];
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            return;
        }
        // Respect the Variable kind: a KIND_VARIABLE receiver is an
        // __object__** slot — StructGEP on it reads a garbage field type and
        // LLVM 9 segfaults later in PointerType::get (#16565).
        $obj = $this->context->helper->loadValue($receiver);
        $objTy = $this->context->getStringFromType($obj->typeOf());
        if ('__object__*' !== $objTy) {
            if (!str_ends_with($objTy, '*')) {
                return; // not a pointer at all — leave the runtime class id untouched
            }
            $obj = $this->context->builder->pointerCast(
                $obj,
                $this->context->getTypeFromString('__object__*')
            );
        }
        $objMap = $this->context->structFieldMap['__object__'];
        $classId = $this->context->builder->load(
            $this->context->builder->structGep($obj, $objMap['class_id'])
        );
        JIT\LateStaticBindingHelper::emitStoreClassId($this->context, $classId);
    }
}
