<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Config;
use PHPCompiler\JIT;
use PHPCompiler\VM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\Printer;
use PHPCompiler\Runtime;
use PHPCompiler\CompileResult;
use SplObjectStorage;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCfg\Script;
use PHPTypes\Type;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ClassConstExpr;
use PHPCompiler\VM\ClassConstMaterializer;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\ClassReadonly;
use PHPCompiler\VM\ClassFinal;
use PHPCompiler\VM\ClosureRichDisplayName;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\Ast\FinalPromotedPropertyRewriter;
use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Ast\GeneratorYieldSourceMarker;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Compiler\AbstractMethodBodyCheck;
use PHPCompiler\Compiler\AbstractMethodVisibilityCheck;
use PHPCompiler\Compiler\AbstractPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\InterfaceConstAmbiguityCheck;
use PHPCompiler\Compiler\InterfaceConstVisibilityCheck;
use PHPCompiler\Compiler\InterfaceMethodBodyCheck;
use PHPCompiler\Compiler\InterfaceMethodFinalCheck;
use PHPCompiler\Compiler\InterfaceMethodVisibilityCheck;
use PHPCompiler\Compiler\EnumAbstractMethodCompileCheck;
use PHPCompiler\Compiler\EnumBuiltinMethodRedeclareCheck;
use PHPCompiler\Compiler\ClassConstDuplicateCheck;
use PHPCompiler\Compiler\ClosureUseDuplicateCompileCheck;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
use PHPCompiler\Compiler\EnumMagicMethodCheck;
use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Compiler\MagicMethodArityCheck;
use PHPCompiler\Compiler\MagicMethodParamTypeCheck;
use PHPCompiler\Compiler\MagicMethodReturnTypeCheck;
use PHPCompiler\Compiler\MagicMethodStaticCheck;
use PHPCompiler\Compiler\PseudoClassTypeHintCompileCheck;
use PHPCompiler\Compiler\DuplicateUnionMemberCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmCompileCheck;
use PHPCompiler\Compiler\RedundantDnfArmSubsetCompileCheck;
use PHPCompiler\Compiler\RedundantObjectClassUnionCompileCheck;
use PHPCompiler\Compiler\IntersectionTypeMemberCompileCheck;
use PHPCompiler\Compiler\FunctionStaticAnonymousClassCompileCheck;
use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Compiler\NonAbstractMethodBodyCheck;
use PHPCompiler\Compiler\NonEnumBuiltinInterfaceCompileCheck;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Compiler\AsymmetricVisibilityCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeConstantEvaluator;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeMetadata;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\FinalClassConstCheck;
use PHPCompiler\Compiler\TraitClassConstConflictCheck;
use PHPCompiler\Compiler\FinalClassExtensionCheck;
use PHPCompiler\Compiler\ImplementsHierarchyCompileCheck;
use PHPCompiler\VM\ImplementsHierarchyRuntimeCheck;
use PHPCompiler\Compiler\FinalMethodOverrideCheck;
use PHPCompiler\Compiler\FinalPropertyOverrideCheck;
use PHPCompiler\Compiler\InterfaceImplementationCheck;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Compiler\GeneratorStaticMethodCompileCheck;
use PHPCompiler\Compiler\ReadonlyClassCompileCheck;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Compiler\TraitCollisionCheck;
use PHPCompiler\Compiler\ClassConstVisibilityInheritCheck;
use PHPCompiler\Compiler\PropertyVisibilityInheritCheck;
use PHPCompiler\Compiler\TypedClassConstInheritCheck;
use PHPCompiler\Compiler\TypedPropertyInheritCheck;
use PHPCompiler\Compiler\VariadicPromotedPropertyCompileCheck;
use PHPCompiler\Compiler\ClassCompileRegistry;
use PHPCompiler\Compiler\OverrideValidator;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\Superglobals;

/**
 * Expression compile dispatch (`compileExpr`) (#36387 / #36403).
 *
 * Extracted from {@see CompileExprAndOpcodeTypes} so gen-0 split-TU can hollow
 * a smaller Concern TU. Opcode-type helpers remain in the parent trait.
 * Assign / AssignRef cases live in {@see CompileExprAssignDispatch}.
 * BinaryOp / Cast / Exit / unary / empty / eval / print live in
 * {@see CompileExprBinaryOpCastAndUnaryDispatch}.
 * Mirrors php-src Zend/zend_compile.c expression compile — move-only; no
 * behavior change intended.
 */
