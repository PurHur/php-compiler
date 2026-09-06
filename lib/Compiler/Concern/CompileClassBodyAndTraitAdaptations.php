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
 * Class-like / function / stmt compilation helpers (#36403 / #36230 / #36387).
 *
 * Interface/trait/enum declaration + method/param/attribute metadata live in
 * {@see CompileInterfaceTraitEnumAndMethodDecl}. Class-const fold / typed rejects live in
 * {@see CompileClassConstFoldAndTypedReject}; promoted property / param defaults live in
 * {@see CompilePromotedPropertyAndParamDefaults}. Param/function/stmt dispatch lives in
 * {@see CompileParamFunctionAndStmtDispatch}. Extracted from {@see \PHPCompiler\Compiler}
 * behind the opcode-corpus-md5 gate. Visibility stays protected/private so LintCompiler
 * and call sites are unchanged.
 */
/**
 * Class body property/method/const/trait-use compile and trait adaptations (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code compileClassBody}
 * through {@code compileTraitAdaptations}. Move-only — mirrors php-src
 * {@code Zend/zend_compile.c} class member compile; no new C ABI.
 */
trait CompileClassBodyAndTraitAdaptations
{
    protected function compileClassBody(CfgBlock $block, int $type, ?string $className = null): Block {
        $result = new Block($block);
        $prevClassLc = $this->compilingClassLc;
        $prevClassDisplayName = $this->compilingClassDisplayName;
        $prevInstancePropertyNames = $this->compilingClassInstancePropertyNames;
        $prevMethodNames = $this->compilingClassMethodNames;
        $this->compilingClassInstancePropertyNames = [];
        $this->compilingClassMethodNames = [];
        if (null !== $className) {
            $this->compilingClassLc = strtolower(ltrim($className, '\\'));
            $this->compilingClassDisplayName = ltrim($className, '\\');
            if (!isset($this->compileTimeClassConsts[$this->compilingClassLc])) {
                $this->compileTimeClassConsts[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstVisibility[$this->compilingClassLc])) {
                $this->compileTimeClassConstVisibility[$this->compilingClassLc] = [];
            }
            if (!isset($this->compileTimeClassConstDeprecated[$this->compilingClassLc])) {
                $this->compileTimeClassConstDeprecated[$this->compilingClassLc] = [];
            }
        } else {
            $this->compilingClassDisplayName = null;
        }
        foreach ($block->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Property::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_INTERFACE !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Properties are only supported on classes, interfaces, and traits for now');
                    }
                    if (OpCode::TYPE_DECLARE_INTERFACE === $type) {
                        if ($child->static && !$this->interfaceStaticPropertyHookAllowed($child->name)) {
                            $this->throwCompileLogic('Interfaces cannot declare static properties');
                        }
                        if (!is_null($child->defaultBlock) || null !== $child->defaultVar) {
                            $this->throwCompileLogic('Interface properties cannot have default values');
                        }
                    }
                    if (
                        OpCode::TYPE_DECLARE_CLASS === $type
                        && !$child->static
                        && $child->name instanceof Operand\Literal
                        && is_string($child->name->value)
                    ) {
                        $this->registerInstancePropertyDeclaration($child->name->value);
                    }
                    $propName = '?';
                    if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                        $propName = $child->name->value;
                    }
                    $this->assertPropertyDeclaredType($child->declaredType, $propName);
                    $propertyDeclName = $this->declNameFromCfgType($child->declaredType);
                    $declared = null !== $propertyDeclName
                        ? Type::fromDecl($propertyDeclName)
                        : $this->typeFromPropertyDecl($child);
                    if ($child->static && null !== $this->currentClassStaticPropertyCompile) {
                        $staticPropName = $this->staticNameFromOperand($child->name);
                        if (null !== $staticPropName) {
                            $this->compiledClassStaticProperties[$this->currentClassStaticPropertyCompile][strtolower($staticPropName)] = true;
                        }
                    }
                    $declareType = $child->static
                        ? OpCode::TYPE_DECLARE_STATIC_PROPERTY
                        : OpCode::TYPE_DECLARE_PROPERTY;
                    $defaultSlot = null;
                    if (null !== $child->defaultVar) {
                        $defaultSlot = $this->tryFoldPropertyDefaultSlot($child, $result);
                        if (null === $defaultSlot) {
                            if (null !== $child->defaultBlock) {
                                $this->compileDefaultBlockChildrenWithProducerCfg($child->defaultBlock, $result);
                            }
                            $defaultSlot = $this->compileOperand($child->defaultVar, $result, true);
                            if (!isset($result->constants[$defaultSlot])) {
                                if ($this->propertyDefaultIsRuntimeNew($child)) {
                                    // Per-instance `new` defaults: opcodes precede DECLARE_*; VM init at TYPE_NEW (#3391).
                                    $defaultSlot = null;
                                } else {
                                    $staticClassMsg = $this->propertyDefaultStaticClassRejectMessage($child);
                                    if (null !== $staticClassMsg) {
                                        $sourceFile = $child->getFile();
                                        if ('' === $sourceFile) {
                                            $sourceFile = 'unknown';
                                        }
                                        throw new CompileFatal(
                                            $sourceFile,
                                            max(1, $child->getLine()),
                                            $staticClassMsg
                                        );
                                    }
                                    $propName = '?';
                                    if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                                        $propName = $child->name->value;
                                    }
                                    $this->throwCompileLogic(
                                        'Property default must be a compile-time constant (#3803): $'.$propName
                                    );
                                }
                            }
                        }
                    }
                    if (null !== $defaultSlot && null !== $child->declaredType) {
                        $defaultVm = $result->constants[$defaultSlot] ?? null;
                        if (null !== $defaultVm) {
                            $propName = '?';
                            if ($child->name instanceof Operand\Literal && is_string($child->name->value)) {
                                $propName = $child->name->value;
                            }
                            $classPrefix = $this->compilingClassDisplayName ?? 'class';
                            $targetName = ($child->static ? $classPrefix.'::' : '').'$'.$propName;
                            $propSourceFile = $child->getFile();
                            if ('' === $propSourceFile) {
                                $propSourceFile = 'unknown';
                            }
                            $this->assertCompileTimeDefaultMatchesDeclaredType(
                                $defaultVm,
                                $child->declaredType,
                                'property',
                                $targetName,
                                null,
                                null,
                                null,
                                $propSourceFile,
                                max(1, $child->getLine())
                            );
                        }
                    }
                    $typeSlot = $this->compileTypeConstrainedVariable(
                        $result,
                        $declared,
                        null !== $propertyDeclName ? $propertyDeclName : $child->declaredType
                    );
                    if (
                        isset($result->constants[$typeSlot])
                        && null !== $result->constants[$typeSlot]->dnfArms
                    ) {
                        $this->scriptHasDnfTypedProperties = true;
                    }
                    $declare = new OpCode(
                        $declareType,
                        $this->compileOperand($child->name, $result, true),
                        $defaultSlot,
                        $typeSlot
                    );
                    $declare->cfgDeclaredType = $child->declaredType;
                    $declare->propertyVisibility = MethodVisibility::mask($child->visibility);
                    $declare->propertySetVisibility = $this->asymmetricSetVisibilityFromCfgOp($child);
                    $declare->propertyGetVisibility = $this->asymmetricGetVisibilityFromCfgOp($child);
                    $declare->propertyAsymmetricExplicitRead = \PHPCompiler\Ast\AsymmetricVisibilityRewriter::hasExplicitReadModifierFromAttributes(
                        $child->getAttributes()
                    );
                    if (!$child->static) {
                        $declare->propertyReadonly = (property_exists($child, 'readonly') && $child->readonly)
                            || (property_exists($child, 'propertyFlags') && $this->isReadonlyPropertyFlags($child->propertyFlags))
                            || $this->isReadonlyPropertyFlags($child->visibility);
                        $declare->propertyLazy = (property_exists($child, 'propertyLazy') && $child->propertyLazy)
                            || LazyPropertyRewriter::isLazyFromAttributes($child->getAttributes());
                    }
                    $declare->propertySetVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
                        $declare->propertyReadonly || $this->compilingClassIsReadonly,
                        MethodVisibility::mask($declare->propertyVisibility),
                        (int) $declare->propertySetVisibility
                    );
                    // Explicit `final` (instance + static) — recover from propertyFlags when CFG
                    // visibility is VISIBILITY_MODIFIER_MASK-stripped (#23403, re-#23036/#22308).
                    // php-src Zend 8.2 rejects all finals; 8.4 allows plain + static finals.
                    $explicitFinal = $this->isFinalPropertyDeclaration($child);
                    $declare->propertyFinal = $explicitFinal
                        || (
                            !$child->static
                            && PropertyVisibility::isImplicitlyFinalFromPrivateSet(
                                (int) $declare->propertySetVisibility
                            )
                        );
                    if ($explicitFinal && !CompilerVersion::supportsFinalProperties()) {
                        // php-src Zend/zend_compile.c — pre-8.4 (#25379, re-#24895/#24822/#22308).
                        // CompileFatal → Zend-shaped "Fatal error: … in file on line N" on CLI.
                        $classDisplay = $this->compilingClassDisplayName ?? '{unknown}';
                        $sourceFile = $child->getFile();
                        if ('' === $sourceFile) {
                            $sourceFile = 'unknown';
                        }
                        throw new CompileFatal(
                            $sourceFile,
                            max(1, $child->getLine()),
                            sprintf(
                                'Cannot declare property %s::$%s final, the final modifier is allowed only for methods, classes, and class constants',
                                $classDisplay,
                                $propName
                            )
                        );
                    }
                    // php-src zend_add_member_modifier — final + private read visibility (#29425).
                    // private(set) only sets asymmetric set-vis, not FLAG_PRIVATE on read vis.
                    if (
                        $explicitFinal
                        && 0 !== (MethodVisibility::mask((int) $child->visibility) & CfgFunc::FLAG_PRIVATE)
                    ) {
                        $sourceFile = $child->getFile();
                        if ('' === $sourceFile) {
                            $sourceFile = 'unknown';
                        }
                        throw new CompileFatal(
                            $sourceFile,
                            max(1, $child->getLine()),
                            \PHPCompiler\SourcePreprocessor\PropertyHooks::FINAL_PRIVATE_PROPERTY_COMPILE_ERROR
                        );
                    }
                    $this->assignAttributeMetadata($declare, $child);
                    AttributeTargetValidator::assertEntriesForTarget(
                        $declare->attributeEntries,
                        AttributeSupport::TARGET_PROPERTY,
                        'property',
                        $this->attributeClassRegistry,
                        true
                    );
                    AttributeNames::assertAttributeMetaClassTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
                    AttributeNames::assertOverrideMethodTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
                    AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
                    AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
                    AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
                    AttributeNames::assertDeprecatedTargetAllowed($declare->attributeNames, 'property', $declare->attributeEntries);
                    $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
                    $this->assignSourceMetadata($declare, $child);
                    $result->addOpCode($declare);
                    break;
                case Op\Stmt\ClassMethod::class:
                    $this->compileClassMethodDeclaration($child, $result, $type);
                    break;
                case Op\Terminal\Const_::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_INTERFACE !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Class constants are only supported on classes, interfaces, and traits for now');
                    }
                    $this->compileClassConstDeclaration($child, $result);
                    break;
                case Op\Stmt\TraitUse::class:
                    if (
                        OpCode::TYPE_DECLARE_CLASS !== $type
                        && OpCode::TYPE_DECLARE_TRAIT !== $type
                    ) {
                        $this->throwCompileLogic('Trait use is only supported on classes and traits for now');
                    }
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
                    break;
                default:
                    $this->throwCompileLogic('Unsupported class body element: ' . get_class($child));
            }
        }
        $this->compilingClassLc = $prevClassLc;
        $this->compilingClassDisplayName = $prevClassDisplayName;
        $this->compilingClassInstancePropertyNames = $prevInstancePropertyNames;
        $this->compilingClassMethodNames = $prevMethodNames;

        return $result;
    }

    protected function registerMethodDeclaration(string $methodName): void
    {
        $lc = strtolower($methodName);
        if (isset($this->compilingClassMethodNames[$lc])) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $this->throwCompileError(sprintf('Cannot redeclare %s::%s()', $class, $methodName));
        }
        $this->compilingClassMethodNames[$lc] = true;
    }

    protected function registerInstancePropertyDeclaration(string $propName): void
    {
        if (isset($this->compilingClassInstancePropertyNames[$propName])) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $this->throwCompileError(sprintf('Cannot redeclare %s::$%s', $class, $propName));
        }
        $this->compilingClassInstancePropertyNames[$propName] = true;
    }

    /**
     * @param list<\PhpParser\Node\Stmt\TraitUseAdaptation> $adaptations
     *
     * @return list<array<string, mixed>>
     */
    protected function compileTraitAdaptations(array $adaptations): array
    {
        $out = [];
        foreach ($adaptations as $adaptation) {
            if ($adaptation instanceof \PhpParser\Node\Stmt\TraitUseAdaptation\Alias) {
                $entry = [
                    'kind' => 'alias',
                    'trait' => null !== $adaptation->trait ? $adaptation->trait->toString() : null,
                    'method' => $adaptation->method->name,
                    'newName' => null !== $adaptation->newName ? $adaptation->newName->name : null,
                ];
                if (null !== $adaptation->newModifier) {
                    $entry['newModifier'] = MethodVisibility::mask((int) $adaptation->newModifier);
                }
                $out[] = $entry;
            } elseif ($adaptation instanceof \PhpParser\Node\Stmt\TraitUseAdaptation\Precedence) {
                $insteadof = [];
                foreach ($adaptation->insteadof as $name) {
                    $insteadof[] = $name->toString();
                }
                $out[] = [
                    'kind' => 'precedence',
                    'trait' => $adaptation->trait->toString(),
                    'method' => $adaptation->method->name,
                    'insteadof' => $insteadof,
                ];
            } else {
                $this->throwCompileLogic('Unsupported TraitUseAdaptation node: ' . get_class($adaptation));
            }
        }

        return $out;
    }

}
