<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;

/**
 * NEW opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_NEW}. Wrapped in
 * {@code switch (true)} so original case-level {@code break} semantics are
 * preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_NEW / ZEND_NEW_ARRAY),
 * Zend/zend_execute.c (object_init_ex / zend_call_function for __construct) —
 * move-only Concern extract; no new C ABI.
 */
trait CompileNew
{
    private function compileNewOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    // new-expression startLine for Exception::__construct file/line (#195, #23641).
                    if (null !== $op->arg3 && (int) $op->arg3 > 0) {
                        $this->context->callSiteLine = (int) $op->arg3;
                    }
                    $classOp = $block->getOperand($op->arg2);
                    if ($classOp instanceof Operand\Literal && 0 === strcasecmp($classOp->value, 'SplObjectStorage')) {
                        $classId = $this->context->type->object->lookup('SplObjectStorage');
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($classId)
                        );
                        $obj->classUserType = 'SplObjectStorage';
                        $resultOp = $block->getOperand($op->arg1);
                        $resultOp->type = new Type(Type::TYPE_OBJECT, [], 'SplObjectStorage');
                        $this->assignOperand($resultOp, $obj, true);
                        // assignOperand may box to TYPE_VALUE — keep class tag for serialize→unserialize (#33876).
                        if ($this->context->hasVariableOp($resultOp)) {
                            $this->context->getVariableFromOp($resultOp)->classUserType = 'SplObjectStorage';
                        }
                        $this->context->type->object->markObjectConstructed(
                            $this->context->helper->loadValue($obj)
                        );
                        $this->context->scope->preserveNewResultOnNullCall = true;
                        $this->context->scope->toCall = null;
                        $this->context->scope->args = [];
                        $this->context->scope->callArgsIncludeReceiver = false;
                    } else {
                        if (\PHPCompiler\JIT\LateStaticBindingHelper::operandNeedsRuntimeClassResolution(
                            $classOp,
                            $this->context
                        )) {
                            $classVar = $this->context->getVariableFromOp($classOp);
                            $classIdVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitResolveClassId(
                                $this->context->type->object,
                                $block,
                                $classVar,
                                $classOp
                            );
                            $objVal = $this->context->type->object->allocateForRuntimeClassId(
                                $classIdVal,
                                $this
                            );
                            $obj = new Variable(
                                $this->context,
                                Variable::TYPE_OBJECT,
                                Variable::KIND_VALUE,
                                $objVal
                            );
                            $resultOp = $block->getOperand($op->arg1);
                            $this->assignOperand($resultOp, $obj, true);
                            $resultOp->type = new Type(Type::TYPE_OBJECT);
                            $resultVar = $this->context->getVariableFromOp($resultOp);
                            // Runtime classname: dispatch __construct by class_id (#27156).
                            $ctorCandidates = $this->buildRuntimeNewConstructCandidatesByClassId();
                            if ([] !== $ctorCandidates) {
                                $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall(
                                    $resultVar,
                                    '__construct',
                                    $ctorCandidates
                                );
                                $this->context->scope->args = [$resultVar];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } else {
                                $this->context->type->object->markObjectConstructed(
                                    $this->context->helper->loadValue($obj)
                                );
                                $this->context->scope->preserveNewResultOnNullCall = true;
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                            }
                        } else {
                            $classId = $this->context->type->object->resolveClassId($classOp);
                            $resolvedName = $this->context->type->object->classNameForId($classId);
                            \PHPCompiler\JIT\ReservedBuiltinClassJitGuard::emitBeforeAllocate(
                                $this->context->type->object,
                                $this,
                                $block,
                                $classId
                            );
                            \PHPCompiler\JIT\InstantiableClassJitGuard::emitBeforeAllocate(
                                $this->context->type->object,
                                $this,
                                $block,
                                $classId
                            );
                            if (!$this->context->type->object->hasUserDeclaredClass($resolvedName)) {
                                \PHPCompiler\ext\standard\JitSplAutoload::dispatchLiteral(
                                    $this->context,
                                    $resolvedName
                                );
                            }
                            $obj = new Variable(
                                $this->context,
                                Variable::TYPE_OBJECT,
                                Variable::KIND_VALUE,
                                $this->context->type->object->allocate($classId)
                            );
                            // Compile-time class for Closure::call / bindTo scope (#26872).
                            $obj->compileTimeString = $resolvedName;
                            $obj->classUserType = $resolvedName;
                            $resultOp = $block->getOperand($op->arg1);
                            $this->assignOperand($resultOp, $obj, true);
                            $resultOp->type = new Type(Type::TYPE_OBJECT, [], $resolvedName);
                            $resultVar = $this->context->getVariableFromOp($resultOp);
                            $resultVar->classUserType = $resolvedName;
                            $resultVar->compileTimeString = $resolvedName;
                            if ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'ReflectionClass')
                            ) {
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy('reflectionclass::__construct');
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } elseif ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'ReflectionObject')
                            ) {
                                // Thin AOT: wire __construct like ReflectionClass (#34001 / #20098).
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy('reflectionobject::__construct');
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } elseif ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'ReflectionEnum')
                            ) {
                                // Thin AOT: wire __construct like ReflectionClass (#27314).
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy('reflectionenum::__construct');
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } elseif ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'SimpleXMLElement')
                                && ('1' === Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')
                                    || 'true' === strtolower((string) Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT')))
                            ) {
                                \PHPCompiler\JIT\SimpleXmlInstanceMethodJit::ensureProxy(
                                    $this->context,
                                    'simplexmlelement::__construct'
                                );
                                $this->context->scope->toCall = $this->context->functionProxies['simplexmlelement::__construct'];
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } elseif ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'XMLWriter')
                                && \PHPCompiler\JIT\XmlWriterInstanceMethodJit::isUserScriptAot()
                            ) {
                                // No XMLWriter::__construct — attach host writer at allocate (#19551).
                                $xwReceiver = $this->context->getVariableFromOp($resultOp);
                                $this->context->extensionLowering->tryInitXmlWriter(
                                    $this->context,
                                    $xwReceiver
                                );
                                $this->context->scope->preserveNewResultOnNullCall = true;
                                $this->context->type->object->markObjectConstructed(
                                    $this->context->helper->loadValue($obj)
                                );
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                            } elseif ($classOp instanceof Operand\Literal
                                && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'XSLTProcessor')
                                && \PHPCompiler\JIT\XsltInstanceMethodJit::isUserScriptAot()
                            ) {
                                // Attach host XSLTProcessor at allocate for security/EXSLT fold (#20392).
                                $xsltReceiver = $this->context->getVariableFromOp($resultOp);
                                $this->context->extensionLowering->tryInitXslt(
                                    $this->context,
                                    $xsltReceiver
                                );
                                $this->context->scope->preserveNewResultOnNullCall = true;
                                $this->context->type->object->markObjectConstructed(
                                    $this->context->helper->loadValue($obj)
                                );
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                            } elseif ($classOp instanceof Operand\Literal
                                && \PHPCompiler\JIT\RandomizerInstanceMethodJit::isUserScriptAot()
                                && (
                                    0 === strcasecmp(ltrim($classOp->value, '\\'), 'Random\\Engine\\Mt19937')
                                    || 0 === strcasecmp(ltrim($classOp->value, '\\'), 'Random\\Randomizer')
                                )
                            ) {
                                $ctorProxy = strtolower(ltrim($classOp->value, '\\')).'::__construct';
                                \PHPCompiler\JIT\RandomizerInstanceMethodJit::ensureProxy($this->context, $ctorProxy);
                                $this->context->scope->toCall = $this->context->functionProxies[$ctorProxy];
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } elseif ($this->context->type->object->hasConstructor($classId)) {
                                $proxyName = strtolower($resolvedName).'::'.'__construct';
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                                if (0 === strcasecmp($resolvedName, 'DateTimeZone')) {
                                    // Remember New result so construct can stamp zone id onto `$z` (#29732).
                                    $this->context->lastDateTimeZoneNewResultOp = $resultOp;
                                    $this->context->lastDateTimeZoneNewResultVar = $this->context->getVariableFromOp($resultOp);
                                }
                                if (
                                    0 === strcasecmp($resolvedName, 'DateTime')
                                    || 0 === strcasecmp($resolvedName, 'DateTimeImmutable')
                                ) {
                                    $this->context->lastDateTimeNewResultOp = $resultOp;
                                    $this->context->lastDateTimeNewResultVar = $this->context->getVariableFromOp($resultOp);
                                    // Bind `$p = new DateTime` LHS before __construct sync so empty-hint
                                    // does not publish onto a later preallocated local like `$a` (#34461).
                                    $this->prebindDateTimeNewAssignTarget(
                                        $block,
                                        $op,
                                        $resultOp,
                                        $this->context->lastDateTimeNewResultVar
                                    );
                                }
                                if (0 === strcasecmp($resolvedName, 'DateInterval')) {
                                    $this->context->lastDateIntervalNewResultOp = $resultOp;
                                    $this->context->lastDateIntervalNewResultVar = $this->context->getVariableFromOp($resultOp);
                                }
                                if (0 === strcasecmp($resolvedName, 'DatePeriod')) {
                                    $this->context->lastDatePeriodNewResultOp = $resultOp;
                                    $this->context->lastDatePeriodNewResultVar = $this->context->getVariableFromOp($resultOp);
                                }
                            } elseif (
                                null !== ($inheritedCtor = $this->context->type->object->inheritedConstructorProxyLc($resolvedName))
                            ) {
                                // User subclass without own __construct inherits Exception/Error ctor (#23974 / #23641).
                                $this->context->scope->toCall = $this->context->resolveFunctionProxy($inheritedCtor);
                                $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                                $this->context->scope->callArgsIncludeReceiver = true;
                            } else {
                                $this->context->scope->preserveNewResultOnNullCall = true;
                                $this->context->type->object->markObjectConstructed(
                                    $this->context->helper->loadValue($obj)
                                );
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                $this->context->scope->callArgsIncludeReceiver = false;
                            }
                        }
                    }
                    break;
        }
    }
}