trait CompileExprDispatch
{
    protected function compileExpr(Op\Expr $expr, Block $block): array {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return $this->compileBinaryOpExpr($expr, $block);
        }
        if ($expr instanceof Op\Expr\Cast) {
            return $this->compileCastExpr($expr, $block);
        }
        switch (get_class($expr)) {
            case Op\Expr\ArrowFunction::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Closure::class:
                return $this->compileAnonymousFunctionExpr($expr, $block);
            case Op\Expr\Assertion::class:
                if ($expr->result instanceof Operand\Literal) {
                    //short circuit
                    return [];
                } elseif ($expr->expr === $expr->result) {
                    return [];
                }
                return [new OpCode(
                    OpCode::TYPE_TYPE_ASSERT,
                    $this->compileOperand($expr->result, $block, false),   
                    $this->compileOperand($expr->expr, $block, true) 
                )];
            case Op\Expr\Assign::class:
                return $this->compileAssignExpr($expr, $block);
            case Op\Expr\Exit_::class:
                return $this->compileExitExpr($expr, $block);
            case Op\Expr\PostInc::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_POST_INC);
            case Op\Expr\PreInc::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_PRE_INC);
            case Op\Expr\PostDec::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_POST_DEC);
            case Op\Expr\PreDec::class:
                return $this->compileIncDecExpr($expr, $block, OpCode::TYPE_PRE_DEC);
            case Op\Expr\UnaryMinus::class:
            case Op\Expr\UnaryPlus::class:
                return $this->compileUnaryPlusMinusExpr($expr, $block);
            case Op\Expr\BitwiseNot::class:
            case Op\Expr\BooleanNot::class:
            case Op\Expr\Clone_::class:
                return $this->compileBitwiseBooleanNotOrCloneExpr($expr, $block);
            case Op\Expr\Empty_::class:
                return $this->compileEmptyExpr($expr, $block);
            case Op\Expr\Eval_::class:
                return $this->compileEvalExpr($expr, $block);
            case Op\Expr\Print_::class:
                return $this->compilePrintExpr($expr, $block);
            case Op\Expr\ArrayDimFetch::class:
                $this->rejectArrayEmptyOffsetRead($expr, $block);
                $this->rejectGlobalsAppend($expr, $block);
                $mergeEcho = $this->mergeEchoSlotForBranch($block);
                $dimForWrite = $this->isArrayDimFetchForWrite($expr, $block);
                // By-ref call args also use FETCH_DIM_W — reject temporary bases (#29522 / #29247).
                if ($dimForWrite) {
                    $this->rejectTemporaryExpressionInWriteContext($expr->result, $block, $expr);
                }
                if (null !== $mergeEcho && !$dimForWrite) {
                    $block->forceFreshVarSlot($expr->result, $mergeEcho);
                }
                $prefix = [];
                $dimSlot = null !== $expr->dim
                    ? $this->compileOperand($expr->dim, $block, true)
                    : null;
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                // Echo/ternary merge phi must not share a slot with dim keys (#3790 / #5506).
                // Literals: rematerialize the constant. Non-literals (e.g. `new T()` result):
                // copy into a fresh temp — never forceFreshVarSlot the live producer operand,
                // which remaps TYPE_NEW's result away from the object and turns the key into
                // null→"" (#29532, zend_hash Illegal offset type).
                if (null !== $mergeEcho && null !== $dimSlot && $dimSlot === $mergeEcho && null !== $expr->dim) {
                    if ($expr->dim instanceof Operand\Literal) {
                        $dimSlot = $this->freshLiteralConstantSlot($expr->dim, $block);
                    } else {
                        $dimTemp = new Operand\Temporary();
                        $srcOp = $block->getOperand($dimSlot);
                        if (null !== $srcOp?->type) {
                            $dimTemp->type = $srcOp->type;
                        }
                        $freshDim = $block->forceFreshVarSlot($dimTemp);
                        $prefix[] = new OpCode(OpCode::TYPE_ASSIGN, $freshDim, $freshDim, $dimSlot);
                        $dimSlot = $freshDim;
                    }
                }
                if (null !== $dimSlot && $resultSlot === $dimSlot) {
                    $block->forceFreshVarSlot($expr->result);
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }
                $fetchType = $dimForWrite
                    ? OpCode::TYPE_ARRAY_DIM_FETCH_WRITE
                    : OpCode::TYPE_ARRAY_DIM_FETCH;

                $fetchOp = new OpCode(
                    $fetchType,
                    $resultSlot,
                    $this->compileArrayDimFetchContainerSlot($expr, $block),
                    $dimSlot
                );
                // Zend attributes Undefined array key to the dim-fetch opline (#31994, zend_vm_def.h).
                $this->assignSourceMetadata($fetchOp, $expr);

                return array_merge($prefix, [$fetchOp]);
            case Op\Expr\ConstFetch::class:
                $nsName = null;
                if (!is_null($expr->nsName)) {
                    $nsName = $this->compileOperand($expr->nsName, $block, true);
                }
                $op = new OpCode(
                    OpCode::TYPE_CONST_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->name, $block, true),
                    $nsName
                );
                $this->assignSourceMetadata($op, $expr);

                return [$op];
            case Op\Expr\ClassConstFetch::class:
                return $this->compileClassConstFetch($expr, $block);
            case Op\Expr\StaticPropertyFetch::class:
                $staticFetchOp = new OpCode(
                    OpCode::TYPE_STATIC_PROPERTY_FETCH,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileClassNameOperand($expr->class, $block),
                    $this->compileStaticPropertyNameSlot($expr->name, $expr->class, $block)
                );
                // Stamp user line for typed-static uninit Errors (#31859, zend_object_handlers.c).
                $this->assignSourceMetadata($staticFetchOp, $expr);
                // isset/empty/?? on dim of Class::$prop — FETCH_STATIC_PROP_IS (#31783).
                if ($this->isStaticPropertyFetchPreludeForDimIssetEmptyOrCoalesce($expr, $block)) {
                    $staticFetchOp->propertyHookCoalesceRead = true;
                }

                return [$staticFetchOp];
            case Op\Expr\FirstClassCallable::class:
                return $this->compileFirstClassCallable($expr, $block);
            case Op\Expr\FuncCall::class:
                if ($this->parensNewCallSkippedWithoutInvoke($expr->name, $block)) {
                    return [];
                }
                if ($this->operandIsInvokableReceiver($expr->name, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->name, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr,
                        true
                    );
                }

                $splitCall = $this->compileFuncCallAfterChainedCoalesceArgs($expr, $block);
                if (null !== $splitCall) {
                    return $splitCall;
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->name, $block, true),
                    $expr->args,
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr
                );
            case Op\Expr\NsFuncCall::class:
                if ($this->parensNewCallSkippedWithoutInvoke($expr->nsName, $block)) {
                    return [];
                }
                if ($this->operandIsInvokableReceiver($expr->nsName, $block)) {
                    return $this->compileMethodCallOpcodes(
                        $this->compileOperand($expr->nsName, $block, true),
                        $this->compileOperand(new Operand\Literal('__invoke'), $block, true),
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr,
                        true
                    );
                }

                return $this->compileFuncCall(
                    $this->compileOperand($expr->nsName, $block, true),
                    $expr->args,
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr
                );
            case Op\Expr\StaticCall::class:
                $this->rejectPseudoClassStaticCallOutsideClassScope($expr, $block);
                $fromCallableFcc = $this->tryCompileClosureFromCallableAsFcc($expr, $block);
                if (null !== $fromCallableFcc) {
                    return $fromCallableFcc;
                }
                $parentScope = $this->staticCallUsesParentScope($expr->class);
                $classSlot = $parentScope
                    ? $this->compileOperand(new Operand\Literal('parent'), $block, true)
                    : $this->compileOperand($expr->class, $block, true);
                $init = new OpCode(
                    OpCode::TYPE_STATICCALL_INIT,
                    $classSlot,
                    $this->compileOperand($expr->name, $block, true)
                );
                $init->staticCallParentScope = $parentScope;
                $className = $this->literalScopeClassName($expr->class)
                    ?? $this->staticNameFromOperand($expr->class);
                $methodName = $this->staticNameFromOperand($expr->name);
                $calleeName = null;
                if (null !== $className && null !== $methodName) {
                    $calleeName = ltrim($className, '\\').'::'.$methodName;
                }

                return $this->compileStaticCallOpcodes(
                    $init,
                    $expr->args,
                    $expr->result,
                    $block,
                    max(0, $expr->getLine()),
                    $expr,
                    $calleeName
                );
            case Op\Expr\New_::class:
                $this->rejectPseudoClassNewOutsideClassScope($expr, $block);
                // Abstract/enum `new` is a runtime Error when NEW executes (Zend zend_execute.c),
                // not a unit-wide compile fatal — dead `if (false) { new Abstract; }` must load (#25787 / re-#3385).
                $className = $this->literalScopeClassName($expr->class);
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                $line = $expr->getLine();
                $return = [
                    new OpCode(
                        OpCode::TYPE_NEW,
                        $resultSlot,
                        $this->compileOperand($expr->class, $block, true),
                        $line > 0 ? $line : null
                    )
                ];
                foreach ($this->compileCallArgSends($expr->args, $block, $className, $expr) as $send) {
                    $return[] = $send;
                }
                $return[] = $this->compileFuncCallExecOpcode(
                    $expr->result,
                    $block,
                    $line > 0 ? $line : 0,
                    $expr
                );
                $this->markInlineNewProducerKeepSlotForSiblingConsumer($expr, $block, (int) $resultSlot);

                return $return;
            case Op\Expr\MethodCall::class:
                $mergeEcho = $this->mergeEchoSlotForBranch($block);
                $catchReceiverSlot = $this->slotForActiveCatchVariable($expr->var);
                $receiverSlot = null !== $catchReceiverSlot
                    ? $catchReceiverSlot
                    : $this->compileOperand($expr->var, $block, true);
                $nameSlot = $this->compileOperand($expr->name, $block, true);
                $prefix = [];
                if (null !== $mergeEcho && $nameSlot === $mergeEcho) {
                    $nameSlot = $this->freshLiteralConstantSlot($expr->name, $block);
                }
                if (null !== $mergeEcho && null === $catchReceiverSlot) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                    if ($resultSlot === $mergeEcho) {
                        $block->forceFreshVarSlot($expr->result);
                    }
                    // Receiver must not alias ?: echo phi (condition var is often reused, #5506).
                    // Copy PHPCfg type so __call / method resolution keep the class (#26427 try+echo).
                    $recvTemp = new Operand\Temporary();
                    $srcOp = $block->getOperand($receiverSlot);
                    if (null !== $srcOp?->type) {
                        $recvTemp->type = $srcOp->type;
                    }
                    $recvSlot = $block->forceFreshVarSlot($recvTemp);
                    $prefix[] = new OpCode(OpCode::TYPE_ASSIGN, $recvSlot, $recvSlot, $receiverSlot);
                    $receiverSlot = $recvSlot;
                }

                return array_merge(
                    $prefix,
                    $this->compileMethodCallOpcodes(
                        $receiverSlot,
                        $nameSlot,
                        $expr->args,
                        $expr->result,
                        $block,
                        max(0, $expr->getLine()),
                        $expr
                    )
                );
            case Op\Expr\PropertyFetch::class:
                $propForWrite = $this->isPropertyFetchForWrite($expr, $block);
                // By-ref call args use FETCH_OBJ_W — reject (new …)->prop temps (#29522 / #29247).
                if ($propForWrite) {
                    $this->rejectTemporaryExpressionInWriteContext($expr->result, $block, $expr);
                }
                $fetchType = $propForWrite
                    ? OpCode::TYPE_PROPERTY_FETCH_WRITE
                    : OpCode::TYPE_PROPERTY_FETCH;

                $fetchOp = new OpCode(
                    $fetchType,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $this->compileOperand($expr->name, $block, true)
                );
                // Zend attributes dynamic-property E_DEPRECATED to the fetch/write site (#21953).
                $this->assignSourceMetadata($fetchOp, $expr);
                // isset/empty/?? on dim of $obj->prop — FETCH_OBJ_IS (#31783, zend_object_handlers.c).
                if (
                    !$propForWrite
                    && $this->isPropertyFetchPreludeForDimIssetEmptyOrCoalesce($expr, $block)
                ) {
                    $fetchOp->propertyHookCoalesceRead = true;
                }

                return [$fetchOp];
            case Op\Expr\Array_::class:
                return $this->compileArrayLiteral($expr, $block);
            case Op\Expr\MagicScriptConst::class:
                $line = null;
                if (Op\Expr\MagicScriptConst::KIND_LINE === $expr->kind) {
                    $line = max(1, $expr->getLine());
                    // wrapEvalCode prepends "<?php\n" — Zend __LINE__ is 1-based in the eval string (#25809).
                    if (\PHPCompiler\ext\standard\VmEval::isEvalScriptPath($block->scriptPath())) {
                        $line = \PHPCompiler\ext\standard\VmEval::unwrapEvalLine($line);
                    }
                }

                return [new OpCode(
                    OpCode::TYPE_SCRIPT_MAGIC,
                    $this->compileOperand($expr->result, $block, false),
                    $line,
                    Op\Expr\MagicScriptConst::KIND_HALT_OFFSET === $expr->kind
                        ? OpCode::SCRIPT_MAGIC_HALT_OFFSET
                        : $expr->kind,
                )];
            case Op\Expr\Include_::class:
                // Re-lowering the same Include_ (CFG walk + call-arg compileExpr) re-runs once-skip
                // and overwrites the first-load int(1) with bool(true) (#25852).
                if (isset($block->emittedIncludeOrEvalExprIds[spl_object_id($expr)])) {
                    return [];
                }

                return [$this->compileIncludeOp($expr, $block)];
            case Op\Expr\Isset_::class:
                return $this->compileIsset($expr, $block);
            case Op\Expr\Throw_::class:
                return $this->compileThrowExpression($expr, $block);
            case Op\Iterator\Valid::class:
                $iterValid = new OpCode(
                    OpCode::TYPE_ITER_VALID,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                );
                $this->assignSourceMetadata($iterValid, $expr);

                return [$iterValid];
            case Op\Iterator\Key::class:
                $iterKey = new OpCode(
                    OpCode::TYPE_ITER_KEY,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true)
                );
                $this->assignSourceMetadata($iterKey, $expr);

                return [$iterKey];
            case Op\Iterator\Value::class:
                $iterValue = new OpCode(
                    OpCode::TYPE_ITER_VALUE,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->var, $block, true),
                    $expr->byRef ? 1 : 0
                );
                $this->assignSourceMetadata($iterValue, $expr);

                return [$iterValue];
            case Op\Expr\InstanceOf_::class:
                return $this->compileInstanceOf($expr, $block);
            case Op\Expr\In_::class:
                return $this->compileIn($expr, $block);
            case Op\Expr\AssignRef::class:
                return $this->compileAssignRefExpr($expr, $block);
            case Op\Expr\Yield_::class:
                $this->markFunctionGenerator($block);

                $yieldOp = new OpCode(
                    OpCode::TYPE_YIELD,
                    [] !== $expr->result->usages
                        ? $this->compileOperand($expr->result, $block, false)
                        : null,
                    null !== $expr->value
                        ? $this->compileOperand($expr->value, $block, true)
                        : (null !== $expr->key
                            ? $this->compileOperand($expr->key, $block, true)
                            : null),
                    null !== $expr->value && null !== $expr->key
                        ? $this->compileOperand($expr->key, $block, true)
                        : null,
                );
                $this->assignSourceMetadata($yieldOp, $expr);

                return [$yieldOp];
            case Op\Expr\YieldFrom::class:
                $this->markFunctionGenerator($block);
                $yieldFromOp = new OpCode(
                    OpCode::TYPE_YIELD_FROM,
                    [] !== $expr->result->usages
                        ? $this->compileOperand($expr->result, $block, false)
                        : null,
                    $this->compileOperand($expr->expr, $block, true),
                );
                $this->assignSourceMetadata($yieldFromOp, $expr);

                return [$yieldFromOp];
            case Op\Expr\NullsafePropertyFetch::class:
                if (null !== $this->slotForNullsafeResult($block, $expr)) {
                    return [];
                }
                $this->compileNullsafePropertyFetch($expr, $block);

                return [];
            case Op\Expr\NullsafeMethodCall::class:
                if (null !== $this->slotForNullsafeResult($block, $expr)) {
                    return [];
                }
                $this->compileNullsafeMethodCall($expr, $block);

                return [];
        }
        $this->throwCompileLogicForOp($expr, 'Unsupported expression: '.$expr->getType());
    }
}
