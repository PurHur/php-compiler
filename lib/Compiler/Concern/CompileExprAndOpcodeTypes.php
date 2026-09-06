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
 * Expression opcode typing + compileExpr dispatch (#36387 / prior #36147).
 *
 * Extracted from {@see ErrorSuppressAndPropertyFetch} so gen-0 split-TU can hollow
 * a smaller Concern TU (`getOpCodeTypeFromBinaryOp` → `compileExpr`). Error-suppress,
 * switch, isset/include, throw, and property-fetch helpers remain in the peer hub.
 *
 * Call sites and visibility stay identical so {@see \PHPCompiler\Lint\LintCompiler}
 * overrides of compileExpr are unaffected.
 * Mirrors php-src Zend/zend_compile.c expression compile — move-only.
 */
trait CompileExprAndOpcodeTypes
{
    protected function getOpCodeTypeFromBinaryOp(Op\Expr\BinaryOp $expr): int {
        if ($expr instanceof Op\Expr\BinaryOp\Concat) {
            return OpCode::TYPE_CONCAT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Plus) {
            return OpCode::TYPE_PLUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Smaller) {
            return OpCode::TYPE_SMALLER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Greater) {
            return OpCode::TYPE_GREATER;
        } elseif ($expr instanceof Op\Expr\BinaryOp\SmallerOrEqual) {
            return OpCode::TYPE_SMALLER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\GreaterOrEqual) {
            return OpCode::TYPE_GREATER_OR_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Equal) {
            return OpCode::TYPE_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotEqual) {
            return OpCode::TYPE_NOT_EQUAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Identical) {
            return OpCode::TYPE_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\NotIdentical) {
            return OpCode::TYPE_NOT_IDENTICAL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Spaceship) {
            return OpCode::TYPE_SPACESHIP;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Minus) {
            return OpCode::TYPE_MINUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mul) {
            return OpCode::TYPE_MUL;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Div) {
            return OpCode::TYPE_DIV;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Mod) {
            return OpCode::TYPE_MODULO;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Pow) {
            return OpCode::TYPE_POW;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseAnd) {
            return OpCode::TYPE_BITWISE_AND;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseOr) {
            return OpCode::TYPE_BITWISE_OR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\BitwiseXor) {
            return OpCode::TYPE_BITWISE_XOR;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftLeft) {
            return OpCode::TYPE_SHIFT_LEFT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\ShiftRight) {
            return OpCode::TYPE_SHIFT_RIGHT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\LogicalXor) {
            return OpCode::TYPE_LOGICAL_XOR;
        }
        $this->throwCompileLogic("Unknown BinaryOp Type: " . $expr->getType());
    }

    protected function getOpCodeTypeFromCastOp(Op\Expr\Cast $expr): int {
        if ($expr instanceof Op\Expr\Cast\Array_) {
            return OpCode::TYPE_CAST_ARRAY;
        } elseif ($expr instanceof Op\Expr\Cast\Bool_) {
            return OpCode::TYPE_CAST_BOOL;
        } elseif ($expr instanceof Op\Expr\Cast\Double) {
            return OpCode::TYPE_CAST_FLOAT;
        } elseif ($expr instanceof Op\Expr\Cast\Int_) {
            return OpCode::TYPE_CAST_INT;
        } elseif ($expr instanceof Op\Expr\Cast\Object_) {
            return OpCode::TYPE_CAST_OBJECT;
        } elseif ($expr instanceof Op\Expr\Cast\String_) {
            return OpCode::TYPE_CAST_STRING;
        } elseif ($expr instanceof Op\Expr\Cast\Unset_) {
            return OpCode::TYPE_CAST_UNSET;
        } elseif ($expr instanceof Op\Expr\Cast\Void_) {
            return OpCode::TYPE_CAST_VOID;
        }
        $this->throwCompileLogic("Unknown CastOp Type: " . $expr->getType());
    }

    protected function compileIncDecExpr(Op\Expr $expr, Block $block, int $opcode): array
    {
        // php-cfg may clear write after SSA replace; read still names the lvalue (#4946).
        $write = $expr->write ?? $expr->read;
        $this->rejectThisReassignment($write);
        $this->rejectGlobalsWrite($write, $expr, $block);
        $this->rejectNullsafeInWriteContext($write, $block);
        $this->rejectNewExprInWriteContext($write, $block, null, null, $expr);
        $this->rejectArrayLiteralInWriteContext($write, $block, $expr);
        $this->rejectGlobalConstInWriteContext($write, $block, $expr);
        $this->rejectCallReturnInWriteContext($write, $block, $expr);

        return [new OpCode(
            $opcode,
            $this->compileOperand($expr->result, $block, false),
            $this->compileOperand($expr->read, $block, true),
            $this->compileOperand($write, $block, false),
        )];
    }

    protected function getOpCodeTypeFromUnaryOp(Op\Expr $expr): int {
        if ($expr instanceof Op\Expr\UnaryMinus) {
            return OpCode::TYPE_UNARY_MINUS;
        } elseif ($expr instanceof Op\Expr\UnaryPlus) {
            return OpCode::TYPE_UNARY_PLUS;
        } elseif ($expr instanceof Op\Expr\BitwiseNot) {
            return OpCode::TYPE_BITWISE_NOT;
        } elseif ($expr instanceof Op\Expr\BooleanNot) {
            return OpCode::TYPE_BOOLEAN_NOT;
        } elseif ($expr instanceof Op\Expr\Clone_) {
            return OpCode::TYPE_CLONE;
        } elseif ($expr instanceof Op\Expr\Empty_) {
            return OpCode::TYPE_EMPTY;
        } elseif ($expr instanceof Op\Expr\Eval_) {
            return OpCode::TYPE_EVAL;
        } elseif ($expr instanceof Op\Expr\Exit_) {
            return OpCode::TYPE_EXIT;
        } elseif ($expr instanceof Op\Expr\Print_) {
            return OpCode::TYPE_PRINT;
        }
        $this->throwCompileLogic("Unknown UnaryOp Type: " . $expr->getType());
    }

    protected function compileExpr(Op\Expr $expr, Block $block): array {
        if ($expr instanceof Op\Expr\BinaryOp\Coalesce) {
            $this->compileCoalesce($expr, $block);

            return [];
        }
        if ($expr instanceof Op\Expr\BinaryOp) {
            if (null !== $expr->left) {
                $this->compileEmbeddedExprForOperand($expr->left, $block);
            }
            if (null !== $expr->right) {
                $this->compileEmbeddedExprForOperand($expr->right, $block);
            }
            $resultSlot = $block->inheritUndefinedLocals
                ? $block->forceFreshVarSlot($expr->result)
                : $this->compileOperand($expr->result, $block, false);
            if (!$block->closureCaptureSlotWritableForOperand($resultSlot, $expr->result)) {
                $resultSlot = $block->forceFreshVarSlot($expr->result);
            }
            $opcode = new OpCode(
                $this->getOpCodeTypeFromBinaryOp($expr),
                $resultSlot,
                null !== $expr->left ? $this->compileOperand($expr->left, $block, true) : null,
                null !== $expr->right ? $this->compileOperand($expr->right, $block, true) : null,
            );
            if ($this->isIncDecBinaryOp($expr)) {
                $opcode->isIncDec = true;
            }
            $this->assignSourceMetadata($opcode, $expr);

            return [$opcode];
        } elseif ($expr instanceof Op\Expr\Cast) {
            if ($expr instanceof Op\Expr\Cast\Unset_) {
                $this->throwCompileError('The (unset) cast is no longer supported');
            }
            $line = $expr->getLine();
            // Seed jump-target ||/&& phi before lowering the cast. Prefer that seeded slot over
            // logicalShortCircuitPhiMergeSlot when the cast sits *inside* an inner && merge that
            // jumps to an outer || merge — otherwise the cast assigns the inner phi and the outer
            // phi keeps a leftover callee-name string (#25850, re-#10626).
            $seededPhiSlot = null;
            if (null !== $block->orig) {
                $seededPhiSlot = $this->seedLogicalShortCircuitPhiSlot($block->orig, $block, $expr->result);
            }
            $castResultSlot = $this->compileOperand($expr->result, $block, false);
            $ops = [new OpCode(
                $this->getOpCodeTypeFromCastOp($expr),
                $castResultSlot,
                $this->compileOperand($expr->expr, $block, true),
                $line > 0 ? $line : null,
            )];
            if ($expr instanceof Op\Expr\Cast\Bool_) {
                $phiSlot = $seededPhiSlot
                    ?? $this->logicalShortCircuitJumpTargetPhiMergeSlot($block)
                    ?? $this->logicalShortCircuitPhiMergeSlot($block);
                if (null !== $phiSlot) {
                    if ($block->isNamedVariableSlot($phiSlot)) {
                        $phiSlot = $block->forceFreshVarSlot($expr->result, $phiSlot);
                        if (null !== $block->orig) {
                            $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                            if (null !== $mergeCfg) {
                                $this->ternaryMergePhiRhsSlots[$mergeCfg] = $phiSlot;
                            }
                        }
                    }
                    if ($castResultSlot !== $phiSlot) {
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $phiSlot,
                            $phiSlot,
                            $castResultSlot
                        );
                    }
                }
            }

            return $ops;
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
                if (!$this->assignIsListSpread($expr)) {
                    $this->rejectThisReassignment($expr->var);
                    $this->rejectGlobalsWrite($expr->var, $expr, $block);
                    $this->rejectNullsafeInWriteContext($expr->var, $block);
                    $this->rejectNewExprInWriteContext($expr->var, $block, $expr->expr, $expr);
                    $this->rejectArrayLiteralInWriteContext($expr->var, $block, $expr);
                    $this->rejectGlobalConstInWriteContext($expr->var, $block, $expr);
                    $this->rejectCallReturnInWriteContext($expr->var, $block, $expr);
                }
                if ($this->assignIsListSpread($expr)) {
                    $this->rejectListSpreadAssignExpr($expr);
                    $fromIndex = new Operand\Literal($expr->listSpreadFromIndex);
                    $spreadOp = new OpCode(
                        OpCode::TYPE_LIST_SPREAD_ASSIGN,
                        $this->compileOperand($expr->var, $block, false),
                        $this->compileOperand($expr->listSpreadRhs, $block, true),
                        $this->compileOperand($fromIndex, $block, true),
                    );
                    $spreadOp->listSpreadExcludedKeys = $expr->listSpreadExcludedKeys ?? [];

                    return [$spreadOp];
                }
                $staticPropertyFetch = $this->unwrapStaticPropertyFetch($expr->var);
                $emitStaticPropertyFetch = true;
                if (null === $staticPropertyFetch) {
                    $staticPropertyFetch = $this->findStaticPropertyFetchForAssign($expr->var, $block);
                    $emitStaticPropertyFetch = false;
                }
                if (null !== $staticPropertyFetch) {
                    $fetchSlot = $this->compileOperand($staticPropertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $ops = [];
                    if ($emitStaticPropertyFetch) {
                        $staticFetchOp = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_FETCH,
                            $fetchSlot,
                            $this->compileClassNameOperand($staticPropertyFetch->class, $block),
                            $this->compileStaticPropertyNameSlot($staticPropertyFetch->name, $staticPropertyFetch->class, $block)
                        );
                        $this->assignSourceMetadata($staticFetchOp, $staticPropertyFetch);
                        $ops[] = $staticFetchOp;
                    }
                    // One property write; publish used result without re-writing the slot (#29194).
                    // Stamp ASSIGN (not only the fetch) so JIT private(set) Errors cite the write (#29665).
                    $writeOp = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $fetchSlot,
                        $fetchSlot,
                        $rhsSlot
                    );
                    $this->assignSourceMetadata($writeOp, $expr);
                    $ops[] = $writeOp;
                    if ([] !== $expr->result->usages) {
                        $resultSlot = $this->compileOperand($expr->result, $block, false);
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $resultSlot,
                            $resultSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }
                $propertyFetch = $this->unwrapPropertyFetch($expr->var)
                    ?? $this->findCoalescePropertyFetch($expr->var, $block);
                if (null !== $propertyFetch) {
                    $fetchSlot = $this->compileOperand($propertyFetch->result, $block, false);
                    $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                    $fetchOp = new OpCode(
                        OpCode::TYPE_PROPERTY_FETCH,
                        $fetchSlot,
                        $this->compileOperand($propertyFetch->var, $block, true),
                        $this->compileOperand($propertyFetch->name, $block, true)
                    );
                    // Assign-lowered property writes skip compileExpr(PropertyFetch); stamp line here (#21953).
                    $this->assignSourceMetadata($fetchOp, $propertyFetch);
                    // Write the property once. A follow-up ASSIGN must not use fetchSlot as dest —
                    // that re-invokes __set for `$r = ($obj->prop = $v)` (#29194). Publish the
                    // expression value into resultSlot only (dest=resultSlot).
                    // Stamp the ASSIGN with the Assign expr so mid-method private(set) Errors
                    // report the write line, not a stale callSiteLine (#29665 / zend_object_handlers.c).
                    $writeOp = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $fetchSlot,
                        $fetchSlot,
                        $rhsSlot
                    );
                    $this->assignSourceMetadata($writeOp, $expr);
                    $ops = [
                        $fetchOp,
                        $writeOp,
                    ];
                    if ([] !== $expr->result->usages) {
                        $resultSlot = $this->compileOperand($expr->result, $block, false);
                        $ops[] = new OpCode(
                            OpCode::TYPE_ASSIGN,
                            $resultSlot,
                            $resultSlot,
                            $rhsSlot
                        );
                    }

                    return $ops;
                }

                $mergeAssignSlot = $this->branchMergeAssignSlot($block, $expr);
                if ($expr->expr instanceof Operand\Literal && null !== $block->orig) {
                    $literalMerge = $this->branchJumpMergeTarget($block->orig);
                    if (
                        null !== $literalMerge
                        && $this->mergeCfgBlockUsesLogicalShortCircuit($literalMerge)
                    ) {
                        $tail = $this->branchTailExprBeforeJump($block->orig);
                        if ($tail === $expr) {
                            $literalPhi = $this->logicalShortCircuitSiblingPhiSlot($block)
                                ?? $this->logicalShortCircuitPhiMergeSlot($block);
                            if (null !== $literalPhi) {
                                $mergeAssignSlot = $literalPhi;
                            }
                        }
                    }
                }
                // Named CV assigns keep their own slots across try/catch merge (#29482).
                // Catch lowers before try (#6411); without this guard, catch's CV is recorded
                // as ternary echo-phi and the try-body assign is forced onto that sibling
                // slot — same class as #26490 (branchMergeAssignSlot) but this override path
                // previously bypassed the named-CV check.
                $assignIsNamedCv = null !== Block::resolveVariableName($expr->var);
                if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
                    $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                    if (
                        null !== $mergeCfg
                        && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)
                        && !$assignIsNamedCv
                    ) {
                        $recordedPhi = $this->ternaryMergePhiRhsSlot($mergeCfg);
                        if (null !== $recordedPhi) {
                            $mergeAssignSlot = $recordedPhi;
                        }
                    }
                }
                if (null !== $mergeAssignSlot) {
                    $root = Block::cfgVarRoot($expr->var);
                    if ($root instanceof Operand\Variable) {
                        $block->prebindCfgVarRoot($root, $mergeAssignSlot);
                    } else {
                        $block->bindScopeSlot($expr->var, $mergeAssignSlot);
                    }
                }
                $destSlot = null !== $mergeAssignSlot
                    ? $mergeAssignSlot
                    : $this->compileOperand($expr->var, $block, false);
                if (null !== $block->orig && $this->isMergeBranchAssign($block, $expr)) {
                    $mergeCfg = $this->branchJumpMergeTarget($block->orig);
                    if (
                        null !== $mergeCfg
                        && $this->mergeCfgBlockUsesTernaryPhi($mergeCfg)
                        && !$assignIsNamedCv
                    ) {
                        if (!$this->ternaryMergePhiRhsSlots->contains($mergeCfg)) {
                            $this->ternaryMergePhiRhsSlots[$mergeCfg] = (int) $destSlot;
                        }
                    }
                }
                $rhsSlot = $this->compileOperand($expr->expr, $block, true);
                $this->reconcileEncapsedConcatListAssignSlots($expr, $block, $destSlot, $rhsSlot);
                $resultSlot = $this->compileOperand($expr->result, $block, false);
                $varRoot = Block::cfgVarRoot($expr->var);
                if (null !== $varRoot) {
                    // Register the CV lvalue slot — assign.result temps diverge after $a[] writes (#12712).
                    $block->registerNamedAssignDest($varRoot, (int) $destSlot);
                }

                $assignOp = new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $resultSlot,
                    $destSlot,
                    $rhsSlot
                );
                $block->registerAssignResultLvalue((int) $resultSlot, (int) $destSlot);
                $this->assignSourceMetadata($assignOp, $expr);

                return [$assignOp];
            case Op\Expr\Exit_::class:
                $exitExpr = null !== $expr->expr
                    ? $this->compileOperand($expr->expr, $block, true)
                    : null;
                $resultSlot = null;
                if ([] !== $expr->result->usages || $block->callResultFeedsReturn($expr->result)) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                }

                $exitOp = new OpCode(
                    OpCode::TYPE_EXIT,
                    $resultSlot,
                    $exitExpr,
                    max(0, $expr->getLine())
                );
                if (null !== $expr->message) {
                    $exitOp->exitMessageSlot = $this->compileOperand($expr->message, $block, true);
                }

                return [$exitOp];
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
                $foldedUnaryLiteral = $this->tryFoldUnaryLiteralDefault($expr);
                if (null !== $foldedUnaryLiteral) {
                    $block->registerConstant($expr->result, $foldedUnaryLiteral);

                    return [];
                }

                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                )];
            case Op\Expr\BitwiseNot::class:
            case Op\Expr\BooleanNot::class:
            case Op\Expr\Clone_::class:
                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                )];
            case Op\Expr\Empty_::class:
                if ([] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($expr, $block))) {
                    $this->compileEmptyNullsafePropertyFetchChain($nullsafeChain, $expr, $block);

                    return [];
                }
                $emptyOperand = $this->recoverEmptyExprOperand($expr, $block)
                    ?? $this->unaryExprOperandForRead($expr, $block);
                $propFetch = null !== $emptyOperand
                    ? $this->findCoalescePropertyFetch($emptyOperand, $block)
                    : null;
                if (null === $propFetch && null !== $emptyOperand) {
                    $propFetch = $this->unwrapPropertyFetch($emptyOperand);
                }
                if (null !== $propFetch) {
                    return [new OpCode(
                        OpCode::TYPE_EMPTY_OBJECT_PROPERTY,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($propFetch->var, $block, true),
                        $this->compileOperand($propFetch->name, $block, true),
                    )];
                }
                $staticPropFetch = null !== $emptyOperand
                    ? $this->findCoalesceStaticPropertyFetch($emptyOperand, $block)
                    : null;
                if (null === $staticPropFetch && null !== $emptyOperand) {
                    $staticPropFetch = $this->unwrapStaticPropertyFetch($emptyOperand);
                }
                if (null !== $staticPropFetch) {
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                    [$classSlot, $nameSlot] = $this->resolveIssetTargetFromStaticPropertyFetch($staticPropFetch, $block);

                    return [new OpCode(
                        OpCode::TYPE_EMPTY_STATIC_PROPERTY,
                        $resultSlot,
                        $classSlot,
                        $nameSlot
                    )];
                }
                $dimFetch = null !== $emptyOperand
                    ? $this->findCoalesceArrayDimFetch($emptyOperand, $block)
                    : null;
                if (null !== $dimFetch) {
                    $chain = $this->collectArrayDimFetchChain($dimFetch, $block);
                    foreach ($chain as $chainFetch) {
                        $this->rejectArrayEmptyOffsetRead($chainFetch, $block);
                    }
                    $resultSlot = $this->compileOperand($expr->result, $block, false);
                    [$prefixOps, $containerSlot] = $this->emitQuietDimFetchChainPrefix($chain, $block);
                    $lastFetch = $chain[count($chain) - 1];
                    $dimSlot = null !== $lastFetch->dim
                        ? $this->compileOperand($lastFetch->dim, $block, true)
                        : null;
                    if (null !== $containerSlot) {
                        $prefixOps[] = new OpCode(
                            OpCode::TYPE_EMPTY_DIMENSION,
                            $resultSlot,
                            $containerSlot,
                            $dimSlot
                        );

                        return $prefixOps;
                    }
                }

                $op = new OpCode(
                    OpCode::TYPE_EMPTY,
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileUnaryExprReadOperand($expr, $block)
                );
                $this->assignSourceMetadata($op, $expr);

                return [$op];
            case Op\Expr\Eval_::class:
                $evalOp = new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true)
                );
                // Call-site line for Zend eval __FILE__ / fatals: parent(line) : eval()'d code (#25809, #4410).
                $this->assignSourceMetadata($evalOp, $expr);

                return [$evalOp];
            case Op\Expr\Print_::class:
                $line = $expr->getLine();

                return [new OpCode(
                    $this->getOpCodeTypeFromUnaryOp($expr),
                    $this->compileOperand($expr->result, $block, false),
                    $this->compileOperand($expr->expr, $block, true),
                    $line > 0 ? $line : null
                )];
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
                $this->rejectThisReassignment($expr->var);
                $this->rejectGlobalsWrite($expr->var, $expr, $block);
                $this->rejectNullsafeInWriteContext($expr->var, $block);
                $this->rejectNewExprInWriteContext($expr->var, $block, null, null, $expr);
                $this->rejectArrayLiteralInWriteContext($expr->var, $block, $expr);
                $this->rejectGlobalConstInWriteContext($expr->var, $block, $expr);
                $this->rejectCallReturnInWriteContext($expr->var, $block, $expr);
                // Zend zend_compile.c: cannot acquire a reference to $GLOBALS (#15627).
                $this->rejectGlobalsReferenceAcquisition($expr->expr);
                // Zend zend_compile.c: &$a?->x / &$a?->m() (#26638).
                $this->rejectNullsafeReferenceAcquisition($expr->expr, $block);
                // Zend zend_compile.c: ref-binding to const/class-const array element (#5409).
                $this->rejectGlobalConstInWriteContext($expr->expr, $block, $expr);
                $bindRefFlags = 0;
                $dimFetch = $this->unwrapArrayDimFetch($expr->expr)
                    ?? $this->findArrayDimFetchForResult($expr->expr, $block);
                $arrayLiteral = null !== $dimFetch
                    ? ($this->unwrapArrayLiteralExpr($dimFetch->var)
                        ?? $this->findArrayExprForResult($dimFetch->var, $block))
                    : null;
                if (null !== $arrayLiteral) {
                    // Zend zend_compile_list_assign: ref target from inline array literal (#3799).
                    $bindRefFlags = 1;
                } elseif (0 !== $this->assignRefBindRefFlags) {
                    $bindRefFlags = $this->assignRefBindRefFlags;
                }
                $ops = [new OpCode(
                    OpCode::TYPE_ASSIGN_REF,
                    $this->compileOperand($expr->var, $block, false),
                    $this->compileOperand($expr->expr, $block, true),
                    $bindRefFlags ?: null
                )];
                if ([] !== $expr->result->usages) {
                    $ops[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $this->compileOperand($expr->result, $block, false),
                        $this->compileOperand($expr->var, $block, false),
                        $this->compileOperand($expr->expr, $block, true)
                    );
                }
                return $ops;
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
