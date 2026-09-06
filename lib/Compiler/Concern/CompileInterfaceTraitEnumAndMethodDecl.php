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
 * Interface / trait / enum declaration + class-method / param / attribute metadata (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code compileInterface} through
 * {@code registerAttributeClassFromEntries}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_class_decl, zend_compile_func_decl),
 * Zend/zend_inheritance.c — move-only Concern extract; no new C ABI.
 */
trait CompileInterfaceTraitEnumAndMethodDecl
{
    protected function compileInterface(Op\Stmt\Interface_ $iface, Block $block): OpCode
    {
        $name = $this->staticNameFromOperand($iface->name);
        if (null === $name) {
            $this->throwCompileError('Interface name must be a compile-time class reference');
        }
        $this->assertNotReservedClassName($name, $iface);
        $extends = $this->interfaceNamesFromOperands($iface->extends);
        $this->classCompileRegistry->registerInterface($name, $extends, $iface->stmts);

        $return = new OpCode(
            OpCode::TYPE_DECLARE_INTERFACE,
            $this->compileOperand($iface->name, $block, true)
        );
        $this->assignSourceMetadata($return, $iface);
        $this->assignAttributeMetadata($return, $iface);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($return->attributeNames, 'class', $return->attributeEntries);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($iface);
        AttributeNames::assertDeprecatedAllowedOnClassLike(
            $return->attributeNames,
            $return->deprecatedMetadata,
            'interface',
            $name,
            $return->attributeEntries
        );
        AttributeNames::assertAttributeMetaOnConcreteClassLike(
            $return->attributeEntries,
            'interface',
            $name
        );
        $this->registerAttributeClassFromEntries($name, $return->attributeEntries);
        $return->classImplements = $extends;
        $this->applySealedMetadataFromOp($iface, $return);
        $return->block1 = $this->compileClassBody(
            $iface->stmts,
            OpCode::TYPE_DECLARE_INTERFACE,
            $this->staticNameFromOperand($iface->name)
        );

        return $return;
    }

    protected function compileTrait(Op\Stmt\Trait_ $trait, Block $block): OpCode
    {
        $name = $this->staticNameFromOperand($trait->name);
        if (null === $name) {
            $this->throwCompileError('Trait name must be a compile-time class reference');
        }
        $this->assertNotReservedClassName($name, $trait);
        $this->classCompileRegistry->registerTrait($name, $trait->stmts);

        $return = new OpCode(
            OpCode::TYPE_DECLARE_TRAIT,
            $this->compileOperand($trait->name, $block, true)
        );
        $this->assignSourceMetadata($return, $trait);
        $this->assignAttributeMetadata($return, $trait);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($return->attributeNames, 'class', $return->attributeEntries);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($trait);
        AttributeNames::assertDeprecatedAllowedOnClassLike(
            $return->attributeNames,
            $return->deprecatedMetadata,
            'trait',
            $name,
            $return->attributeEntries
        );
        AttributeNames::assertAttributeMetaOnConcreteClassLike(
            $return->attributeEntries,
            'trait',
            $name
        );
        $this->registerAttributeClassFromEntries($name, $return->attributeEntries);
        $traitLc = strtolower(ltrim($name, '\\'));
        $this->compiledClassStaticProperties[$traitLc] = $this->compiledClassStaticProperties[$traitLc] ?? [];
        $prevClassStaticCompile = $this->currentClassStaticPropertyCompile;
        $this->currentClassStaticPropertyCompile = $traitLc;
        $return->block1 = $this->compileClassBody(
            $trait->stmts,
            OpCode::TYPE_DECLARE_TRAIT,
            $this->staticNameFromOperand($trait->name)
        );
        $this->currentClassStaticPropertyCompile = $prevClassStaticCompile;

        return $return;
    }

