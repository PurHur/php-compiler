<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Func as CoreFunc;
use PHPLLVM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Instance method-call init / dispatch for JIT/AOT.
 *
 * Extracted from {@see \PHPCompiler\JIT} (#36403).
 */
trait InitJitMethodCall
{
    private function initJitMethodCall(
        Block $block,
        Operand $receiverOp,
        string $methodName,
        bool $objectCallInvoke = false
    ): void
    {
        if ('__invoke' === strtolower($methodName)) {
            $receiver = $this->context->getVariableFromOp($receiverOp);
            $closureCall = \PHPCompiler\JIT\ClosureHelper::resolveCall($this->context, $receiver);
            if (null !== $closureCall) {
                $this->context->scope->toCall = $closureCall;
                $this->context->scope->args = [];

                return;
            }
        }
        if ('loadxml' === strtolower($methodName)) {
            $this->context->extensionLowering->setPendingLoadXmlReceiverVarName(
                \PHPCompiler\JIT\OperandName::resolve($receiverOp)
            );
        }
        if ('propertyisinitialized' === strtolower($methodName)) {
            $receiverVar = $this->context->getVariableFromOp($receiverOp);
            if (Type::TYPE_OBJECT === $receiverOp->type?->type) {
                \PHPCompiler\JIT\LazyObjectHelper::emitEnsureInitialized(
                    $this->context,
                    $this->context->helper->loadValue($receiverVar)
                );
            }
            $this->context->scope->toCall = new \PHPCompiler\VM\PropertyIsInitializedHandler();
            $this->context->scope->args = [$receiverVar];

            return;
        }
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        $methodLcEarlyDispatch = strtolower($methodName);
        $this->applyDateTimeLocalInstantToReceiver($receiverOp, $receiverVar);
        $this->applyDateIntervalStateToReceiver($receiverOp, $receiverVar);
        $this->applyDateTimeZoneLocalToReceiver($receiverOp, $receiverVar);
        $recvHintLc = strtolower(ltrim(
            (string) ($receiverVar->classUserType
                ?? $this->typedPropertyClassConstraintUserType($receiverVar)
                ?? $receiverOp->type?->userType
                ?? ''),
            '\\'
        ));
        // unserialize() runtime O: result — prefer RuntimeIndirect before object::format throw (#34602).
        if (
            $this->receiverIsFromUnserializeObject($receiverOp)
            && (
                '' === $recvHintLc
                || 'object' === $recvHintLc
                || 'stdclass' === $recvHintLc
            )
        ) {
            $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLcEarlyDispatch);
            if ([] !== $runtimeCandidates) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLcEarlyDispatch,
                    $runtimeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        // Typed DateInterval::format() — ExternalMethod previously wrote empty (#32699).
        if (
            'format' === $methodLcEarlyDispatch
            && (
                'dateinterval' === $recvHintLc
                || \is_array($receiverVar->compileTimeDateInterval)
            )
            && $this->context->functionIsRegistered('dateinterval::format')
        ) {
            $this->context->scope->toCall = $this->context->resolveFunctionProxy('dateinterval::format');
            $this->context->scope->args = [$receiverVar];

            return;
        }
        // Typed DateTime(Immutable)::getOffset() — do not steal DateTimeZone proxy (#30761).
        if (
            'getoffset' === $methodLcEarlyDispatch
            && \in_array($recvHintLc, ['datetime', 'datetimeimmutable'], true)
            && $this->context->functionIsRegistered('datetime::getoffset')
        ) {
            $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                'datetimeimmutable' === $recvHintLc
                    ? 'datetimeimmutable::getoffset'
                    : 'datetime::getoffset'
            );
            $this->context->scope->args = [$receiverVar];

            return;
        }
        // DateTimeZone::{getOffset,getTransitions,getName,getLocation} — CFG often types `$z` as plain
        // `object`, so the Call proxy never binds and ExternalMethod returns 0/null (#29732, #33727).
        // DateTime::getOffset() shares the method name; EXEC rewrites when argc==1 (#30761).
        if (
            \in_array($methodLcEarlyDispatch, ['getoffset', 'gettransitions', 'getname', 'getlocation'], true)
            && $this->context->functionIsRegistered('datetimezone::'.$methodLcEarlyDispatch)
        ) {
            $recvName = \PHPCompiler\JIT\OperandName::resolve($receiverOp);
            $knownZone = null !== $recvName
                && '' !== $recvName
                && isset($this->context->dateTimeZoneLocalNames[$this->context->resolveRefAliasName($recvName)]);
            if ($knownZone) {
                $zoneId = $this->context->dateTimeZoneLocalNames[$this->context->resolveRefAliasName($recvName)];
                $receiverVar->compileTimeTimezoneName = $zoneId;
                $receiverVar->classUserType = 'DateTimeZone';
                if (
                    null === $receiverVar->compileTimeString
                    || 'DateTimeZone' === $receiverVar->compileTimeString
                ) {
                    $receiverVar->compileTimeString = $zoneId;
                }
            }
            if ($knownZone || 'getoffset' === $methodLcEarlyDispatch || 'gettransitions' === $methodLcEarlyDispatch) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                    'datetimezone::'.$methodLcEarlyDispatch
                );
                $this->context->scope->args = [$receiverVar];
                if ('getoffset' === $methodLcEarlyDispatch) {
                    // May be DateTime::getOffset() (0 args) — rewrite at EXEC if argc==1 (#30761).
                    $this->context->scope->pendingDateTimeZoneGetOffset = true;
                }

                return;
            }
        }
        if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($receiverVar)) {
            $methodLc = strtolower($methodName);
            $proxyName = 'generator::'.$methodLc;
            if ($this->context->functionIsRegistered($proxyName)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if (null === $receiverOp->type) {
            // Bootstrap/self-host can hit methodcall init before operand typing stabilizes.
            // Prefer a safe short-circuit for stubbed self-host JIT paths over hard-crashing.
            if ($this->shouldUseSelfHostJitStubs()) {
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];

                return;
            }
        } elseif (Type::TYPE_OBJECT !== $receiverOp->type->type) {
            // Some bootstrap paths produce a receiver operand whose inferred PHPCfg type
            // is not yet marked as object (but is still an object at runtime).
            if ($this->shouldUseSelfHostJitStubs()) {
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];

                return;
            }
            // Known non-object: Error with zend_zval_value_name (#30054, zend_execute.c).
            // Nullsafe ?-> call arms keep a null-typed slot for a runtime object (#26364) —
            // skip early Error when this block is a synthetic nullsafe branch.
            $scalarLabel = Variable::propertyFetchNonObjectTypeLabel(
                Variable::getTypeFromType($receiverOp->type)
            );
            if (
                null !== $scalarLabel
                && !('null' === $scalarLabel && $block->syntheticCfgBranch)
            ) {
                if ('bool' === $scalarLabel) {
                    if ($receiverOp instanceof Operand\Literal && \is_bool($receiverOp->value)) {
                        $scalarLabel = $receiverOp->value ? 'true' : 'false';
                    } elseif ($this->context->hasVariableOp($receiverOp)) {
                        $recvLabel = \PHPCompiler\JIT\JitOperandTypeLabel::givenLabel(
                            $this->context,
                            $this->context->getVariableFromOp($receiverOp)
                        );
                        if ('true' === $recvLabel || 'false' === $recvLabel) {
                            $scalarLabel = $recvLabel;
                        }
                    }
                }
                $message = sprintf(
                    'Call to a member function %s() on %s',
                    $methodName,
                    $scalarLabel
                );
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                } else {
                    \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                    \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                    $this->context->builder->clearInsertionPosition();
                }
                $this->context->scope->toCall = null;
                $this->context->scope->args = [];

                return;
            }
            $methodLcEarly = strtolower($methodName);
            // #27044 / #19208: stored `$root = $doc->documentElement` often loses TYPE_OBJECT.
            // RuntimeIndirect(appendChild) only has Document/Node candidates — Element class_id
            // misses and aborts. Prefer DomNodeAppendChild (live slots + returns the child);
            // do not use ParentNode::append alone — it returns null and breaks
            // `$a = $el->appendChild(...)` (#27480).
            if (
                'appendchild' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::appendchild');
                if ($this->context->functionIsRegistered('domnode::appendchild')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::appendchild');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // insertBefore on documentElement temps — peer appendChild (#27044 / #35425).
            // RuntimeIndirect skips propagateDomAppendChildCompileTimeTag so cloneNode on
            // the return falls through to documentElement after loadXML SSOT refresh.
            if (
                'insertbefore' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::insertbefore');
                if ($this->context->functionIsRegistered('domnode::insertbefore')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::insertbefore');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // replaceChild return is oldChild — RuntimeIndirect skips propagateDomAppendChildCompileTimeTag
            // so getAttribute/cloneNode on createElement trees lose attrs/inner (#35386 re-open).
            if (
                'replacechild' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::replacechild');
                if ($this->context->functionIsRegistered('domnode::replacechild')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::replacechild');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // documentElement temps (:object) — Element getElementsByTagName SIGABRT via RuntimeIndirect (#32454).
            if (
                'getelementsbytagname' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getelementsbytagname');
                if ($this->context->functionIsRegistered('domelement::getelementsbytagname')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagname');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // documentElement temps (:object) — Element getElementsByTagNameNS SIGABRT via RuntimeIndirect (#32511).
            if (
                'getelementsbytagnamens' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getelementsbytagnamens');
                if ($this->context->functionIsRegistered('domelement::getelementsbytagnamens')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagnamens');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // documentElement temps lose TYPE_OBJECT — C14N via RuntimeIndirect echoes "Object" (#32961).
            if (
                'c14n' === $methodLcEarly
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::c14n');
                if ($this->context->functionIsRegistered('domnode::c14n')) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::c14n');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // try{} widens $doc past TYPE_OBJECT — RuntimeIndirect(saveXML) also emits
            // SimpleXMLElement::asXML (FALIAS) which throws at compile time (#34567 / re-#31396).
            if (
                ('savexml' === $methodLcEarly || 'savehtml' === $methodLcEarly)
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                $receiverVar = $this->context->getVariableFromOp($receiverOp);
                $hint = strtolower(ltrim(
                    (string) ($receiverVar->classUserType ?? $receiverVar->compileTimeString ?? ''),
                    '\\'
                ));
                $isSxe = 'simplexmlelement' === $hint || str_contains($hint, 'simplexml');
                if (!$isSxe) {
                    $proxy = 'savehtml' === $methodLcEarly
                        ? 'domdocument::savehtml'
                        : 'domdocument::savexml';
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $proxy);
                    if ($this->context->functionIsRegistered($proxy)) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxy);
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
            }
            // firstChild/documentElement temps lose TYPE_OBJECT — CharacterData mutators
            // via RuntimeIndirect drop surplus args silently (#31091).
            if (
                \in_array($methodLcEarly, ['substringdata', 'appenddata', 'replacedata', 'deletedata', 'insertdata'], true)
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                $proxy = 'domtext::'.$methodLcEarly;
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $proxy);
                if ($this->context->functionIsRegistered($proxy)) {
                    $receiverVar = $this->context->getVariableFromOp($receiverOp);
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxy);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // Closure use() / :object temps — RuntimeIndirect drops surplus argc (#31251 / #30814).
            $receiverVar = $this->context->getVariableFromOp($receiverOp);
            if ($this->context->extensionLowering->tryRouteDomExcessArgcNonObjectReceiver(
                $this->context,
                $methodLcEarly,
                $receiverVar,
                $this->context->scope
            )) {
                return;
            }
            // ?-> fetch blocks compile against a null-typed receiver slot; at runtime the
            // branch is only taken when the receiver is a real object (zend_compile.c).
            $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLcEarly);
            if ([] !== $runtimeCandidates) {
                $receiverVar = $this->context->getVariableFromOp($receiverOp);
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLcEarly,
                    $runtimeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }

        $externalReceiverClass = $this->resolveInstanceMethodReceiverClass($receiverOp);
        $userType = $receiverOp->type?->userType;
        // NestedJIT helper compiles can leak scope->className into user-script lowering
        // (CaseCompareJitHelper, ErrorSilenceJitHelper, …) — ignore those outside NestedJIT (#22680).
        $scopeClassName = $this->context->scope->className;
        if (
            '' !== $scopeClassName
            && !\PHPCompiler\JIT\NestedJitCompileScope::isActive()
            && $this->isNestedJitHelperScopeClassName($scopeClassName)
        ) {
            $scopeClassName = '';
        }
        $className = (is_string($userType) && '' !== ltrim($userType, '\\'))
            ? $userType
            : (null !== $externalReceiverClass
                ? $externalReceiverClass
                : (null !== ($constraintClass = $this->typedPropertyClassConstraintUserType($receiverVar))
                    ? $constraintClass
                    : ('' !== $scopeClassName ? $scopeClassName : 'object')));
        $declaringClassLc = strtolower(ltrim($className, '\\'));
        // php-types InternalArgInfo typo: simplexml_load_* → simplemxml_element (#25338, #26863, #26911).
        // userType wins over resolveInstanceMethodReceiverClass(), so remap here too.
        if ('simplemxml_element' === $declaringClassLc) {
            $className = 'SimpleXMLElement';
            $declaringClassLc = 'simplexmlelement';
        }
        if (
            '' !== $declaringClassLc
            && !\PHPCompiler\JIT\NestedJitCompileScope::isActive()
            && $this->isNestedJitHelperScopeClassName($declaringClassLc)
        ) {
            $className = 'object';
            $declaringClassLc = 'object';
        }
        $methodLc = strtolower($methodName);

        // Generator methods register a resume creator under class::method, not an ordinary
        // callable proxy — mirror FUNCCALL_INIT generatorResumeCallee → emitCreateFromCall
        // (#35147 / Zend zend_generators.c; Aggregate getIterator already used creatorResumeName).
        $genResume = null;
        $genWalk = $declaringClassLc;
        $genSeen = [];
        while ('' !== $genWalk && 'object' !== $genWalk && !isset($genSeen[$genWalk])) {
            $genSeen[$genWalk] = true;
            $genResume = \PHPCompiler\JIT\GeneratorHelper::creatorResumeName(
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
        if (null === $genResume) {
            $recvHint = (string) ($receiverVar->classUserType ?? '');
            $recvHintLc = strtolower(ltrim($recvHint, '\\'));
            if ('' !== $recvHintLc && 'object' !== $recvHintLc && !isset($genSeen[$recvHintLc])) {
                $genResume = \PHPCompiler\JIT\GeneratorHelper::creatorResumeName(
                    $this->context,
                    $recvHintLc.'::'.$methodLc
                );
            }
        }
        if (null !== $genResume) {
            $this->context->scope->generatorResumeCallee = $genResume;
            // Non-null toCall required: EXEC_RETURN null-short-circuits before generatorResumeCallee.
            $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                $declaringClassLc.'::'.$methodLc
            );
            $this->context->scope->args = [$receiverVar];

            return;
        }

        // Prefer typed WeakReference::get when create() tagged the receiver (#27118).
        if (
            'get' === $methodLc
            && 'WeakReference' === ($receiverVar->classUserType ?? '')
            && $this->context->functionIsRegistered('weakreference::get')
        ) {
            $this->context->scope->toCall = $this->context->resolveFunctionProxy('weakreference::get');
            $this->context->scope->args = [$receiverVar];

            return;
        }

        if ('object' === $declaringClassLc) {
            // Untyped `$obj->getName()` used to always bind ReflectionAttribute (#27303): that
            // steals ReflectionConstant/ReflectionClass/… and yields empty/segfault under AOT.
            $receiverHint = (string) ($receiverVar->classUserType ?? $receiverVar->compileTimeString ?? '');
            $receiverHintLc = strtolower(ltrim($receiverHint, '\\'));
            if ('getname' === $methodLc) {
                if (
                    'reflectionconstant' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionconstant::getname')
                ) {
                    $className = 'ReflectionConstant';
                    $declaringClassLc = 'reflectionconstant';
                } elseif (
                    'reflectionclass' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionclass::getname')
                ) {
                    $className = 'ReflectionClass';
                    $declaringClassLc = 'reflectionclass';
                } elseif (
                    'reflectionobject' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionobject::getname')
                ) {
                    $className = 'ReflectionObject';
                    $declaringClassLc = 'reflectionobject';
                } elseif (
                    'reflectionfunction' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionfunction::getname')
                ) {
                    $className = 'ReflectionFunction';
                    $declaringClassLc = 'reflectionfunction';
                } elseif (
                    'reflectionenum' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionenum::getname')
                ) {
                    $className = 'ReflectionEnum';
                    $declaringClassLc = 'reflectionenum';
                } elseif (
                    'datetimezone' === $receiverHintLc
                    && $this->context->functionIsRegistered('datetimezone::getname')
                ) {
                    // Prefer DateTimeZone over ReflectionAttribute fallback (#27307).
                    $className = 'DateTimeZone';
                    $declaringClassLc = 'datetimezone';
                } elseif ($this->context->functionIsRegistered('reflectionattribute::getname')) {
                    $className = 'ReflectionAttribute';
                    $declaringClassLc = 'reflectionattribute';
                }
            } elseif (
                \in_array($methodLc, ['getoffset', 'gettransitions', 'getname', 'getlocation'], true)
                && (
                    'datetimezone' === $receiverHintLc
                    || (null !== ($receiverVar->compileTimeTimezoneName ?? null)
                        && '' !== $receiverVar->compileTimeTimezoneName)
                )
                && $this->context->functionIsRegistered('datetimezone::'.$methodLc)
            ) {
                // Stored `$z = new DateTimeZone(...)` often loses CFG object userType; route
                // zone methods via compileTimeTimezoneName / classUserType (#29732 / #29733 / #29734 / #33727).
                $className = 'DateTimeZone';
                $declaringClassLc = 'datetimezone';
            } elseif (
                'getmangledname' === $methodLc
                && $this->context->functionIsRegistered('reflectionproperty::getmangledname')
            ) {
                // foreach (ReflectionClass::getProperties()) loses ReflectionProperty userType (#27592).
                $className = 'ReflectionProperty';
                $declaringClassLc = 'reflectionproperty';
            } elseif ('getvalue' === $methodLc) {
                if (
                    'reflectionconstant' === $receiverHintLc
                    && $this->context->functionIsRegistered('reflectionconstant::getvalue')
                ) {
                    $className = 'ReflectionConstant';
                    $declaringClassLc = 'reflectionconstant';
                }
            } elseif ('newinstance' === $methodLc && $this->context->functionIsRegistered('reflectionattribute::newinstance')) {
                $className = 'ReflectionAttribute';
                $declaringClassLc = 'reflectionattribute';
            } elseif ('getattributes' === $methodLc && $this->context->functionIsRegistered('reflectionmethod::getattributes')) {
                $className = 'ReflectionMethod';
                $declaringClassLc = 'reflectionmethod';
            } elseif ('getnamedarguments' === $methodLc && $this->context->functionIsRegistered('reflectionfunction::getnamedarguments')) {
                $className = 'ReflectionFunction';
                $declaringClassLc = 'reflectionfunction';
            } elseif ('loadhtml' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::loadhtml');
                if ($this->context->functionIsRegistered('domdocument::loadhtml')) {
                    $className = 'DOMDocument';
                    $declaringClassLc = 'domdocument';
                }
            } elseif ('loadxml' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::loadxml');
                if ($this->context->functionIsRegistered('domdocument::loadxml')) {
                    $className = 'DOMDocument';
                    $declaringClassLc = 'domdocument';
                }
            } elseif ('appendchild' === $methodLc) {
                // Prefer DOMDocument::appendChild when typed as document; untyped object
                // receivers still fall back to DOMNode / ParentNode::append (#19208, #24973).
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::appendchild');
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::appendchild');
                if ($this->context->functionIsRegistered('domnode::appendchild')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('append' === $methodLc && $this->context->functionIsRegistered('domnode::append')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('prepend' === $methodLc && $this->context->functionIsRegistered('domnode::prepend')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('after' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::after');
                if ($this->context->functionIsRegistered('domnode::after')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('before' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::before');
                if ($this->context->functionIsRegistered('domnode::before')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('replacewith' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::replacewith');
                if ($this->context->functionIsRegistered('domnode::replacewith')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif (
                'remove' === $methodLc
                && !str_contains($declaringClassLc, 'tokenlist')
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::remove');
                if ($this->context->functionIsRegistered('domnode::remove')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('replacechildren' === $methodLc && $this->context->functionIsRegistered('domnode::replacechildren')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('removechild' === $methodLc && $this->context->functionIsRegistered('domnode::removechild')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('replacechild' === $methodLc && $this->context->functionIsRegistered('domnode::replacechild')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('insertbefore' === $methodLc && $this->context->functionIsRegistered('domnode::insertbefore')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('comparedocumentposition' === $methodLc && $this->context->functionIsRegistered('domnode::comparedocumentposition')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('issupported' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::issupported');
                if ($this->context->functionIsRegistered('domnode::issupported')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('lookupprefix' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupprefix');
                if ($this->context->functionIsRegistered('domnode::lookupprefix')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('lookupnamespaceuri' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupnamespaceuri');
                if ($this->context->functionIsRegistered('domnode::lookupnamespaceuri')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('isdefaultnamespace' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::isdefaultnamespace');
                if ($this->context->functionIsRegistered('domnode::isdefaultnamespace')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('getlineno' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::getlineno');
                if ($this->context->functionIsRegistered('domnode::getlineno')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('hasfeature' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domimplementation::hasfeature');
                if ($this->context->functionIsRegistered('domimplementation::hasfeature')) {
                    $className = 'DOMImplementation';
                    $declaringClassLc = 'domimplementation';
                }
            } elseif ('contains' === $methodLc && $this->context->functionIsRegistered('domnode::contains')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('getrootnode' === $methodLc && $this->context->functionIsRegistered('domnode::getrootnode')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('isequalnode' === $methodLc && $this->context->functionIsRegistered('domnode::isequalnode')) {
                $className = 'DOMNode';
                $declaringClassLc = 'domnode';
            } elseif ('issamenode' === $methodLc) {
                // documentElement temps lose DOMElement userType → :object (#32957 / peer #21687).
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::issamenode');
                if ($this->context->functionIsRegistered('domnode::issamenode')) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('c14n' === $methodLc || 'c14nfile' === $methodLc) {
                // Untyped documentElement path (#32961 / #32962 / #32964).
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::'.$methodLc);
                if ($this->context->functionIsRegistered('domnode::'.$methodLc)) {
                    $className = 'DOMNode';
                    $declaringClassLc = 'domnode';
                }
            } elseif ('toggleattribute' === $methodLc && $this->context->functionIsRegistered('domelement::toggleattribute')) {
                $className = 'DOMElement';
                $declaringClassLc = 'domelement';
            } elseif (
                'insertadjacentelement' === $methodLc
                || 'insertadjacenttext' === $methodLc
                || 'insertadjacenthtml' === $methodLc
            ) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::'.$methodLc);
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\element::'.$methodLc);
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\htmlelement::'.$methodLc);
                if ($this->context->functionIsRegistered('domelement::'.$methodLc)) {
                    $className = 'DOMElement';
                    $declaringClassLc = 'domelement';
                }
            } elseif ('setidattribute' === $methodLc
                || 'setidattributens' === $methodLc
                || 'setidattributenode' === $methodLc
            ) {
                // getElementsByTagName/item temps → :object → DOMNode; ExternalMethod null (#33957).
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::'.$methodLc);
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::'.$methodLc);
                $className = 'DOMElement';
                $declaringClassLc = 'domelement';
            } elseif ('isid' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domattr::isid');
                if ($this->context->functionIsRegistered('domattr::isid')) {
                    $className = 'DOMAttr';
                    $declaringClassLc = 'domattr';
                }
            } elseif (\in_array($methodLc, ['queryselector', 'queryselectorall', 'savehtml', 'getelementbyid'], true)) {
                // Prefer concrete Dom\*Document receiver; fall back to HTMLDocument (#19580, #29453).
                $livingDocProxies = [
                    'dom\\xmldocument::'.$methodLc,
                    'dom\\htmldocument::'.$methodLc,
                    'dom\\document::'.$methodLc,
                ];
                if ('savehtml' === $methodLc || 'getelementbyid' === $methodLc) {
                    $livingDocProxies = ['dom\\htmldocument::'.$methodLc];
                }
                foreach ($livingDocProxies as $livingProxy) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $livingProxy);
                }
                $preferLc = strtolower($className);
                if (str_contains($preferLc, 'xmldocument')
                    && $this->context->functionIsRegistered('dom\\xmldocument::'.$methodLc)
                ) {
                    $className = 'Dom\\XMLDocument';
                    $declaringClassLc = 'dom\\xmldocument';
                } elseif (str_contains($preferLc, 'document')
                    && !str_contains($preferLc, 'html')
                    && $this->context->functionIsRegistered('dom\\document::'.$methodLc)
                ) {
                    $className = 'Dom\\Document';
                    $declaringClassLc = 'dom\\document';
                } elseif ($this->context->functionIsRegistered('dom\\htmldocument::'.$methodLc)) {
                    $className = 'Dom\\HTMLDocument';
                    $declaringClassLc = 'dom\\htmldocument';
                }
            }
        }

        $proxyName = $this->resolveJitInstanceMethodProxyName($declaringClassLc, $methodLc);
        $proxyName = $this->resolveDomSubclassInstanceMethodProxy($declaringClassLc, $methodLc, $proxyName);
        if (
            \in_array($methodLc, ['insertadjacentelement', 'insertadjacenttext', 'insertadjacenthtml'], true)
        ) {
            if (
                ('insertadjacentelement' === $methodLc
                    && !\PHPCompiler\CompilerVersion::supportsDomElementInsertAdjacentElement())
                || ('insertadjacenttext' === $methodLc
                    && !\PHPCompiler\CompilerVersion::supportsDomElementInsertAdjacentText())
                || ('insertadjacenthtml' === $methodLc
                    && !\PHPCompiler\CompilerVersion::supportsDomElementInsertAdjacentHtml())
            ) {
                throw new \LogicException("Call to undefined method {$className}::{$methodLc}()");
            }
        }
        // Thin AOT: Element/Node appendChild → DomNodeAppendChild (live NodeList + return
        // child) (#19208, #27044, #27480). Never remap to ParentNode::append alone — that
        // returns null and makes `$a = $el->appendChild(...)` NULL under AOT (#27480).
        // DOMDocument must keep DomDocumentAppendChild (documentElement + parentNode); the
        // append remap corrupts child tagName after an intervening echo (#24973).
        if (
            'appendchild' === $methodLc
            && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
        ) {
            $docAppendClasses = [
                'domdocument' => true,
                'dom\\document' => true,
                'dom\\xmldocument' => true,
                'dom\\htmldocument' => true,
            ];
            if (!isset($docAppendClasses[$declaringClassLc])) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::appendchild');
                if ($this->context->functionIsRegistered('domnode::appendchild')) {
                    $proxyName = 'domnode::appendchild';
                }
            } else {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::appendchild');
                if ($this->context->functionIsRegistered('domdocument::appendchild')) {
                    $proxyName = 'domdocument::appendchild';
                }
            }
        }
        if (
            \in_array($methodLc, ['substringdata', 'appenddata', 'replacedata', 'deletedata', 'insertdata'], true)
            && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
        ) {
            $cdProxy = 'domtext::'.$methodLc;
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $cdProxy);
            if ($this->context->functionIsRegistered($cdProxy)) {
                $proxyName = $cdProxy;
            }
        }
        // Register SimpleXML user-script AOT proxies before functionIsRegistered (#19306).
        if (str_starts_with(strtolower($proxyName), 'simplexmlelement::')
            && ('1' === Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')
                || 'true' === strtolower((string) Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')))
        ) {
            \PHPCompiler\JIT\SimpleXmlInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Register XMLWriter user-script AOT proxies before functionIsRegistered (#19551).
        if (\PHPCompiler\JIT\XmlWriterInstanceMethodJit::isXmlWriterInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XmlWriterInstanceMethodJit::isUserScriptAot()
        ) {
            \PHPCompiler\JIT\XmlWriterInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Register XMLReader user-script AOT proxies before functionIsRegistered (#27299).
        if (\PHPCompiler\JIT\XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XmlReaderInstanceMethodJit::isUserScriptAot()
        ) {
            \PHPCompiler\JIT\XmlReaderInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Register XSLTProcessor user-script AOT proxies before functionIsRegistered (#20392).
        if (\PHPCompiler\JIT\XsltInstanceMethodJit::isXsltInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XsltInstanceMethodJit::isUserScriptAot()
        ) {
            \PHPCompiler\JIT\XsltInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        // Register Randomizer user-script AOT proxies before functionIsRegistered (#19574).
        if (\PHPCompiler\JIT\RandomizerInstanceMethodJit::isRandomizerInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\RandomizerInstanceMethodJit::isUserScriptAot()
        ) {
            \PHPCompiler\JIT\RandomizerInstanceMethodJit::ensureProxy($this->context, $proxyName);
        }
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        $receiverVar = $this->resolveUserScriptDomDocumentReceiver(
            $block,
            $receiverOp,
            $declaringClassLc,
            $methodLc,
            $receiverVar
        );
        $dispatchReceiver = $this->jitInstanceMethodReceiverVariable($receiverVar);
        $splObjectStorageMethod = str_starts_with(strtolower($proxyName), 'splobjectstorage::');
        $simpleXmlUserScript = str_starts_with(strtolower($proxyName), 'simplexmlelement::')
            && ('1' === Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')
                || 'true' === strtolower((string) Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')));
        $xmlWriterUserScript = \PHPCompiler\JIT\XmlWriterInstanceMethodJit::isXmlWriterInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XmlWriterInstanceMethodJit::isUserScriptAot();
        $xmlReaderUserScript = \PHPCompiler\JIT\XmlReaderInstanceMethodJit::isXmlReaderInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XmlReaderInstanceMethodJit::isUserScriptAot();
        $xsltUserScript = \PHPCompiler\JIT\XsltInstanceMethodJit::isXsltInstanceMethodProxy($proxyName)
            && \PHPCompiler\JIT\XsltInstanceMethodJit::isUserScriptAot();
        // NestedJIT \PHPCompiler\VM\Variable params are `__value__*` (#16565) — never run object lazy-init on them (#20785).
        // When the operand lacks a Variable userType, className falls back to the NestedJIT helper
        // class (e.g. DomCreateElementJitHelper) — still route known Variable methods (#22678 AOT).
        $nestedVmVariableReceiver = \PHPCompiler\JIT\NestedJitCompileScope::isActive()
            && Variable::TYPE_VALUE === $receiverVar->type
            && (
                'phpcompiler\\vm\\variable' === $declaringClassLc
                || 'variable' === $declaringClassLc
                || str_ends_with($declaringClassLc, '\\vm\\variable')
                || \PHPCompiler\JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod($methodLc)
            );
        if (
            !$nestedVmVariableReceiver
            && Type::TYPE_OBJECT === $receiverOp->type?->type
            && !$splObjectStorageMethod
            && !$simpleXmlUserScript
            && !$xmlWriterUserScript
            && !$xmlReaderUserScript
            && !$xsltUserScript
        ) {
            \PHPCompiler\JIT\LazyObjectHelper::emitEnsureInitialized(
                $this->context,
                $this->context->helper->loadValue($dispatchReceiver)
            );
        }
        if ($nestedVmVariableReceiver && \PHPCompiler\JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod($methodLc)) {
            if (!\PHPCompiler\JIT\NestedVmVariableMethodLlvm::ensureMethod($this->context, $methodLc)) {
                throw new \LogicException("Nested VM Variable method {$methodLc} missing (#20785)");
            }
            $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                'phpcompiler\\vm\\variable::'.$methodLc
            );
            $this->context->scope->args = [$receiverVar];

            return;
        }
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ('getmessage' === $methodLc && $this->context->functionIsRegistered('exception::getmessage')) {
                $msgProxy = 'exception::getmessage';
                if (
                    '' !== $declaringClassLc
                    && (
                        \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                            $declaringClassLc,
                            \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                        )
                        || \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $declaringClassLc
                    )
                    && $this->context->functionIsRegistered('error::getmessage')
                ) {
                    $msgProxy = 'error::getmessage';
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($msgProxy);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // LogicException/Error subclasses inherit getCode; only Exception/Error proxies exist (#23974).
            if ('getcode' === $methodLc && $this->context->functionIsRegistered('exception::getcode')) {
                $codeProxy = 'exception::getcode';
                if (
                    '' !== $declaringClassLc
                    && (
                        \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                            $declaringClassLc,
                            \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                        )
                        || \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $declaringClassLc
                    )
                    && $this->context->functionIsRegistered('error::getcode')
                ) {
                    $codeProxy = 'error::getcode';
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($codeProxy);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // Exception/Error getFile/getLine/getPrevious inherit like getMessage (#30895).
            if (
                \in_array($methodLc, ['getfile', 'getline', 'getprevious'], true)
                && $this->context->functionIsRegistered('exception::'.$methodLc)
            ) {
                $propProxy = 'exception::'.$methodLc;
                if (
                    '' !== $declaringClassLc
                    && (
                        \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                            $declaringClassLc,
                            \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                        )
                        || \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $declaringClassLc
                    )
                    && $this->context->functionIsRegistered('error::'.$methodLc)
                ) {
                    $propProxy = 'error::'.$methodLc;
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($propProxy);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            if (
                '__construct' === $methodLc
                && $this->context->functionIsRegistered('exception::__construct')
            ) {
                // Typed `object` receivers (e.g. lazy ghost initializer `$obj->__construct()`)
                // must use runtime class-id dispatch — do not bind Exception::__construct (#27302).
                $ctorProxy = null;
                if (
                    '' !== $declaringClassLc
                    && 'object' !== $declaringClassLc
                    && $this->context->functionIsRegistered($declaringClassLc.'::__construct')
                ) {
                    $ctorProxy = $declaringClassLc.'::__construct';
                } elseif (
                    '' !== $declaringClassLc
                    && 'object' !== $declaringClassLc
                    && (
                        \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                            $declaringClassLc,
                            \PHPCompiler\ext\standard\ThrowableManifest::LC_EXCEPTION
                        )
                        || \PHPCompiler\ext\standard\ThrowableManifest::LC_EXCEPTION === $declaringClassLc
                    )
                ) {
                    $ctorProxy = 'exception::__construct';
                } elseif (
                    '' !== $declaringClassLc
                    && 'object' !== $declaringClassLc
                    && (
                        \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                            $declaringClassLc,
                            \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                        )
                        || \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $declaringClassLc
                    )
                    && $this->context->functionIsRegistered('error::__construct')
                ) {
                    $ctorProxy = 'error::__construct';
                }
                if (null !== $ctorProxy) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($ctorProxy);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if ('object' === $declaringClassLc || '' === $declaringClassLc) {
                // childNodes/attributes temps often lower as :object; ensure DOM list item() /
                // NamedNodeMap getNamedItem* proxies before building class-id candidates
                // (#21171 AOT, #18493, #24332).
                if ('item' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnodelist::item');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnamednodemap::item');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\namednodemap::item');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtokenlist::item');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\tokenlist::item');
                }
                // while ($r->read()) widens XMLReader receivers to :object (#27299).
                // Directory::read from dir() shares the method name — bind by classUserType
                // or prefer Directory when XMLReader is not tagged (#30757).
                if (
                    'read' === $methodLc
                    && \PHPCompiler\JIT\XmlReaderInstanceMethodJit::isUserScriptAot()
                ) {
                    $recvHintLc = strtolower(ltrim(
                        (string) ($receiverVar->classUserType ?? ''),
                        '\\'
                    ));
                    if ('xmlreader' === $recvHintLc) {
                        \PHPCompiler\JIT\XmlReaderInstanceMethodJit::ensureProxy($this->context, 'xmlreader::read');
                        if ($this->context->functionIsRegistered('xmlreader::read')) {
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy('xmlreader::read');
                            $this->context->scope->args = [$receiverVar];

                            return;
                        }
                    }
                    if (
                        $this->context->functionIsRegistered('directory::read')
                        && 'xmlreader' !== $recvHintLc
                    ) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('directory::read');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('getnameditem' === $methodLc || 'getnameditemns' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnamednodemap::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\namednodemap::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\dtdnamednodemap::'.$methodLc);
                }
                // getAttributeNode / documentElement temps lower as :object — bind living rename (#27108).
                if ('rename' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\attr::rename');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\element::rename');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\htmlelement::rename');
                }
                if (
                    'hasattribute' === $methodLc
                    || 'hasattributens' === $methodLc
                    || 'getattribute' === $methodLc
                    || 'getattributens' === $methodLc
                    || 'getattributenode' === $methodLc
                    || 'getattributenodens' === $methodLc
                ) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\element::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\htmlelement::'.$methodLc);
                    if ('hasattribute' !== $methodLc && 'hasattributens' !== $methodLc) {
                        \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::'.$methodLc);
                    }
                }
                if ('createattribute' === $methodLc || 'createattributens' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\xmldocument::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\document::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::'.$methodLc);
                }
                if (
                    'insertadjacentelement' === $methodLc
                    || 'insertadjacenttext' === $methodLc
                    || 'insertadjacenthtml' === $methodLc
                ) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\element::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\htmlelement::'.$methodLc);
                }
                // setIdAttribute* on child-property temps (:object) (#29257, #29284).
                if (
                    'setidattribute' === $methodLc
                    || 'setidattributens' === $methodLc
                    || 'setidattributenode' === $methodLc
                ) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::'.$methodLc);
                }
                // cloneNode on firstChild temps (:object) — php-src xmlDocCopyNode.
                if ('clonenode' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::clonenode');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::clonenode');
                }
                // C14N on documentElement temps (:object / unknown) — peer cloneNode (#32961).
                if ('c14n' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::c14n');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::c14n');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::c14n');
                }
                // hasChildNodes on documentElement/firstChild temps (:object) — php-src node.c.
                if ('haschildnodes' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::haschildnodes');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::haschildnodes');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::haschildnodes');
                }
                if ('hasattributes' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::hasattributes');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::hasattributes');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::hasattributes');
                }
                if ('getnodepath' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::getnodepath');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getnodepath');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::getnodepath');
                }
                if ('issupported' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::issupported');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::issupported');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::issupported');
                }
                if ('lookupprefix' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupprefix');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::lookupprefix');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::lookupprefix');
                }
                if ('lookupnamespaceuri' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupnamespaceuri');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::lookupnamespaceuri');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::lookupnamespaceuri');
                }
                if ('isdefaultnamespace' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::isdefaultnamespace');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::isdefaultnamespace');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::isdefaultnamespace');
                }
                if ('getlineno' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::getlineno');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getlineno');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::getlineno');
                }
                if ('hasfeature' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domimplementation::hasfeature');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\implementation::hasfeature');
                }
                // getElementsByTagName on documentElement temps (:object) — php-src element.c (#32454).
                if ('getelementsbytagname' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getelementsbytagname');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::getelementsbytagname');
                    if ($this->context->functionIsRegistered('domelement::getelementsbytagname')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagname');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                // getElementsByTagNameNS on documentElement temps — php-src element.c xmlFirstElementChild (#32511).
                if ('getelementsbytagnamens' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domelement::getelementsbytagnamens');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::getelementsbytagnamens');
                    if ($this->context->functionIsRegistered('domelement::getelementsbytagnamens')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagnamens');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('substringdata' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::substringdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcomment::substringdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::substringdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcharacterdata::substringdata');
                    if ($this->context->functionIsRegistered('domtext::substringdata')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::substringdata');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('appenddata' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::appenddata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcomment::appenddata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::appenddata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcharacterdata::appenddata');
                    if ($this->context->functionIsRegistered('domtext::appenddata')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::appenddata');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('replacedata' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::replacedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcomment::replacedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::replacedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcharacterdata::replacedata');
                    if ($this->context->functionIsRegistered('domtext::replacedata')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::replacedata');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('deletedata' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::deletedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcomment::deletedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::deletedata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcharacterdata::deletedata');
                    if ($this->context->functionIsRegistered('domtext::deletedata')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::deletedata');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('insertdata' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::insertdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcomment::insertdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::insertdata');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcharacterdata::insertdata');
                    if ($this->context->functionIsRegistered('domtext::insertdata')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::insertdata');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('splittext' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::splittext');
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domcdatasection::splittext');
                    // Assigned firstChild temps lose DOMText userType and would otherwise
                    // RuntimeIndirect no-op under thin AOT (#34475 / re-#34314).
                    if ($this->context->functionIsRegistered('domtext::splittext')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                            'domtext::splittext'
                        );
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                // Living createElement* — peer createAttribute object-receiver path (#28958).
                if ('createelement' === $methodLc || 'createelementns' === $methodLc) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\htmldocument::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\xmldocument::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'dom\\document::'.$methodLc);
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domdocument::'.$methodLc);
                }
                // insertBefore on :object documentElement temps — peer appendChild force-direct
                // (#28509 / #35425). RuntimeIndirect drops compileTimeDom* on the return so
                // cloneNode after loadXML move uses stale index / documentElement.
                if ('insertbefore' === $methodLc && $this->context->functionIsRegistered('domnode::insertbefore')) {
                    \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::insertbefore');
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::insertbefore');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                // `__construct` on typed object: use safe new-construct candidates only —
                // LimitIteratorConstruct et al. throw while emitting every switch arm (#27302 / #27156).
                $runtimeCandidates = ('__construct' === $methodLc)
                    ? $this->buildRuntimeNewConstructCandidatesByClassId()
                    : $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
                if ([] !== $runtimeCandidates) {
                    $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                        $receiverVar,
                        $methodLc,
                        $runtimeCandidates
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                // User-script AOT may omit DOMNodeList from allClassNamesById — still bind item().
                // attributes->item must not steal the NodeList lowering (#32546).
                if ('item' === $methodLc) {
                    $itemHintLc = strtolower(str_replace('/', '\\', ltrim(
                        (string) ($receiverVar->classUserType ?? $declaringClassLc),
                        '\\'
                    )));
                    if (
                        str_contains($itemHintLc, 'namednodemap')
                        && $this->context->functionIsRegistered('domnamednodemap::item')
                    ) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                            'domnamednodemap::item'
                        );
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                    if ($this->context->functionIsRegistered('domnodelist::item')) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnodelist::item');
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
                if ('clonenode' === $methodLc && $this->context->functionIsRegistered('domnode::clonenode')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::clonenode');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('haschildnodes' === $methodLc && $this->context->functionIsRegistered('domnode::haschildnodes')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::haschildnodes');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('hasattributes' === $methodLc && $this->context->functionIsRegistered('domnode::hasattributes')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::hasattributes');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('getnodepath' === $methodLc && $this->context->functionIsRegistered('domnode::getnodepath')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::getnodepath');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('issupported' === $methodLc && $this->context->functionIsRegistered('domnode::issupported')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::issupported');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('lookupprefix' === $methodLc && $this->context->functionIsRegistered('domnode::lookupprefix')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::lookupprefix');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('lookupnamespaceuri' === $methodLc && $this->context->functionIsRegistered('domnode::lookupnamespaceuri')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::lookupnamespaceuri');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('isdefaultnamespace' === $methodLc && $this->context->functionIsRegistered('domnode::isdefaultnamespace')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::isdefaultnamespace');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('getlineno' === $methodLc && $this->context->functionIsRegistered('domnode::getlineno')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::getlineno');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('hasfeature' === $methodLc && $this->context->functionIsRegistered('domimplementation::hasfeature')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domimplementation::hasfeature');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('getelementsbytagname' === $methodLc && $this->context->functionIsRegistered('domelement::getelementsbytagname')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagname');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('getelementsbytagnamens' === $methodLc && $this->context->functionIsRegistered('domelement::getelementsbytagnamens')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domelement::getelementsbytagnamens');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('substringdata' === $methodLc && $this->context->functionIsRegistered('domtext::substringdata')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::substringdata');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('replacedata' === $methodLc && $this->context->functionIsRegistered('domtext::replacedata')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::replacedata');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('deletedata' === $methodLc && $this->context->functionIsRegistered('domtext::deletedata')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::deletedata');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('insertdata' === $methodLc && $this->context->functionIsRegistered('domtext::insertdata')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::insertdata');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if ('splittext' === $methodLc && $this->context->functionIsRegistered('domtext::splittext')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::splittext');
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                // Same omission for DOMNamedNodeMap::getNamedItem* (#24332).
                if (
                    ('getnameditem' === $methodLc || 'getnameditemns' === $methodLc)
                    && $this->context->functionIsRegistered('domnamednodemap::'.$methodLc)
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'domnamednodemap::'.$methodLc
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                // Living Dom\* may be omitted from allClassNamesById — bind rename bridge (#27108).
                // DomInstanceMethod only ships methodLc; VmDomJitDispatch::rename selects Attr/Element.
                if (
                    'rename' === $methodLc
                    && $this->context->functionIsRegistered('dom\\attr::rename')
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'dom\\attr::rename'
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if (
                    (
                        'hasattribute' === $methodLc
                        || 'hasattributens' === $methodLc
                        || 'getattribute' === $methodLc
                        || 'getattributens' === $methodLc
                        || 'getattributenode' === $methodLc
                        || 'getattributenodens' === $methodLc
                    )
                    && $this->context->functionIsRegistered('dom\\element::'.$methodLc)
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'dom\\element::'.$methodLc
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if (
                    ('createattribute' === $methodLc || 'createattributens' === $methodLc)
                    && $this->context->functionIsRegistered('dom\\xmldocument::'.$methodLc)
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'dom\\xmldocument::'.$methodLc
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if (
                    ('createelement' === $methodLc || 'createelementns' === $methodLc)
                    && $this->context->functionIsRegistered('dom\\htmldocument::'.$methodLc)
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'dom\\htmldocument::'.$methodLc
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
                if (
                    (
                        'setidattribute' === $methodLc
                        || 'setidattributens' === $methodLc
                        || 'setidattributenode' === $methodLc
                    )
                    && $this->context->functionIsRegistered('domelement::'.$methodLc)
                ) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'domelement::'.$methodLc
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if (\PHPCompiler\JIT\MagicMethodDispatch::tryInitMagicCall(
                $this->context,
                $declaringClassLc,
                $methodName,
                $receiverVar
            )) {
                return;
            }
            // firstChild temps stamped DOMElement (#34375) resolve domelement::splittext as
            // ExternalMethod no-op; force DOMText fold (#34475 / re-#34314).
            if (
                \in_array($methodLc, ['substringdata', 'appenddata', 'replacedata', 'deletedata', 'insertdata'], true)
                && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
            ) {
                $cdProxy = 'domtext::'.$methodLc;
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $cdProxy);
                if ($this->context->functionIsRegistered($cdProxy)) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($cdProxy);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if ('splittext' === $methodLc) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::splittext');
                if ($this->context->functionIsRegistered('domtext::splittext')) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                        'domtext::splittext'
                    );
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if ($this->isBundledJitExternalClassPrefix($declaringClassLc)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            if (
                'getfile' === $methodLc
                && str_starts_with($declaringClassLc, 'phpcompiler\\')
                && ($this->shouldUseM3InventoryEmitDriver() || $this->shouldEnsureInventoryArgvParseHelperStubs())
            ) {
                // $script->main->getFile() temps lose PHPCfg userType on inventory argv spine (#11809).
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('phpcfg\\func::getfile');
                $this->context->scope->args = [$receiverVar];

                return;
            }
            if ($this->tryInitInventoryArgvRuntimeParseHelperCall($methodLc, $dispatchReceiver)) {
                return;
            }
            if ($this->tryInitNestedVmHelperMethodCall($declaringClassLc, $methodLc, $receiverVar)) {
                return;
            }
            if (\PHPCompiler\JIT\DomInstanceMethodJit::isDomInstanceMethodProxy($proxyName)) {
                \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $proxyName);
                if ($this->context->functionIsRegistered($proxyName)) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if (\PHPCompiler\JIT\RandomizerInstanceMethodJit::isRandomizerInstanceMethodProxy($proxyName)
                && \PHPCompiler\JIT\RandomizerInstanceMethodJit::isUserScriptAot()
            ) {
                \PHPCompiler\JIT\RandomizerInstanceMethodJit::ensureProxy($this->context, $proxyName);
                if ($this->context->functionIsRegistered($proxyName)) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            if (\PHPCompiler\JIT\XsltInstanceMethodJit::isXsltInstanceMethodProxy($proxyName)
                && \PHPCompiler\JIT\XsltInstanceMethodJit::isUserScriptAot()
            ) {
                \PHPCompiler\JIT\XsltInstanceMethodJit::ensureProxy($this->context, $proxyName);
                if ($this->context->functionIsRegistered($proxyName)) {
                    $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                    $this->context->scope->args = [$receiverVar];

                    return;
                }
            }
            // Spine split-TU / external-only class: fall through to ExternalMethod (null or
            // bound extern) instead of aborting — otherwise chunk builds never reach the stub
            // report (external_method_stubs=0 with rc=2, #24429).
            $fallthroughClassId = null;
            if (
                '' !== $declaringClassLc
                && 'object' !== $declaringClassLc
                && $this->context->type->object->hasDeclaredClass($declaringClassLc)
            ) {
                $fallthroughClassId = $this->context->type->object->lookup($declaringClassLc);
            }
            if (
                ($this->shouldUseSelfHostJitStubs() && $this->isSelfHostBundledClassPrefix($declaringClassLc))
                || \PHPCompiler\AOT\ExternalMethodBind::allowUnresolvedMethodFallthrough(
                    $this->context,
                    $declaringClassLc,
                    $fallthroughClassId
                )
            ) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // Runtime::compileEmitSmoke calls $assignOpResolver->optimize(); typed property can
            // collapse to object during M5 argv host-lower — bind the void Optimizer stub (#26756).
            if (
                'optimize' === $methodLc
                && (
                    $this->shouldUseM5DriverHostCompile()
                    || $this->shouldUseM3InventoryEmitDriver()
                )
            ) {
                $this->ensureM3EmitTuInventoryArgvVmOptimizerStub();
                foreach ([
                    'phpcompiler\\vm\\optimizer\\assignop::optimize',
                    'phpcompiler\\vm\\optimizer::optimize',
                ] as $optProxy) {
                    if (isset($this->context->functionProxies[$optProxy])) {
                        $this->context->scope->toCall = $this->context->functionProxies[$optProxy];
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
            }
            // Generic `:object` / empty class (nullsafe `$o?->m()` fetch arm, #34713): do not
            // abort compile — bind a catchable Error so the dead fetch arm can lower while the
            // null arm short-circuits at runtime (ZEND_NULLSAFE_METHODCALL).
            if ('object' === $declaringClassLc || '' === $declaringClassLc) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\EmitCatchableError(
                    "Call to undefined method {$className}::{$methodLc}()"
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // Trait method bodies: `$this->m()` may resolve on the composing class only
            // (Nyholm StreamTrait::__toString → Stream::isSeekable, #36382).
            if ($this->context->type->object->isTraitClass($declaringClassLc)) {
                $composing = $this->context->scope->traitComposingClassName;
                if ('' !== $composing) {
                    $compLc = strtolower(ltrim($composing, '\\'));
                    $compProxy = $compLc.'::'.$methodLc;
                    if ($this->context->functionIsRegistered($compProxy)) {
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy($compProxy);
                        $this->context->scope->args = [$receiverVar];

                        return;
                    }
                }
            }
            // Interface / abstract (or other) typed receivers: no lowered body on the
            // declared type — dispatch by runtime class_id among known subtypes
            // (zend_std_get_method). Unblocks Slim RouteCollectorInterface::getNamedRoute (#36382).
            $subtypeCandidates = $this->buildRuntimeInstanceMethodCandidatesForDeclaredType(
                $declaringClassLc,
                $methodLc
            );
            if ([] !== $subtypeCandidates) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLc,
                    $subtypeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // Interface with no implementors in this compile (optional DI container, etc.):
            // bind catchable Error so dead `$container && $container->has()` can lower (#36382).
            if ($this->context->type->object->isInterfaceClassLc($declaringClassLc)) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\EmitCatchableError(
                    "Call to undefined method {$className}::{$methodLc}()"
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
            // Scope-class fallback when a typed interface property has no implementors in
            // the TU (Slim CallableResolver::$container → callableresolver::has, #36382).
            // Zend resolves at runtime; compile-time abort blocks dead branches.
            if (
                '' !== $declaringClassLc
                && 'object' !== $declaringClassLc
                && $this->context->type->object->hasDeclaredClass($declaringClassLc)
                && !$this->context->type->object->hasMethod(
                    $this->context->type->object->lookup($declaringClassLc),
                    $methodLc
                )
            ) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\EmitCatchableError(
                    "Call to undefined method {$className}::{$methodLc}()"
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
            throw new \LogicException("Call to undefined method {$className}::{$methodLc}()");
        }
        $receiverUserType = $receiverOp->type?->userType;
        $normalizedReceiverUserType = is_string($receiverUserType) ? ltrim($receiverUserType, '\\') : null;
        $staticProxy = $this->context->resolveFunctionProxy($proxyName);
        if (
            \in_array($methodLc, ['substringdata', 'appenddata', 'replacedata', 'deletedata', 'insertdata'], true)
            && $this->context->extensionLowering->shouldUseDomDocumentMethodKernel($this->context)
        ) {
            $cdProxy = 'domtext::'.$methodLc;
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, $cdProxy);
            if ($this->context->functionIsRegistered($cdProxy)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($cdProxy);
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        // :object receivers use RuntimeIndirectInstanceMethodCall; MCJIT segfaults on
        // ReflectionAttribute::newInstance() through that path (#4598).
        if ('reflectionattribute::newinstance' === strtolower($proxyName)) {
            $this->context->scope->toCall = $staticProxy;
            $this->context->scope->args = [$receiverVar];

            return;
        }
        // Legacy: appendChild was briefly remapped to ParentNode::append (#19208). Keep the
        // early-return shape but force DomNodeAppendChild so the child is returned (#27480).
        // Also force when proxy is already domnode::appendchild: documentElement temps keep
        // TYPE_OBJECT but lose userType (:object) and would otherwise take RuntimeIndirect
        // with Document/Node candidates only — Element class_id miss aborts (#28509, re-#27044).
        if (
            'appendchild' === $methodLc
            && (
                'domnode::append' === $proxyName
                || 'domnode::appendchild' === strtolower($proxyName)
            )
        ) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::appendchild');
            if ($this->context->functionIsRegistered('domnode::appendchild')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::appendchild');
            } else {
                $this->context->scope->toCall = $staticProxy;
            }
            $this->context->scope->args = [$receiverVar];

            return;
        }
        // insertBefore peer of appendChild force-direct (#35425 / #28509).
        if (
            'insertbefore' === $methodLc
            && 'domnode::insertbefore' === strtolower($proxyName)
        ) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::insertbefore');
            if ($this->context->functionIsRegistered('domnode::insertbefore')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::insertbefore');
            } else {
                $this->context->scope->toCall = $staticProxy;
            }
            $this->context->scope->args = [$receiverVar];

            return;
        }
        if ('clonenode' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::clonenode');
            if ($this->context->functionIsRegistered('domnode::clonenode')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::clonenode');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        // :object / empty userType would take RuntimeIndirect and echo "Object" (#32961).
        if ('c14n' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::c14n');
            if ($this->context->functionIsRegistered('domnode::c14n')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::c14n');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('haschildnodes' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::haschildnodes');
            if ($this->context->functionIsRegistered('domnode::haschildnodes')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::haschildnodes');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('hasattributes' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::hasattributes');
            if ($this->context->functionIsRegistered('domnode::hasattributes')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::hasattributes');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('getnodepath' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::getnodepath');
            if ($this->context->functionIsRegistered('domnode::getnodepath')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::getnodepath');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('issupported' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::issupported');
            if ($this->context->functionIsRegistered('domnode::issupported')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::issupported');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('lookupprefix' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupprefix');
            if ($this->context->functionIsRegistered('domnode::lookupprefix')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::lookupprefix');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('lookupnamespaceuri' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::lookupnamespaceuri');
            if ($this->context->functionIsRegistered('domnode::lookupnamespaceuri')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::lookupnamespaceuri');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('isdefaultnamespace' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::isdefaultnamespace');
            if ($this->context->functionIsRegistered('domnode::isdefaultnamespace')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::isdefaultnamespace');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('getlineno' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domnode::getlineno');
            if ($this->context->functionIsRegistered('domnode::getlineno')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domnode::getlineno');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('hasfeature' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domimplementation::hasfeature');
            if ($this->context->functionIsRegistered('domimplementation::hasfeature')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domimplementation::hasfeature');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ($this->context->extensionLowering->tryRouteDomExcessArgcNonObjectReceiver(
            $this->context,
            $methodLc,
            $receiverVar,
            $this->context->scope
        )) {
            return;
        }
        if ('substringdata' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::substringdata');
            if ($this->context->functionIsRegistered('domtext::substringdata')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::substringdata');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('replacedata' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::replacedata');
            if ($this->context->functionIsRegistered('domtext::replacedata')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::replacedata');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('deletedata' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::deletedata');
            if ($this->context->functionIsRegistered('domtext::deletedata')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::deletedata');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('insertdata' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::insertdata');
            if ($this->context->functionIsRegistered('domtext::insertdata')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::insertdata');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('appenddata' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::appenddata');
            if ($this->context->functionIsRegistered('domtext::appenddata')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::appenddata');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        if ('splittext' === $methodLc) {
            \PHPCompiler\JIT\DomInstanceMethodJit::ensureProxy($this->context, 'domtext::splittext');
            if ($this->context->functionIsRegistered('domtext::splittext')) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('domtext::splittext');
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        $needsRuntimeDispatch = null === $normalizedReceiverUserType
            || '' === $normalizedReceiverUserType
            || 'object' === strtolower($normalizedReceiverUserType)
            || $staticProxy instanceof \PHPCompiler\JIT\Call\ExternalMethod;
        if ($needsRuntimeDispatch) {
            // NestedJIT may lose HashTable userType on temps; prefer HT/Variable bridges
            // before RuntimeIndirect — otherwise static helpers named `add` (e.g.
            // OutputRewriteVarsJitHelper::add) pollute candidates and fail string lowering
            // when compiling bin/compile.php (#23468).
            if ($this->tryInitNestedVmHelperMethodCall($declaringClassLc, $methodLc, $receiverVar)) {
                return;
            }
            $runtimeCandidates = $this->buildRuntimeInstanceMethodCandidatesByClassId($methodLc);
            if ([] !== $runtimeCandidates) {
                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                    $receiverVar,
                    $methodLc,
                    $runtimeCandidates
                );
                $this->context->scope->args = [$receiverVar];

                return;
            }
        }
        $resolvedClassLc = strstr($proxyName, '::', true) ?: $declaringClassLc;
        $declaringClassId = $this->context->type->object->lookup($resolvedClassLc);
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = strtolower(ltrim($this->context->scope->className, '\\'));
        }
        // `$obj(...)` object-call ignores __invoke visibility; `$obj->__invoke()` does not (#26438).
        if (!($objectCallInvoke && '__invoke' === $methodLc)) {
            MethodVisibility::assertCallable(
                $visFlags,
                $callerClassLc,
                $resolvedClassLc,
                $className,
                $methodName,
                false,
                fn (string $a, string $b): bool => $this->jitIsClassSameOrSubclassOf($a, $b)
            );
        }
        if (
            null !== $receiverUserType
            && 'object' !== strtolower(ltrim((string) $receiverUserType, '\\'))
        ) {
            $this->context->scope->lateStaticCallClassId = $this->context->type->object->lookup($receiverUserType);
        }
        $this->context->scope->toCall = $staticProxy;
        // Instance call of a static method: omit receiver from args (zend_execute.c; #22288).
        // XMLReader::open/XML keep EX(This) (#22630, re-#19330).
        if (($visFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            $keepThis = ('xmlreader' === strtolower((string) $resolvedClassLc))
                && ('open' === $methodLc || 'xml' === $methodLc);
            if (!$keepThis) {
                $this->context->scope->args = [];

                return;
            }
        }
        $this->context->scope->args = [$splObjectStorageMethod ? $receiverVar : $dispatchReceiver];
    }
}
