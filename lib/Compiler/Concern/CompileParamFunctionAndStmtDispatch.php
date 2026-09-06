<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ClassConstName;
use PHPCompiler\ClassConstVisibility;
use PHPCompiler\DnfType;
use PHPCompiler\Frame;
use PHPCompiler\GenericArrayTypeSpec;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
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
 * Param / function / stmt dispatch helpers (#36387 / #36147).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code compileParam}
 * through {@code compileStmt} (early-bound FUNCDEF helpers included).
 * Move-only Concern split for gen-0 split-TU hollow — mirrors php-src
 * {@code Zend/zend_compile.c} function/param/stmt dispatch; no new C ABI.
 */
trait CompileParamFunctionAndStmtDispatch
{
    protected function compileParam(Op\Expr\Param $param, Block $block, int $paramIdx): OpCode {
        if ($param->byRef) {
            $block->paramByRef[$paramIdx] = true;
        }
        if ($param->variadic) {
            assert(null === $param->defaultVar);
            if (null !== $block->variadicParamIndex) {
                $this->throwCompileLogic('Only one variadic parameter is allowed per function');
            }
            $block->variadicParamIndex = $paramIdx;
        }
        $defaultConst = $this->resolvePropertyOrParamDefaultSlot($param, $block, $paramIdx);
        $slot = $this->compileOperand($param->result, $block, false);
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $block->paramNames[$paramIdx] = $param->name->value;
        }
        if (AttributeNames::isSensitiveParameter(AttributeNames::fromOp($param))) {
            $block->paramSensitive[$paramIdx] = true;
        }
        $defaultConstantName = $this->paramDefaultConstantName($param);
        if (null !== $defaultConstantName) {
            $block->paramDefaultConstantNames[$paramIdx] = $defaultConstantName;
        }
        $this->applyParamDeclaredType($param, $block, $slot, $param->variadic);
        $this->assertParamDefaultMatchesDeclaredType($param, $defaultConst, $block);
        if ($this->paramIsImplicitNullable($param, $defaultConst, $block)) {
            $block->paramImplicitNullable[$slot] = true;
        }
        $this->maybeEmitImplicitNullableParamDeprecation($param, $defaultConst, $block);

        $recv = new OpCode(
            OpCode::TYPE_ARG_RECV,
            $slot,
            $paramIdx,
            $defaultConst
        );
        // Param declaration line for user-arg TypeError Exception::$line (#29023).
        $recv->sourceLocation = SourceLocation::fromOp($param);

        return $recv;
    }

    /**
     * Constant-fetch default name for ReflectionParameter (#22026, zim_reflection_parameter_*).
     * true/false/null and ::class are not constant defaults (php-src).
     * self::/parent:: keep source spelling (#31149, ext/reflection/php_reflection.c).
     */
    protected function paramDefaultConstantName(Op\Expr\Param $param): ?string
    {
        $expr = $this->paramDefaultConstFetchExpr($param);
        if ($expr instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($expr->name);
            if (null === $name || '' === $name) {
                return null;
            }
            $name = ltrim($name, '\\');
            $lc = strtolower($name);
            if ('true' === $lc || 'false' === $lc || 'null' === $lc) {
                return null;
            }

            return $name;
        }
        if ($expr instanceof Op\Expr\ClassConstFetch) {
            $constName = $this->staticNameFromOperand($expr->name);
            $className = $this->staticNameFromOperand($expr->class);
            if (null === $constName || null === $className || '' === $constName || '' === $className) {
                return null;
            }
            if ('class' === strtolower($constName)) {
                return null;
            }
            $className = ltrim($className, '\\');
            $lcClass = strtolower($className);
            if ('self' === $lcClass || 'parent' === $lcClass || 'static' === $lcClass) {
                return $className.'::'.$constName;
            }
            $scopeKeyword = $expr->getAttribute('phpcLexicalScopeKeyword');
            if (is_string($scopeKeyword) && '' !== $scopeKeyword) {
                return $scopeKeyword.'::'.$constName;
            }

            return $className.'::'.$constName;
        }

        return null;
    }

    /**
     * @return Op\Expr\ConstFetch|Op\Expr\ClassConstFetch|null
     */
    protected function paramDefaultConstFetchExpr(Op\Expr\Param $param): ?Op\Expr
    {
        if (null !== $param->defaultBlock && [] !== $param->defaultBlock->children) {
            $last = $param->defaultBlock->children[\count($param->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\ConstFetch || $last instanceof Op\Expr\ClassConstFetch) {
                return $last;
            }
            if ($last instanceof Op\Expr\Assign
                && ($last->expr instanceof Op\Expr\ConstFetch || $last->expr instanceof Op\Expr\ClassConstFetch)
            ) {
                return $last->expr;
            }
        }
        $unwrapped = null !== $param->defaultVar ? $this->unwrapOperandChain($param->defaultVar) : null;
        if ($unwrapped instanceof Op\Expr\ConstFetch || $unwrapped instanceof Op\Expr\ClassConstFetch) {
            return $unwrapped;
        }

        return null;
    }

    protected function compileFunction(Op\Stmt\Function_ $function, Block $block): OpCode {
        $funcBlock = $this->compileCfgBlock($function->func->cfg, $function->func->params, $function->func);
        // Decl-scope link for compile-time call-site traces (callable $c — not a CFG parent; #13686).
        $funcBlock->enclosingDeclBlocks[] = $block;
        NoDiscardMetadata::applyToBlock($funcBlock, $function);
        DeprecatedMetadata::applyToBlock($funcBlock, $function);
        $this->markGeneratorIfNeeded($function, $funcBlock);
        $funcLc = strtolower($function->func->name);
        if ($this->funcDeclReturnTypeIsNever($function->func)) {
            $this->neverFunctionNames[$funcLc] = true;
        }
        foreach ($function->func->params as $paramIdx => $param) {
            if ($param->byRef) {
                $this->userFunctionParamByRef[$funcLc][(int) $paramIdx] = true;
            }
        }
        $operand = new Operand\Literal($function->func->name);
        $operand->type = Type::string();
        $return = new OpCode(
            OpCode::TYPE_FUNCDEF,
            $this->compileOperand($operand, $block, true)
        );
        $return->block1 = $funcBlock;
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($function);
        $return->parameterMetadata = $this->parameterMetadataFromParams($function->func->params, $function->func);
        $this->assignAttributeMetadata($return, $function);
        $this->assignSourceMetadata($return, $function);
        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($return->attributeNames, 'function', $return->attributeEntries);
        AttributeNames::assertAttributeMetaClassTargetOnly($return->attributeNames, 'function', $return->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'function', $return->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'function', $return->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($return->attributeNames, 'function', $return->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($return->attributeNames, 'function', $return->attributeEntries);
        return $return;
    }

    /**
     * Emit FUNCDEF for Zend early-bound file-level decls at {main} entry (#24807).
     *
     * Declarations nested in if/else/try/catch/finally/switch/loop stay at their CFG site
     * (runtime registration when that path runs). Merge blocks after try/if that hold a
     * top-level `function` are early-bound — matching zend_compile.c for non-conditional decls.
     *
     * php-cfg's ClassMethod extends Function_, and ClassLike::getSubBlocks() exposes the
     * class stmts block — so a naive instanceof Function_ walk also picks up interface/abstract
     * methods (null cfg) and concrete methods. Exact-class match only; methods stay on the
     * DECLARE_METHOD path (#24836 TypeError / prior SIGSEGV on ext/spl spine chunks).
     */
    private function emitEarlyBoundFunctionDefs(CfgBlock $entry, Block $dest): void
    {
        $delayed = $this->collectDelayedDeclarationCfgBlocks($entry);
        /** @var list<Op\Stmt\Function_> $funcs */
        $funcs = [];
        $seenBlocks = new SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $cfg = array_shift($queue);
            if ($seenBlocks->contains($cfg)) {
                continue;
            }
            $seenBlocks[$cfg] = true;
            foreach ($cfg->children as $child) {
                // ClassMethod extends Function_ — do not early-bind methods as FUNCDEF (#24836).
                if (
                    Op\Stmt\Function_::class === \get_class($child)
                    && !$delayed->contains($cfg)
                ) {
                    $funcs[] = $child;
                }
                // Do not walk into class/interface/trait/enum bodies — only file-level decls.
                if ($child instanceof Op\Stmt\ClassLike) {
                    continue;
                }
                foreach ($this->cfgOpSuccessorBlocks($child) as $succ) {
                    $queue[] = $succ;
                }
            }
        }
        usort(
            $funcs,
            static function (Op\Stmt\Function_ $a, Op\Stmt\Function_ $b): int {
                return ((int) ($a->getAttribute('startLine') ?? 0))
                    <=> ((int) ($b->getAttribute('startLine') ?? 0));
            }
        );
        /** @var array<string, array{file: string, line: int}> $declared */
        $declared = [];
        foreach ($funcs as $fn) {
            $name = $fn->func->name;
            if (is_string($name) && '' !== $name) {
                $lc = strtolower($name);
                if (isset($declared[$lc])) {
                    $prev = $declared[$lc];
                    $this->throwCompileError(
                        ('' !== $prev['file'] && 'unknown' !== $prev['file'] && $prev['line'] > 0)
                            ? sprintf(
                                'Cannot redeclare %s() (previously declared in %s:%d)',
                                $name,
                                $prev['file'],
                                $prev['line']
                            )
                            : sprintf('Cannot redeclare %s()', $name),
                        $fn->getFile(),
                        max(1, $fn->getLine())
                    );
                }
                $declared[$lc] = [
                    'file' => $fn->getFile(),
                    'line' => max(0, $fn->getLine()),
                ];
            }
            $this->earlyBoundFunctionOps[$fn] = true;
            $dest->addOpCode($this->compileFunction($fn, $dest));
        }
    }

    /**
     * CFG blocks that are exclusive bodies of delayed declaration contexts (Zend: not early-bound).
     *
     * @return SplObjectStorage
     */
    private function collectDelayedDeclarationCfgBlocks(CfgBlock $entry): SplObjectStorage
    {
        $delayed = new SplObjectStorage();
        $seenBlocks = new SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $cfg = array_shift($queue);
            if ($seenBlocks->contains($cfg)) {
                continue;
            }
            $seenBlocks[$cfg] = true;
            foreach ($cfg->children as $child) {
                if ($child instanceof Op\Stmt\JumpIf) {
                    $this->markDelayedDeclBlockIfExclusiveArm($delayed, $child->if);
                    $this->markDelayedDeclBlockIfExclusiveArm($delayed, $child->else);
                } elseif ($child instanceof Op\Stmt\TryCatch) {
                    $delayed[$child->try] = true;
                    foreach ($child->catches as $catchBlock) {
                        if ($catchBlock instanceof CfgBlock) {
                            $delayed[$catchBlock] = true;
                        }
                    }
                    if ($child->finally instanceof CfgBlock) {
                        $delayed[$child->finally] = true;
                    }
                    if ($child->else instanceof CfgBlock) {
                        $delayed[$child->else] = true;
                    }
                } elseif ($child instanceof Op\Stmt\Switch_) {
                    foreach ($child->targets as $target) {
                        $this->markDelayedDeclBlockIfExclusiveArm($delayed, $target);
                    }
                    $this->markDelayedDeclBlockIfExclusiveArm($delayed, $child->default);
                }
                foreach ($this->cfgOpSuccessorBlocks($child) as $succ) {
                    $queue[] = $succ;
                }
            }
        }

        return $delayed;
    }

    /**
     * Exclusive branch/case arm (single CFG parent) — not a merge that also continues top-level code.
     *
     * @param SplObjectStorage $delayed
     */
    private function markDelayedDeclBlockIfExclusiveArm(SplObjectStorage $delayed, ?CfgBlock $arm): void
    {
        if (!$arm instanceof CfgBlock) {
            return;
        }
        if (\count($arm->parents) < 2) {
            $delayed[$arm] = true;
        }
    }

    /** @return list<CfgBlock> */
    private function cfgOpSuccessorBlocks(Op $op): array
    {
        if (!method_exists($op, 'getSubBlocks')) {
            return [];
        }
        $out = [];
        foreach ($op->getSubBlocks() as $name) {
            // Gen-0/nikic rejects variable-property syntax — use OpSubBlockAccess (re-#10067 / #24877).
            $val = OpSubBlockAccess::propertyValue($op, $name);
            if ($val instanceof CfgBlock) {
                $out[] = $val;
            } elseif (\is_array($val)) {
                foreach ($val as $block) {
                    if ($block instanceof CfgBlock) {
                        $out[] = $block;
                    }
                }
            }
        }

        return $out;
    }

    protected function compileOp(Op $op, Block $block) {
        if ($op instanceof Op\Expr\ConcatList) {
            $total = count($op->list);
            $return = $this->freshConcatResultSlotIfCatchAlias(
                $this->compileOperand($op->result, $block, false),
                $block,
                $op->result
            );
            if (0 === $total) {
                $empty = new Operand\Literal('');
                $empty->type = Type::string();
                $emptySlot = $this->compileOperand($empty, $block, true);
                $this->addConcatListOpCode($block, new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $return,
                    $return,
                    $emptySlot
                ), $op);
            } elseif (1 === $total) {
                // Zend string context for a lone encapsed variable (#4785) — not a plain assign.
                $part = $this->compileConcatListPart($op->list[0], $block);
                $this->addConcatListOpCode($block, new OpCode(
                    OpCode::TYPE_CAST_STRING,
                    $return,
                    $part
                ), $op);
            } else {
                // Encapsed ConcatList used to emit in-place CONCAT($return, $return, $right).
                // Reusing one dead-temp slot for every link intermittently heap-corrupts under
                // AOT once three or more variables are interpolated (#23842). Match explicit
                // `$a . " " . $b . …`: a fresh destination per link.
                $acc = $this->compileConcatListPart($op->list[0], $block);
                for ($i = 1; $i < $total; $i++) {
                    $right = $this->compileConcatListPart($op->list[$i], $block);
                    $isLast = ($i === $total - 1);
                    if ($isLast) {
                        $dest = $return;
                    } else {
                        $tmp = new Temporary();
                        $dest = $block->forceFreshVarSlot($tmp);
                        $block->orig->deadOperands[] = $tmp;
                    }
                    $this->addConcatListOpCode($block, new OpCode(
                        OpCode::TYPE_CONCAT,
                        $dest,
                        $acc,
                        $right
                    ), $op);
                    $acc = $dest;
                }
            }
        } elseif ($op instanceof Op\Expr) {
            $block->addOpCode(...$this->compileExpr($op, $block));
            if (
                $op instanceof Op\Expr\PropertyFetch
                && !$this->isPropertyFetchForWrite($op, $block)
            ) {
                $this->syncPropertyFetchResultToFollowingFuncCallArg($op, $block);
            }
        } elseif ($op instanceof Op\Stmt) {
            $this->compileStmt($op, $block);
        } elseif ($op instanceof Op\Terminal) {
            $terminalOps = $this->compileTerminal($op, $block);
            foreach ($terminalOps as $terminalOp) {
                $block->addOpCode($terminalOp);
            }
        } else {
            $this->throwCompileLogicForOp($op, 'Unknown Op Type: '.\PHPCompiler\opcode_type_name($op->type));
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block) {
        if ($stmt instanceof Op\Stmt\Jump) {
            $target = $this->compileCfgBranch($stmt->target, $block);
            if (!$this->isRedundantTryEntryJump($block, $target)) {
                $op = new OpCode(OpCode::TYPE_JUMP);
                $op->block1 = $target;
                $block->addOpCode($op);
            }
        } elseif ($stmt instanceof Op\Stmt\JumpIf) {
            if (null !== ($paramOp = $this->nullableParamFromReturnTernaryArms($stmt, $block))) {
                $this->emitImplicitNullableParamCoalesceReturn($paramOp, $block);

                return;
            }
            $rewriteNeNull = $this->rewrittenNeNullReturnJumpIf->contains($stmt);
            // Capture before compiling arms: braced declare attaches LeaveTickInterval to the
            // while/for exit block, which would clear activeTickInterval (#25621).
            $emitLoopExitTick = $this->activeTickInterval > 0
                && $stmt->hasAttribute('zend_loop_exit_tick')
                && $stmt->getAttribute('zend_loop_exit_tick');
            $op = new OpCode(OpCode::TYPE_JUMPIF, $this->compileOperand($stmt->cond, $block, true));
            if ($rewriteNeNull) {
                $op->block1 = $this->compileCfgBranch($stmt->else, $block);
                $op->block2 = $this->compileCfgBranch($stmt->if, $block);
            } elseif ($this->jumpIfTargetsLogicalOrShortCircuitLiteralIf($stmt)) {
                // `||` literal-true arm must reuse bool-cast phi slot from the long arm (#12745).
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
            } elseif ($this->jumpIfTargetsLogicalAndShortCircuitCastIf($stmt)) {
                // `&&` literal-false arm must reuse bool-cast phi from the long (if) arm (#24506).
                // Do not take ternary else-first order — that seeds phi before `$x = …` and aliases slots.
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
            } elseif ($this->jumpIfTargetsTernaryMerge($stmt)) {
                // Lower else before if so merge blocks record both branch phi slots (#3790, #5510).
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
            } else {
                $op->block1 = $this->compileCfgBranch($stmt->if, $block);
                $op->block2 = $this->compileCfgBranch($stmt->else, $block);
            }
            // Zend zend_compile_stmt emits ZEND_TICKS after while/for/do-while on the
            // fallthrough (loop exit) path — php-src Zend/zend_compile.c (#25621).
            if ($emitLoopExitTick) {
                $elseCompiled = $this->seen[$stmt->else] ?? null;
                if ($elseCompiled instanceof Block) {
                    $wrapped = $this->wrapBlockWithLoopExitTick($elseCompiled);
                    if ($op->block1 === $elseCompiled) {
                        $op->block1 = $wrapped;
                    }
                    if ($op->block2 === $elseCompiled) {
                        $op->block2 = $wrapped;
                    }
                }
            }
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\TryCatch) {
            foreach ($stmt->catchVars as $catchVar) {
                $this->assertNoThisAsCatchVariable($catchVar, $stmt);
            }
            // Reserve catch \$e slots before merge lowering allocates sibling temps (#9887).
            $reservedCatchVarSlots = [];
            foreach ($stmt->catches as $i => $catchBlock) {
                $catchVar = $stmt->catchVars[$i] ?? null;
                if (null !== $catchVar && null !== $this->resolveCatchVariableName($catchVar)) {
                    $reservedCatchVarSlots[$i] = $block->getVarSlot($catchVar, false);
                }
            }
            $merge = $this->compileCfgBranch($stmt->end, $block);
            $merge = $this->splitMergeBeforeNestedTry($merge);
            // Merge block is entered via TYPE_CATCH before catch locals exist (#195, #2084).
            $merge->inheritUndefinedLocals = true;
            // Lower catch bodies before try so sibling ?: merge prebind cannot clobber try locals (#6411).
            $compiledCatches = [];
            $savedCatchVarSlots = $this->activeCatchVarSlotsByName;
            $savedCatchVarRoots = $this->activeCatchVarRoots;
            $this->activeCatchVarSlotsByName = [];
            $this->activeCatchVarRoots = [];
            foreach ($stmt->catches as $i => $catchBlock) {
                $catchVar = $stmt->catchVars[$i] ?? null;
                $catchName = $this->resolveCatchVariableName($catchVar);
                if (null !== $catchName && isset($reservedCatchVarSlots[$i])) {
                    $lc = strtolower($catchName);
                    $this->activeCatchVarSlotsByName[$lc] = $reservedCatchVarSlots[$i];
                    $root = Block::cfgVarRoot($catchVar);
                    if (null !== $root) {
                        $this->activeCatchVarRoots[] = $root;
                    }
                }
            }
            foreach ($stmt->catches as $i => $catchBlock) {
                $compiledCatch = $this->compileCfgBranch($catchBlock, $block);
                $compiledCatch->inheritUndefinedLocals = true;
                $compiledCatches[] = $compiledCatch;
            }
            $this->activeCatchVarSlotsByName = $savedCatchVarSlots;
            $this->activeCatchVarRoots = $savedCatchVarRoots;
            $try = $this->compileCfgBranch($stmt->try, $block);
            $try->inheritUndefinedLocals = true;
            $tryOp = new OpCode(OpCode::TYPE_TRY);
            $tryOp->block1 = $try;
            $tryOp->block2 = $merge;
            $block->addOpCode($tryOp);
            foreach ($compiledCatches as $i => $compiledCatch) {
                $catchOp = new OpCode(OpCode::TYPE_CATCH);
                $catchOp->block1 = $compiledCatch;
                $catchOp->block2 = $merge;
                $catchOp->catchTypes = $this->encodeCatchTypeList($stmt->catchTypes[$i] ?? []);
                $catchOp->arg3 = $this->resolveCatchVarSlot(
                    $compiledCatch,
                    $stmt->catchVars[$i] ?? null
                ) ?? ($reservedCatchVarSlots[$i] ?? null);
                $block->addOpCode($catchOp);
            }
            if (null !== $stmt->finally) {
                $compiledFinally = $this->compileCfgBranch($stmt->finally, $block);
                // Catch runs before finally on throw; finally block must not inherit catch locals (#195).
                $compiledFinally->inheritUndefinedLocals = true;
                $finallyOp = new OpCode(OpCode::TYPE_FINALLY);
                $finallyOp->block1 = $compiledFinally;
                $finallyOp->block2 = $merge;
                $block->addOpCode($finallyOp);
                $this->rewriteMergeJumpsToFinally($try, $merge, $compiledFinally);
                if (property_exists($stmt, 'else')
                    && null !== $stmt->else
                    && isset($this->seen[$stmt->else])) {
                    $this->rewriteMergeJumpsToFinally($this->seen[$stmt->else], $merge, $compiledFinally);
                }
            }
        } elseif ($stmt instanceof Op\Stmt\Switch_) {
            $this->compileSwitchAsJumpIfChain($stmt, $block);
        } elseif ($stmt instanceof Op\Stmt\HaltCompiler) {
            if (null === $this->haltCompilerRemaining) {
                $this->haltCompilerRemaining = $stmt->remaining;
                $this->haltCompilerOffset = $stmt->haltOffset;
            }
            $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        } else {
            $this->throwCompileLogicForOp($stmt, 'Unknown Stmt Type: '.$stmt->getType());
        }
    }
}