    protected function compileEnum(Op\Stmt\Enum_ $enum, Block $block): OpCode
    {
        $enumName = $this->staticNameFromOperand($enum->name);
        if (null !== $enumName) {
            $this->assertNotReservedClassName($enumName, $enum);
        }
        $backedTypeSlot = null;
        if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
            $backedVar = new Variable(Variable::TYPE_STRING);
            $backedVar->string($enum->backedType->name);
            $backedOperand = new Operand\Temporary;
            $backedOperand->type = Type::string();
            $backedTypeSlot = $block->registerConstant($backedOperand, $backedVar);
        }
        $return = new OpCode(
            OpCode::TYPE_DECLARE_ENUM,
            $this->compileOperand($enum->name, $block, true),
            $backedTypeSlot
        );
        $this->assignAttributeMetadata($return, $enum);
        $this->assignSourceMetadata($return, $enum);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($enum);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($return->attributeNames, 'class', $return->attributeEntries);
        if (null !== $enumName) {
            AttributeNames::assertDeprecatedAllowedOnClassLike(
                $return->attributeNames,
                $return->deprecatedMetadata,
                'enum',
                $enumName,
                $return->attributeEntries
            );
            AttributeNames::assertAllowDynamicPropertiesNotOnEnum($return->attributeNames, $enumName);
            AttributeNames::assertAttributeMetaOnConcreteClassLike(
                $return->attributeEntries,
                'enum',
                $enumName
            );
            $this->registerAttributeClassFromEntries($enumName, $return->attributeEntries);
        }
        [$enumIfaceLcs, $enumIfaceDisplays] = $this->interfaceLcAndDisplayFromOperands($enum->implements);
        $return->classImplements = $enumIfaceLcs;
        $return->classImplementsDisplay = $enumIfaceDisplays;
        $return->classIsAbstract = \PHPCompiler\VM\ClassAbstract::fromClassFlags($enum->flags ?? 0);
        if (null !== $enumName) {
            $enumLc = strtolower(ltrim($enumName, '\\'));
            $backedTypeName = null;
            if (null !== $enum->backedType && $enum->backedType instanceof Op\Type\Literal) {
                $backedTypeName = $enum->backedType->name;
            }
            $this->compileTimeEnumBackedTypes[$enumLc] = $backedTypeName;
        }
        $return->block1 = $this->compileEnumBody($enum->stmts, $enumName);

