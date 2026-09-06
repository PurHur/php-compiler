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
 * Constructor promotion, asymmetric visibility, and property/param default runtime-new (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code isPromotedParam} through
 * {@code propertyDefaultIsRuntimeNew}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_params / ctor promotion, asymmetric visibility),
 * Zend/zend_inheritance.c — move-only Concern extract; no new C ABI.
 */
trait CompilePromotedPropertyAndParamDefaults
{
    protected function isPromotedParam(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }

    protected function compilePromotedPropertyDeclaration(Op\Expr\Param $param, Block $result): void
    {
        // php-src Zend/zend_compile.c — variadic + promotion incompatible (#26515).
        if ($param->variadic) {
            $sourceFile = $param->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            throw new CompileFatal(
                $sourceFile,
                max(1, $param->getLine()),
                VariadicPromotedPropertyCompileCheck::MESSAGE
            );
        }
        $propName = '?';
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $propName = $param->name->value;
            $this->registerInstancePropertyDeclaration($propName);
        }
        $this->assertPropertyDeclaredType($param->declaredType, $propName);
        $defaultSlot = $this->resolvePropertyOrParamDefaultSlot($param, $result);
        if (null !== $defaultSlot && null !== $param->declaredType) {
            $defaultVm = $result->constants[$defaultSlot] ?? null;
            if (null !== $defaultVm) {
                $propName = '?';
                if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                    $propName = '$'.$param->name->value;
                }
                $propSourceFile = $param->getFile();
                if ('' === $propSourceFile) {
                    $propSourceFile = 'unknown';
                }
                $this->assertCompileTimeDefaultMatchesDeclaredType(
                    $defaultVm,
                    $param->declaredType,
                    'property',
                    $propName,
                    null,
                    null,
                    null,
                    $propSourceFile,
                    max(1, $param->getLine())
                );
            }
        }
        $declared = $this->typeFromParamDecl($param);
        $propName = new Operand\Literal($param->name->value);
        $propName->type = Type::string();
        $typeSlot = $this->compileTypeConstrainedVariable($result, $declared, $param->declaredType);
        if (isset($result->constants[$typeSlot]) && null !== $result->constants[$typeSlot]->dnfArms) {
            $this->scriptHasDnfTypedProperties = true;
        }
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_PROPERTY,
            $this->compileOperand($propName, $result, true),
            $defaultSlot,
            $typeSlot
        );
        $declare->propertyReadonly = $this->isPromotedParamReadonly($param);
        $declare->propertyFromConstructorPromotion = true;
        $declare->propertyVisibility = MethodVisibility::mask($param->promotionFlags);
        $declare->propertySetVisibility = $this->asymmetricSetVisibilityFromCfgOp($param);
        $declare->propertyGetVisibility = $this->asymmetricGetVisibilityFromCfgOp($param);
        $declare->propertySetVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
            $declare->propertyReadonly || $this->compilingClassIsReadonly,
            MethodVisibility::mask($declare->propertyVisibility),
            (int) $declare->propertySetVisibility
        );
        $explicitFinal = FinalPromotedPropertyRewriter::isFinalFromAttributes($param->getAttributes())
            || (property_exists($param, 'promotionFinal') && $param->promotionFinal);
        // php-src zend_API.c — private(set) promoted props are implicitly final (#23068).
        $declare->propertyFinal = $explicitFinal
            || PropertyVisibility::isImplicitlyFinalFromPrivateSet((int) $declare->propertySetVisibility);
        if ($explicitFinal && !CompilerVersion::supportsFinalPromotedProperties()) {
            // php-src: ≤8.3 parse error; 8.4 zend_compile.c fatal until 8.5 (#27123, #31153).
            $sourceFile = $param->getFile();
            if ('' === $sourceFile) {
                $sourceFile = 'unknown';
            }
            throw new CompileFatal(
                $sourceFile,
                max(1, $param->getLine()),
                \PHPCompiler\Ast\FinalPromotedPropertyRewriter::referenceProfileRejectMessage()
            );
        }
        $declare->propertyAsymmetricExplicitRead = \PHPCompiler\Ast\AsymmetricVisibilityRewriter::hasExplicitReadModifierFromAttributes(
            $param->getAttributes()
        );
        $declare->deprecatedMetadata = DeprecatedMetadata::fromOp($param);
        $this->assignAttributeMetadata($declare, $param);
        // GH-9420 / #9661: parameter-only internals (SensitiveParameter) stay on the param,
        // not ReflectionProperty (#26379, re-#20351). Param metadata still has the full list.
        $declare->attributeEntries = AttributeNames::filterPromotedPropertyAttributeEntries(
            $declare->attributeEntries
        );
        $declare->attributeNames = AttributeEntry::namesFromList($declare->attributeEntries);
        AttributeTargetValidator::assertPromotedParameterTargets($declare->attributeEntries, $this->attributeClassRegistry);
        AttributeNames::assertAttributeMetaClassTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
        AttributeNames::assertOverrideMethodTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($declare->attributeNames, 'property', $declare->attributeEntries);
        // php-src zend_attributes.c — promotion is a parameter for #[\Deprecated] target checks (#29420).
        AttributeNames::assertDeprecatedTargetAllowed($declare->attributeNames, 'parameter', $declare->attributeEntries);
        $result->addOpCode($declare);
    }

    protected function isReadonlyPropertyFlags(int $flags): bool
    {
        return 0 !== ($flags & ClassReadonly::MODIFIER_READONLY);
    }

    /**
     * PHP 8.4 final property from php-parser MODIFIER_FINAL (#22241, #23403).
     *
     * CFG `visibility` is VISIBILITY_MODIFIER_MASK only — recover FINAL from `propertyFlags`.
     * Hooked finals strip `final` before parse and set registry finalProperty (#16799).
     */
    protected function isFinalPropertyDeclaration(Op\Stmt\Property $prop): bool
    {
        if (property_exists($prop, 'propertyFlags') && ClassFinal::fromClassFlags((int) $prop->propertyFlags)) {
            return true;
        }

        return ClassFinal::fromClassFlags((int) $prop->visibility);
    }

    protected function isPromotedParamReadonly(Op\Expr\Param $param): bool
    {
        return property_exists($param, 'promotionReadonly') && $param->promotionReadonly;
    }

    protected function asymmetricSetVisibilityFromCfgOp(Op $op): int
    {
        if (property_exists($op, 'setVisibility') && 0 !== (int) $op->setVisibility) {
            return (int) $op->setVisibility;
        }
        if (property_exists($op, 'promotionSetVisibility') && 0 !== (int) $op->promotionSetVisibility) {
            return (int) $op->promotionSetVisibility;
        }

        return AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($op->getAttributes());
    }

    protected function asymmetricGetVisibilityFromCfgOp(Op $op): int
    {
        if (property_exists($op, 'getVisibility') && 0 !== (int) $op->getVisibility) {
            return (int) $op->getVisibility;
        }
        if (property_exists($op, 'promotionGetVisibility') && 0 !== (int) $op->promotionGetVisibility) {
            return (int) $op->promotionGetVisibility;
        }

        return AsymmetricVisibilityRewriter::extractGetVisibilityFromAttributes($op->getAttributes());
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    protected function compileCtorPromotionAssignments(Block $block, array $params): void
    {
        $thisVar = new Operand\Variable(new Operand\Literal('this'));
        $thisSlot = $block->getVarSlot($thisVar, true);

        foreach ($params as $param) {
            if (!$this->isPromotedParam($param)) {
                continue;
            }
            if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                $this->throwCompileLogic('Promoted constructor parameter must have a simple name');
            }
            $propName = new Operand\Literal($param->name->value);
            $propName->type = Type::string();
            $propSlot = $this->compileOperand($propName, $block, true);
            $fetchTmp = new Temporary();
            $fetchSlot = $block->getVarSlot($fetchTmp, false);
            // Param slot is already registered by compileParam(); do not mark as arg read or
            // getFrame fails on method entry (callArgs holds $this only) (#3816).
            $paramSlot = $this->compileOperand($param->result, $block, false);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_PROPERTY_FETCH,
                $fetchSlot,
                $thisSlot,
                $propSlot
            ));
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $fetchSlot,
                $fetchSlot,
                $paramSlot
            ));
        }
    }

    protected function typeFromPropertyDecl(Op\Stmt\Property $child): Type
    {
        if ($child->declaredType instanceof Op\Type\Mixed_) {
            return Type::mixed();
        }
        if ($child->declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($child->declaredType->name)) {
            return Type::mixed();
        }
        if ($child->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($child->declaredType->name);
        }
        if (null !== $child->declaredType) {
            return Type::fromTypeDecl($child->declaredType);
        }

        return $child->type ?? Type::mixed();
    }

    protected function typeFromParamDecl(Op\Expr\Param $param): Type
    {
        if ($param->declaredType instanceof Op\Type\Mixed_) {
            return Type::mixed();
        }
        if ($param->declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($param->declaredType->name)) {
            return Type::mixed();
        }
        if ($param->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($param->declaredType->name);
        }
        if (null !== $param->declaredType) {
            return Type::fromTypeDecl($param->declaredType);
        }

        return Type::mixed();
    }

    protected function compileTypeConstrainedVariable(Block $block, Type $type, Op\Type|string|null $cfgTypeOrDeclName = null): int {
        $cfgType = $cfgTypeOrDeclName instanceof Op\Type ? $cfgTypeOrDeclName : null;
        $declName = is_string($cfgTypeOrDeclName) ? $cfgTypeOrDeclName : null;
        // Untyped (no source type / php-cfg Mixed_) → TYPE_NULL; typed incl. explicit mixed → UNDEFINED (#4240, #22021).
        $untypedPrototype = null === $cfgTypeOrDeclName || $cfgType instanceof Op\Type\Mixed_;
        $var = new Variable($untypedPrototype ? Variable::TYPE_NULL : Variable::TYPE_UNDEFINED);
        $operand = new Operand\Temporary;
        $operand->type = $type;
        $return = $block->registerConstant($operand, $var);
        $arraySpec = null !== $declName ? GenericArrayTypeSpec::tryParseDeclName($declName) : null;
        if (null !== $arraySpec) {
            $var->typeConstraint = Variable::TYPE_ARRAY;
            $var->genericArrayTypeSpec = $arraySpec;
            $var->declaredTypeLabel = $declName;

            return $return;
        }
        $literalBoolName = null;
        if ($cfgType instanceof Op\Type\Literal) {
            $literalBoolName = strtolower($cfgType->name);
        } elseif (null !== $declName) {
            $literalBoolName = strtolower($declName);
        }
        if ('true' === $literalBoolName || 'false' === $literalBoolName) {
            $var->typeConstraint = Variable::TYPE_BOOLEAN;
            $var->literalBoolType = $literalBoolName;
            $var->declaredTypeLabel = $literalBoolName;

            return $return;
        }
        if (null !== $cfgType && $this->cfgDeclaredTypeIsMixed($cfgType)) {
            // Op\Type\Mixed_ is php-cfg's untyped marker (already TYPE_NULL). Literal "mixed" stays UNDEFINED.
            return $return;
        }
        // PHPTypes Type::fromDecl('mixed') mis-parses as object userType mixed (#12348).
        if (Type::TYPE_OBJECT === $type->type && 'mixed' === strtolower((string) ($type->userType ?? ''))) {
            return $return;
        }
        if ($this->cfgTypeUsesDnfShape($cfgType)) {
            $dnfArms = DnfType::armsFromCfgType(
                $cfgType,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t, $block),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t, $block),
                fn (Op\Type\Reference $t) => $this->resolvedDnfReferenceNameFromCfgType($t, $block)
            );
            if (DnfType::hasConstraints($dnfArms) && DnfType::requiresDnfLowering($dnfArms)) {
                $var->dnfArms = $dnfArms;
                $var->declaredTypeLabel = $this->dnfTypeLabelFromCfgType($cfgType, $block);

                return $return;
            }
        }
        if (Type::TYPE_UNION === $type->type) {
            // PHPTypes Type::mixed() — untyped properties and `mixed` hints must not coerce writes (#2256).
            if (str_contains($type->toString(), 'callable')) {
                return $return;
            }
            $members = [];
            foreach ($type->subTypes as $sub) {
                $mapped = Variable::mapFromType($sub);
                if (Variable::TYPE_UNDEFINED !== $mapped) {
                    $members[] = $mapped;
                }
            }
            if ([] !== $members) {
                $var->unionTypeConstraints = $members;
                $memberNames = [];
                foreach ($type->subTypes as $sub) {
                    $memberNames[] = $sub->toString();
                }
                $var->declaredTypeLabel = DnfType::zendCanonicalUnionLabel($memberNames);
            }

            return $return;
        }
        $mappedType = Variable::mapFromType($type);
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            return $return;
        }
        if ($mappedType === Variable::TYPE_OBJECT) {
            $classConstraint = $type->userType;
            // Bare `self`/`parent` property (and promoted) types stay LITERAL/Reference
            // keywords in php-cfg; DNF (?self / self|…) already early-binds via
            // resolvedDnfReferenceNameFromCfgType. Resolve the check class here so
            // instanceof matches Zend while TypeErrors keep the keyword label
            // (zend_object_handlers.c / #31835).
            if (null !== $classConstraint && '' !== $classConstraint) {
                $lc = strtolower(ltrim($classConstraint, '\\'));
                if ('self' === $lc || 'parent' === $lc) {
                    $resolved = $this->resolveTypeHintClassName($classConstraint, $block);
                    if (null !== $resolved && '' !== $resolved) {
                        $classConstraint = $resolved;
                    }
                }
            }
            $var->classConstraint = $classConstraint;
        }
        $var->typeConstraint = $mappedType;
        $var->declaredTypeLabel = $type->toString();

        return $return;
    }


    /**
     * Fold parameter/property defaults to block constant slots (Zend zend_compile_default_value, #3803).
     */
    protected function resolvePropertyOrParamDefaultSlot(Op\Expr\Param $param, Block $block, ?int $paramIdx = null): ?int
    {
        if (null === $param->defaultVar) {
            return null;
        }
        $this->rejectStaticScopeInCompileTimeConstExpr($param->defaultBlock, $param, $param->defaultVar);
        $folded = $this->tryFoldParamDefaultSlot($param, $block);
        if (null !== $folded) {
            return $folded;
        }
        if ($this->paramDefaultUsesRuntimeInit($param)) {
            if (null === $paramIdx) {
                // Promoted property metadata: default applied via constructor param (#6652).
                return null;
            }
            $beforeCount = \count($block->opCodes);
            if (null !== $param->defaultBlock) {
                // Same Array_/New_ rematerialize as function statics (#22390, #8561).
                $this->compileDefaultBlockChildrenWithProducerCfg($param->defaultBlock, $block);
            }
            $resultSlot = $this->compileOperand($param->defaultVar, $block, true);
            $newOps = \array_slice($block->opCodes, $beforeCount);
            $block->opCodes = \array_slice($block->opCodes, 0, $beforeCount);
            $block->nOpCodes = \count($block->opCodes);
            $block->invalidateOpcodeDerivedIndexes();
            $block->paramRuntimeDefaultInitBlocks[$paramIdx] = $block->fragmentForOpcodes($newOps);
            $block->paramRuntimeDefaultResultSlots[$paramIdx] = $resultSlot;

            return null;
        }
        if (null !== $param->defaultBlock) {
            $this->compileDefaultBlockChildrenWithProducerCfg($param->defaultBlock, $block);
        }
        $slot = $this->compileOperand($param->defaultVar, $block, true);
        if (!isset($block->constants[$slot])) {
            $paramName = '?';
            if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                $paramName = $param->name->value;
            }
            $this->throwCompileLogic(
                'Parameter default must be a compile-time constant (#3803): $'.$paramName
            );
        }

        return $slot;
    }

    /**
     * Parameter defaults evaluated when the argument is omitted: `new Class()` (#6652).
     * Unresolved ConstFetch / ClassConstFetch defaults defer like Zend (zend_compile_default_value, #24138).
     * First-class callables are not constant expressions below PHP 8.5 (Zend/zend_compile.c, #9697);
     * on 8.5+ they are legal const-exprs and lower as runtime defaults (#26240).
     */
    protected function paramDefaultUsesRuntimeInit(Op\Expr\Param $param): bool
    {
        if ($this->paramDefaultIsRuntimeNew($param)) {
            return true;
        }
        if (null !== $this->paramDefaultFirstClassCallableExpr($param)) {
            if (!CompilerVersion::supportsClosuresInConstantExpressions()) {
                $this->throwCompileLogic(ThrowInClassConstCompileCheck::MESSAGE);
            }

            return true;
        }
        // tryFoldParamDefaultSlot already failed — keep the AST and resolve at call time (#24138).
        if (null !== $this->paramDefaultConstFetchExpr($param)) {
            return true;
        }

        return false;
    }

    /**
     * Parameter default `new Class()` — evaluated when the argument is omitted (#6652).
     */
    protected function paramDefaultIsRuntimeNew(Op\Expr\Param $param): bool
    {
        if (null === $param->defaultVar) {
            return false;
        }
        if (null !== $param->defaultBlock && [] !== $param->defaultBlock->children) {
            $last = $param->defaultBlock->children[\count($param->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\New_) {
                return true;
            }
        }

        return $this->unwrapOperandChain($param->defaultVar) instanceof Op\Expr\New_;
    }

    protected function paramDefaultFirstClassCallableExpr(Op\Expr\Param $param): ?Op\Expr\FirstClassCallable
    {
        if (null === $param->defaultVar) {
            return null;
        }
        if (null !== $param->defaultBlock && [] !== $param->defaultBlock->children) {
            $last = $param->defaultBlock->children[\count($param->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\FirstClassCallable) {
                return $last;
            }
        }

        $unwrapped = $this->unwrapOperandChain($param->defaultVar);

        return $unwrapped instanceof Op\Expr\FirstClassCallable ? $unwrapped : null;
    }

    /**
     * Property default `new Class()` — deferred to instance creation (Zend zend_objects.c, #3391).
     */
    protected function propertyDefaultIsRuntimeNew(Op\Stmt\Property $prop): bool
    {
        if (null === $prop->defaultVar) {
            return false;
        }
        if (null !== $prop->defaultBlock && [] !== $prop->defaultBlock->children) {
            $last = $prop->defaultBlock->children[\count($prop->defaultBlock->children) - 1];
            if ($last instanceof Op\Expr\New_) {
                return true;
            }
        }

        return $this->unwrapOperandChain($prop->defaultVar) instanceof Op\Expr\New_;
    }
}