        return $return;
    }

    protected function compileEnumBody(CfgBlock $block, ?string $enumName = null): Block
    {
        $result = new Block($block);
        $prevClassLc = $this->compilingClassLc;
        $prevClassDisplayName = $this->compilingClassDisplayName;
        $prevInstancePropertyNames = $this->compilingClassInstancePropertyNames;
        $prevMethodNames = $this->compilingClassMethodNames;
        $this->compilingClassInstancePropertyNames = [];
        $this->compilingClassMethodNames = [];
        if (null !== $enumName) {
            $this->compilingClassLc = strtolower(ltrim($enumName, '\\'));
            $this->compilingClassDisplayName = ltrim($enumName, '\\');
            if (!isset($this->compileTimeClassConsts[$this->compilingClassLc])) {
                $this->compileTimeClassConsts[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstVisibility[$this->compilingClassLc])) {
                $this->compileTimeClassConstVisibility[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstDeprecated[$this->compilingClassLc])) {
                $this->compileTimeClassConstDeprecated[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeEnumBackedTypes[$this->compilingClassLc])) {
                $this->compileTimeEnumBackedTypes[$this->compilingClassLc] = null;
            }
            if (!isset($this->compileTimeEnumCaseConstNames[$this->compilingClassLc])) {
                $this->compileTimeEnumCaseConstNames[$this->compilingClassLc] = [];
            }
        } else {
            $this->compilingClassDisplayName = null;
        }
        foreach ($block->children as $child) {
            if ($child instanceof Op\Terminal\Const_) {
                $this->compileClassConstDeclaration($child, $result);
                continue;
            }
            if ($child instanceof Op\Stmt\TraitUse) {
                foreach ($child->traits as $traitOperand) {
                    $result->addOpCode(new OpCode(
                        OpCode::TYPE_USE_TRAIT,
                        $this->compileOperand($traitOperand, $result, true)
                    ));
                }
                $adaptOp = new OpCode(OpCode::TYPE_TRAIT_USE_ADAPTATION);
                $adaptOp->traitAdaptations = [] !== $child->adaptations
                    ? $this->compileTraitAdaptations($child->adaptations)
                    : [];
                $result->addOpCode($adaptOp);
                continue;
            }
            if ($child instanceof Op\Stmt\ClassMethod) {
                $this->compileClassMethodDeclaration($child, $result);

                continue;
            }
            $this->throwCompileLogic('Unsupported enum body element: '.get_class($child));
        }
        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevClassDisplayName;
        $this->compilingClassInstancePropertyNames = $prevInstancePropertyNames;
        $this->compilingClassMethodNames = $prevMethodNames;

        return $result;
    }

    protected function compileClassMethodDeclaration(Op\Stmt\ClassMethod $child, Block $result, ?int $declaringType = null): void
    {
        $this->registerMethodDeclaration($child->func->name);
        // php-src Zend/zend_compile.c — duplicate params fatal before property promotion
        // registers (`Redefinition of parameter $name`, not `Cannot redeclare Class::$name`) (#29979).
        $this->assertNoDuplicateParameterNames($child->func->params);
        // Abstract/interface methods skip compileCfgBlock — still reject `$this` as a param
        // (zend_compile_params, #32179).
        $this->assertNoThisAsParameter($child->func->params);
        foreach ($child->func->params as $param) {
            $methodBlock = new Block(null);
            $methodBlock->func = $child->func;
            $this->assertParamDeclaredType($param->declaredType, $methodBlock, $child->func);
        }
        if ('__construct' === $child->func->name) {
            // php-src Zend/zend_compile.c — promotion requires a concrete constructor body (#26529).
            $abstractCtor = OpCode::TYPE_DECLARE_INTERFACE === $declaringType
                || 0 !== ($child->func->flags & CfgFunc::FLAG_ABSTRACT);
            foreach ($child->func->params as $param) {
                if ($this->isPromotedParam($param)) {
                    if ($abstractCtor) {
                        $sourceFile = $child->getFile();
                        if ('' === $sourceFile) {
                            $sourceFile = 'unknown';
                        }
                        throw new CompileFatal(
                            $sourceFile,
                            max(1, $child->getLine()),
                            AbstractPromotedPropertyCompileCheck::MESSAGE
                        );
                    }
                    $this->compilePromotedPropertyDeclaration($param, $result);
                }
            }
        }
        $methodName = new Operand\Literal($child->func->name);
        $methodName->type = Type::string();
        $visVar = new Variable(Variable::TYPE_INTEGER);
        $visFlags = MethodVisibility::mask($child->func->flags);
        if (($child->func->flags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            $visFlags |= \PHPCfg\Func::FLAG_STATIC;
        }
        if (($child->func->flags & CfgFunc::FLAG_FINAL) !== 0) {
            $visFlags |= CfgFunc::FLAG_FINAL;
        }
        $visVar->int($visFlags);
        $visOperand = new Operand\Temporary;
        $visOperand->type = Type::int();
        $visIdx = $result->registerConstant($visOperand, $visVar);
        $methodLine = max(0, $child->getLine());
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_METHOD,
            $this->compileOperand($methodName, $result, true),
            $methodLine > 0 ? $methodLine : null,
            $visIdx
        );
        if (null !== $child->func->cfg) {
            $methodBlock = $this->compileCfgBlock($child->func->cfg, $child->func->params, $child->func);
            NoDiscardMetadata::applyToBlock($methodBlock, $child);
            DeprecatedMetadata::applyToBlock($methodBlock, $child);
            $this->markGeneratorIfNeeded($child, $methodBlock);
            $declare->block1 = $methodBlock;
        } else {
            // Abstract/interface methods skip compileCfgBlock — still emit optional-before-required
            // E_DEPRECATED (zend_compile_params, #31904).
            $diag = new Block(null);
            $diag->func = $child->func;
            $this->maybeEmitOptionalBeforeRequiredParamDeprecations($child->func->params, $diag);
        }
        // null cfg: abstract / interface methods — NonAbstractMethodBodyCheck rejects
        // non-abstract class/trait/enum `function f();` (#24906). Empty `{}` has cfg.
        $this->assignAttributeMetadata($declare, $child);
        $this->assignSourceMetadata($declare, $child);
        AttributeNames::assertAllowDynamicPropertiesClassTargetOnly($declare->attributeNames, 'method', $declare->attributeEntries);
        AttributeNames::assertAttributeMetaClassTargetOnly($declare->attributeNames, 'method', $declare->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'method', $declare->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'method', $declare->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($declare->attributeNames, 'method', $declare->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($declare->attributeNames, 'method', $declare->attributeEntries);
        $declare->parameterMetadata = $this->parameterMetadataFromParams($child->func->params, $child->func);
        // Abstract/interface methods have no method body block — keep return AST for cross-file LSP (#25384).
        // Untyped `__toString` is `: string` for Reflection / LSP (zend_compile.c, #26402).
        $declare->returnDeclaredType = $this->implicitToStringReturnType($child->func)
            ?? $child->func->returnType;
        $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
        $result->addOpCode($declare);
    }

    /**
     * @param list<Op\Expr\Param> $params
     *
     * @return list<ParameterMetadata>
     */
    protected function parameterMetadataFromParams(array $params, ?CfgFunc $func = null): array
    {
        return AttributeConstantEvaluator::withUserlandConstContext(
            $this->userlandConstScalarsForAttributes(),
            $this->namespaceHintFromFunc($func),
            function () use ($params): array {
                $metadata = [];
                foreach ($params as $param) {
                    if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                        continue;
                    }
                    $isVariadic = (bool) $param->variadic;
                    $hasDefault = null !== $param->defaultVar || null !== $param->defaultBlock;
                    $metadata[] = new ParameterMetadata(
                        $param->name->value,
                        AttributeMetadata::fromOp($param),
                        $this->isPromotedParam($param),
                        $isVariadic || $hasDefault,
                        $isVariadic,
                        (bool) $param->byRef,
                        $this->parameterTypeStringForDump($param),
                        $this->parameterDefaultExportForDump($param),
                    );
                }

                return $metadata;
            }
        );
    }

    /**
     * Zend _function_string / parameter dump type label (#22522).
     *
     * Implicit nullable (`string $s = null`) dumps as `?string` for Reflection metadata (#26469).
     */
    protected function parameterTypeStringForDump(Op\Expr\Param $param): ?string
    {
        $type = $param->declaredType ?? null;
        if (null === $type || $type instanceof Op\Type\Mixed_) {
            return null;
        }
        if (
            !($type instanceof Op\Type\Nullable)
            && !$this->cfgTypeUsesDnfShape($type)
            && $this->paramAstDefaultIsNull($param)
        ) {
            $type = new Op\Type\Nullable($type);
        }

        return ReflectionTypeSupport::cfgTypeStringForDump($type);
    }

    /** AST-side `= null` default (literal null / null const) for Reflection dump labels (#26469). */
    protected function paramAstDefaultIsNull(Op\Expr\Param $param): bool
    {
        if ($param->defaultVar instanceof NullOperand) {
            return true;
        }
        if (null === $param->defaultVar) {
            return false;
        }
        $unwrapped = $this->unwrapOperandChain($param->defaultVar);
        if ($unwrapped instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($unwrapped->name);
            if (null !== $name && 'null' === strtolower(ltrim($name, '\\'))) {
                return true;
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($param->defaultVar);

        return null !== $vm && Variable::TYPE_NULL === $vm->type;
    }

    /**
     * Zend parameter default literal for Reflection*::__toString (#22522).
     */
    protected function parameterDefaultExportForDump(Op\Expr\Param $param): ?string
    {
        if ($param->variadic || (null === $param->defaultVar && null === $param->defaultBlock)) {
            return null;
        }
        if ($param->defaultVar instanceof NullOperand) {
            return 'NULL';
        }
        if (null === $param->defaultVar) {
            return null;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($param->defaultVar);
        if (null === $vm) {
            $unwrapped = $this->unwrapOperandChain($param->defaultVar);
            if ($unwrapped instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($unwrapped->name);
                if (null !== $name) {
                    $lc = strtolower(ltrim($name, '\\'));
                    if ('null' === $lc) {
                        return 'NULL';
                    }
                    if ('true' === $lc) {
                        return 'true';
                    }
                    if ('false' === $lc) {
                        return 'false';
                    }
                }
            }

            return null;
        }
        $vm = $vm->resolveIndirect();
        switch ($vm->type) {
            case Variable::TYPE_NULL:
                return 'NULL';
            case Variable::TYPE_BOOLEAN:
                return $vm->toBool() ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $vm->toInt();
            case Variable::TYPE_FLOAT:
                return (string) $vm->toFloat();
            case Variable::TYPE_STRING:
                return var_export($vm->toString(), true);
            default:
                return null;
        }
    }

    protected function assignAttributeMetadata(OpCode $op, Op $cfgOp): void
    {
        $entries = AttributeConstantEvaluator::withUserlandConstContext(
            $this->userlandConstScalarsForAttributes(),
            $this->namespaceHintFromCfgOp($cfgOp),
            static fn (): array => AttributeMetadata::fromOp($cfgOp)
        );
        $op->attributeEntries = AttributeNames::validateDuplicates($entries, $this->attributeClassRegistry);
        $op->attributeNames = AttributeEntry::namesFromList($op->attributeEntries);
    }

    /**
     * Scalar map of file/namespace consts for attribute ConstFetch folding (#26628).
     *
     * @return array<string, mixed>
     */
    private function userlandConstScalarsForAttributes(): array
    {
        $out = [];
        foreach ($this->compileTimeGlobalConsts as $lc => $var) {
            try {
                $out[$lc] = AttributeConstantEvaluator::phpScalarFromVariable($var);
            } catch (\LogicException $e) {
                // Non-scalar compile-time values cannot appear in attribute args.
            }
        }

        return $out;
    }

    /** Declaring namespace for relative ConstFetch in attribute args (#26628). */
    private function namespaceHintFromCfgOp(Op $cfgOp): string
    {
        if ($cfgOp instanceof Op\Stmt\Function_) {
            return $this->namespaceHintFromFunc($cfgOp->func);
        }
        if ($cfgOp instanceof Op\Stmt\ClassMethod) {
            return $this->namespaceHintFromFunc($cfgOp->func);
        }
        if ($cfgOp instanceof Op\Stmt\ClassLike) {
            $name = $this->staticNameFromOperand($cfgOp->name);

            return null !== $name ? $this->namespaceFromFqcn($name) : '';
        }
        if ($cfgOp instanceof Op\Terminal\Const_) {
            $name = $this->staticNameFromOperand($cfgOp->name);

            return null !== $name ? $this->namespaceFromFqcn($name) : '';
        }
        if ($cfgOp instanceof Op\Expr\Param || $cfgOp instanceof Op\Expr\Closure || $cfgOp instanceof Op\Expr\ArrowFunction) {
            if (null !== $this->compilingClassDisplayName && '' !== $this->compilingClassDisplayName) {
                return $this->namespaceFromFqcn($this->compilingClassDisplayName);
            }
        }
        if (null !== $this->compilingClassDisplayName && '' !== $this->compilingClassDisplayName) {
            return $this->namespaceFromFqcn($this->compilingClassDisplayName);
        }

        return '';
    }

    private function namespaceHintFromFunc(?CfgFunc $func): string
    {
        if (null === $func) {
            if (null !== $this->compilingClassDisplayName && '' !== $this->compilingClassDisplayName) {
                return $this->namespaceFromFqcn($this->compilingClassDisplayName);
            }

            return '';
        }
        $className = $this->funcClassNameString($func);
        if (null !== $className && '' !== $className) {
            return $this->namespaceFromFqcn($className);
        }
        if ('{main}' === $func->name || '' === $func->name) {
            return '';
        }

        return $this->namespaceFromFqcn($func->name);
    }

    private function funcClassNameString(CfgFunc $func): ?string
    {
        if (!isset($func->class) || null === $func->class) {
            return null;
        }
        if (is_string($func->class)) {
            return $func->class;
        }
        if ($func->class instanceof Operand\Literal && is_string($func->class->value)) {
            return $func->class->value;
        }
        if ($func->class instanceof Operand) {
            return $this->staticNameFromOperand($func->class);
        }

        return null;
    }

    private function namespaceFromFqcn(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos = strrpos($fqcn, '\\');
        if (false === $pos) {
            return '';
        }

        return substr($fqcn, 0, $pos);
    }

    protected function assignSourceMetadata(OpCode $op, Op $cfgOp): void
    {
        $op->sourceLocation = SourceLocation::fromOp($cfgOp);
    }

    /**
     * @param list<AttributeEntry> $selfEntries
     */
    protected function registerAttributeClassFromEntries(string $className, array $selfEntries): void
    {
        $this->attributeClassRegistry->registerAttributeClass($className, $selfEntries);
    }

}
