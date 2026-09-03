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
 * Class-like / function / stmt compilation helpers (#36403 / #36230).
 *
 * Extracted from {@see \PHPCompiler\Compiler} behind the opcode-corpus-md5 gate.
 * Visibility stays protected/private so LintCompiler and call sites are unchanged.
 */
trait ClassLikeAndStmtCompile
{
    private function isPropertyFetchOnlyEmptyVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next,
        Block $block
    ): bool {
        if ($next instanceof Op\Expr\Empty_) {
            $target = $next->expr;
            if ($target === $fetch || $target === $fetch->result) {
                return true;
            }
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }

            return $this->findCoalescePropertyFetch($target, $block) === $fetch;
        }
        if ($this->isInlineExprCallArgConsumer($next)) {
            return $this->funcCallHasEmptyArgUsingPropertyFetch($next, $fetch, $block);
        }

        return false;
    }

    private function isStaticPropertyFetchOnlyEmptyVar(
        Op\Expr\StaticPropertyFetch $fetch,
        Op $next,
        Block $block
    ): bool {
        if ($next instanceof Op\Expr\Empty_) {
            $target = $next->expr;
            if ($target === $fetch || $target === $fetch->result) {
                return true;
            }
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }

            return $this->findCoalesceStaticPropertyFetch($target, $block) === $fetch;
        }
        if ($this->isInlineExprCallArgConsumer($next)) {
            return $this->funcCallHasEmptyArgUsingStaticPropertyFetch($next, $fetch, $block);
        }

        return false;
    }

    private function funcCallHasEmptyArgUsingPropertyFetch(Op $call, Op\Expr\PropertyFetch $fetch, Block $block): bool
    {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if (!$arg instanceof Operand\Temporary || !$arg->original instanceof Op\Expr\Empty_) {
                continue;
            }
            if ($this->emptyExprDependsOnOperand($arg->original, $fetch->result, $block)) {
                return true;
            }
        }

        return false;
    }

    private function funcCallHasEmptyArgUsingStaticPropertyFetch(
        Op $call,
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block
    ): bool {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return false;
        }
        foreach ($call->args as $arg) {
            if (!$arg instanceof Operand\Temporary || !$arg->original instanceof Op\Expr\Empty_) {
                continue;
            }
            if ($this->emptyExprDependsOnOperand($arg->original, $fetch->result, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg emits ArrayDimFetch as its own stmt before Empty_; skip duplicate lowering (#5307).
     */
    private function isArrayDimFetchOnlyEmptyVar(
        Op\Expr\ArrayDimFetch $fetch,
        Op $next,
        Block $block
    ): bool {
        if (!$next instanceof Op\Expr\Empty_) {
            return false;
        }
        $target = $next->expr;
        if ($target === $fetch || $target === $fetch->result) {
            return true;
        }
        while ($target instanceof Temporary) {
            if ($target === $fetch->result) {
                return true;
            }
            if (null === $target->original) {
                break;
            }
            $target = $target->original;
        }
        if ($target === $fetch->result) {
            return true;
        }

        return $this->findCoalesceArrayDimFetch($target, $block) === $fetch;
    }

    private function isPropertyWriteAssign(Op\Expr\Assign $assign, Block $block): bool
    {
        if (null !== $this->unwrapPropertyFetch($assign->var)
            || null !== $this->findCoalescePropertyFetch($assign->var, $block)) {
            return true;
        }

        return null !== $this->unwrapStaticPropertyFetch($assign->var)
            || null !== $this->findStaticPropertyFetchForAssign($assign->var, $block);
    }

    /** While-loop ?: merge must not steal array-append write slots (#10702). */
    private function isArrayDimWriteAssign(Op\Expr\Assign $assign, Block $block): bool
    {
        if (null !== $this->unwrapArrayDimFetch($assign->var)) {
            return true;
        }

        return null !== $this->findArrayDimFetchForResult($assign->var, $block);
    }

    private function isPropertyFetchOnlyAssignVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Assign) {
            return false;
        }
        $var = $next->var;
        if ($var === $fetch || $var === $fetch->result) {
            return true;
        }
        while ($var instanceof Temporary) {
            if ($var === $fetch->result || $var->original === $fetch) {
                return true;
            }
            if (null === $var->original) {
                break;
            }
            $var = $var->original;
        }

        return $var === $fetch->result;
    }

    /**
     * `[&$obj->hook]` — php-cfg emits PropertyFetch then Expr_Array; eager read fetch breaks ref (#17353).
     */
    private function isPropertyFetchLoweredByFollowingArrayLiteralByRefElement(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Expr\Array_) {
            return false;
        }

        return $this->arrayLiteralHasByRefElementOperand($next, $fetch->result);
    }

    private function arrayLiteralHasByRefElementOperand(Op\Expr\Array_ $array, Operand $target): bool
    {
        $byRefFlags = property_exists($array, 'byRef') ? $array->byRef : [];
        foreach ($array->values as $i => $value) {
            if (empty($byRefFlags[$i])) {
                continue;
            }
            if ($value === $target) {
                return true;
            }
            $cursor = $value;
            while ($cursor instanceof Temporary) {
                if ($cursor === $target) {
                    return true;
                }
                if (null === $cursor->original) {
                    break;
                }
                $cursor = $cursor->original;
            }
        }

        return false;
    }

    private function isPropertyFetchOnlyUnsetVar(
        Op\Expr\PropertyFetch $fetch,
        Op $next
    ): bool {
        if (!$next instanceof Op\Terminal\Unset_) {
            return false;
        }
        foreach ($next->exprs as $var) {
            if ($var === $fetch) {
                return true;
            }
            $target = $var;
            while ($target instanceof Temporary) {
                if ($target === $fetch->result) {
                    return true;
                }
                if (null === $target->original) {
                    break;
                }
                $target = $target->original;
            }
            if ($target === $fetch->result) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: ?int, 2: bool}
     */
    protected function resolveUnsetTarget($expr, Block $block): array
    {
        if ($expr instanceof Op\Expr\ArrayDimFetch) {
            [$containerSlot, $dimSlot] = $this->resolveIssetTargetFromArrayDimFetch($expr, $block);

            return [$containerSlot, $dimSlot, false];
        }
        if ($expr instanceof Op\Expr\PropertyFetch) {
            return [
                $this->compileOperand($expr->var, $block, true),
                $this->compileOperand($expr->name, $block, true),
                true,
            ];
        }
        if ($expr instanceof Op\Expr\StaticPropertyFetch) {
            $this->throwCompileLogic(
                'StaticPropertyFetch unset must be lowered via TYPE_STATIC_PROPERTY_UNSET (#2256)'
            );
        }
        if ($expr instanceof Operand) {
            $dimFetch = $this->findCoalesceArrayDimFetch($expr, $block);
            if (null !== $dimFetch) {
                [$containerSlot, $dimSlot] = $this->resolveIssetTargetFromArrayDimFetch($dimFetch, $block);

                return [$containerSlot, $dimSlot, false];
            }
            foreach ($block->orig->children as $child) {
                if ($child instanceof Op\Expr\PropertyFetch && $child->result === $expr) {
                    return [
                        $this->compileOperand($child->var, $block, true),
                        $this->compileOperand($child->name, $block, true),
                        true,
                    ];
                }
            }
            [$containerSlot, $dimSlot] = $this->resolveIssetTarget($expr, $block);

            return [$containerSlot, $dimSlot, false];
        }

        $this->throwCompileLogic('Unsupported unset target: ' . (is_object($expr) ? $expr->getType() : gettype($expr)));
    }

    /**
     * php-src Zend/zend_compile.c {@code reserved_class_names} / {@code zend_is_reserved_class_name()} (#32206).
     * Match is case-insensitive on the unqualified name; {@code parent}/{@code self}/{@code static}
     * and {@code array}/{@code callable} are in the C table (usually parse errors as identifiers).
     *
     * @var array<string, true>
     */
    private const RESERVED_CLASS_NAMES = [
        'bool' => true,
        'false' => true,
        'float' => true,
        'int' => true,
        'null' => true,
        'parent' => true,
        'self' => true,
        'static' => true,
        'string' => true,
        'true' => true,
        'void' => true,
        'never' => true,
        'iterable' => true,
        'object' => true,
        'mixed' => true,
        'array' => true,
        'callable' => true,
    ];

    /**
     * php-src zend_compile_const_decl() + zend_get_special_const() (#32228).
     * File-scope `const true` / `false` / `null` (any case, any namespace prefix) is a
     * compile fatal. Message preserves the source spelling of the unqualified name.
     * `define('true', 1)` stays a runtime warning — do not call this from the define() path.
     */
    protected function rejectReservedGlobalConstName(Op\Terminal\Const_ $const): void
    {
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name || '' === $name) {
            return;
        }
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        $lc = strtolower($unqualified);
        if ('true' !== $lc && 'false' !== $lc && 'null' !== $lc) {
            return;
        }
        $detail = sprintf("Cannot redeclare constant '%s'", $unqualified);
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_compile_class_const_declaration() + zend_check_const_and_trait_alias_name() (#32251).
     * Declared class/interface/trait/enum constant named `class` (any case) is a compile fatal;
     * `Foo::class` the pseudo-constant is a fetch, not a declaration.
     */
    protected function rejectReservedClassConstName(?string $constName, Op\Terminal\Const_ $const): void
    {
        if (null === $constName || '' === $constName) {
            return;
        }
        $unqualified = $constName;
        if (str_contains($constName, '\\')) {
            $parts = explode('\\', $constName);
            $unqualified = $parts[count($parts) - 1];
        }
        if ('class' !== strtolower($unqualified)) {
            return;
        }
        $detail = "A class constant must not be called 'class'; it is reserved for class name fetching";
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_assert_valid_class_name() — compile fatal before TYPE_DECLARE_* (#32206).
     * Message shape is PHP 8.2/8.3: Cannot use '%s' as class name as it is reserved.
     */
    protected function assertNotReservedClassName(string $name, Op $op): void
    {
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        if (!isset(self::RESERVED_CLASS_NAMES[strtolower($unqualified)])) {
            return;
        }
        $detail = sprintf("Cannot use '%s' as class name as it is reserved", $unqualified);
        $sourceFile = $op->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $op->getLine());
    }

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

    protected function compileClassLike(Op\Stmt\ClassLike $class, Block $block): OpCode {
        $type = 0;
        if ($class instanceof Op\Stmt\Class_) {
            $type = OpCode::TYPE_DECLARE_CLASS;
        } else {
            $this->throwCompileLogic('Unsupported class type: ' . get_class($class));
        }
        $className = $this->staticNameFromOperand($class->name);
        if (null === $className) {
            $this->throwCompileError('Class name must be a compile-time class reference');
        }
        $this->assertNotReservedClassName($className, $class);
        $parentLc = null;
        $parentName = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentName = $this->staticNameFromOperand($class->extends);
            if (null === $parentName) {
                $this->throwCompileError('Parent class name must be a compile-time class reference');
            }
            $parentLc = strtolower(ltrim($parentName, '\\'));
        }
        [$interfaceLcs, $interfaceDisplays] = $this->interfaceLcAndDisplayFromOperands($class->implements);
        $parentSlot = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentSlot = $this->compileOperand($class->extends, $block, true);
        }
        $classFlagsVar = new Variable(Variable::TYPE_INTEGER);
        $classFlagsVar->int(\PHPCompiler\VM\ClassFlags::pack($class->flags));
        $classFlagsOperand = new Operand\Temporary;
        $classFlagsOperand->type = Type::int();
        $readonlySlot = $block->registerConstant($classFlagsOperand, $classFlagsVar);
        $return = new OpCode(
            $type,
            $this->compileOperand($class->name, $block, true),
            $parentSlot,
            $readonlySlot
        );
        $return->classImplements = $interfaceLcs;
        $return->classImplementsDisplay = $interfaceDisplays;
        if (\PHPCompiler\VM\StringableSupport::requiresImplementation($return->classImplements)) {
            \PHPCompiler\VM\StringableSupport::assertConcreteClassImplements($class, $className);
        }
        $this->assignAttributeMetadata($return, $class);
        $this->assignSourceMetadata($return, $class);
        $return->deprecatedMetadata = DeprecatedMetadata::fromOp($class);
        AttributeNames::assertOverrideMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($return->attributeNames, 'class', $return->attributeEntries);
        AttributeNames::assertDeprecatedAllowedOnClassLike(
            $return->attributeNames,
            $return->deprecatedMetadata,
            'class',
            $className,
            $return->attributeEntries
        );
        $this->applySealedMetadataFromOp($class, $return);
        $return->classIsAbstract = \PHPCompiler\VM\ClassAbstract::fromClassFlags($class->flags);
        if ($return->classIsAbstract) {
            AttributeNames::assertAttributeMetaOnConcreteClassLike(
                $return->attributeEntries,
                'abstract class',
                $className
            );
        }
        $classLc = strtolower(ltrim($className, '\\'));
        $this->compiledClassStaticProperties[$classLc] = $this->compiledClassStaticProperties[$classLc] ?? [];
        if (null !== $parentLc) {
            $this->inheritCompileTimeClassConstsFromParent($classLc, $parentLc);
        }
        $prevClassStaticCompile = $this->currentClassStaticPropertyCompile;
        $this->currentClassStaticPropertyCompile = $classLc;
        $prevCompilingParentLc = $this->compilingClassParentLc;
        $prevCompilingParentName = $this->compilingClassParentName;
        $prevCompilingClassIsReadonly = $this->compilingClassIsReadonly;
        $this->compilingClassParentLc = $parentLc;
        $this->compilingClassParentName = null !== $parentLc
            ? ($parentName ?? $parentLc)
            : null;
        $this->compilingClassIsReadonly = ClassReadonly::fromClassFlags($class->flags);
        $return->block1 = $this->compileClassBody(
            $class->stmts,
            $type,
            $className
        );
        $this->compilingClassParentLc = $prevCompilingParentLc;
        $this->compilingClassParentName = $prevCompilingParentName;
        $this->compilingClassIsReadonly = $prevCompilingClassIsReadonly;
        $this->currentClassStaticPropertyCompile = $prevClassStaticCompile;
        $this->mergeTraitStaticPropertiesIntoClass($class->stmts, $classLc);
        $this->mergeTraitCompileTimeClassConstsIntoClass($class->stmts, $classLc);
        $this->mergeInterfaceCompileTimeClassConstsIntoClass($classLc, $interfaceLcs);
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends && null !== $parentLc) {
            foreach ($this->compiledClassStaticProperties[$parentLc] ?? [] as $prop => $_) {
                $this->compiledClassStaticProperties[$classLc][$prop] = true;
            }
        }
        $this->classCompileRegistry->registerClass($className, $parentLc, $interfaceLcs, $class->stmts);
        $this->registerAttributeClassFromEntries($className, $return->attributeEntries);

        return $return;
    }

    protected function mergeTraitStaticPropertiesIntoClass(CfgBlock $stmts, string $classLc): void
    {
        foreach ($stmts->children as $child) {
            if (!$child instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($child->traits as $traitOperand) {
                $traitName = $this->staticNameFromOperand($traitOperand);
                if (null === $traitName) {
                    continue;
                }
                $traitLc = strtolower(ltrim($traitName, '\\'));
                foreach ($this->compiledClassStaticProperties[$traitLc] ?? [] as $prop => $_) {
                    $this->compiledClassStaticProperties[$classLc][$prop] = true;
                }
            }
        }
    }

    /**
     * Copy trait class constants into the composing class compile-time table (#9430, zend_traits.c).
     */
    protected function mergeTraitCompileTimeClassConstsIntoClass(CfgBlock $stmts, string $classLc): void
    {
        foreach ($stmts->children as $child) {
            if (!$child instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($child->traits as $traitOperand) {
                $traitName = $this->staticNameFromOperand($traitOperand);
                if (null === $traitName) {
                    continue;
                }
                $this->inheritCompileTimeClassConstsFromTrait(
                    $classLc,
                    strtolower(ltrim($traitName, '\\'))
                );
            }
        }
    }

    /**
     * Copy interface class constants into implementor compile-time table (#9430, zend_constants.c).
     *
     * @param list<string> $interfaceLcs
     */
    protected function mergeInterfaceCompileTimeClassConstsIntoClass(string $classLc, array $interfaceLcs): void
    {
        foreach ($interfaceLcs as $ifaceLc) {
            $this->inheritCompileTimeClassConstsFromInterface($classLc, $ifaceLc);
        }
    }

    protected function inheritCompileTimeClassConstsFromTrait(string $classLc, string $traitLc): void
    {
        if (!isset($this->compileTimeClassConsts[$traitLc])) {
            return;
        }
        if (!isset($this->compileTimeClassConsts[$classLc])) {
            $this->compileTimeClassConsts[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstVisibility[$classLc])) {
            $this->compileTimeClassConstVisibility[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstDeprecated[$classLc])) {
            $this->compileTimeClassConstDeprecated[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstNames[$classLc])) {
            $this->compileTimeClassConstNames[$classLc] = [];
        }
        foreach ($this->compileTimeClassConsts[$traitLc] as $constLc => $value) {
            if (isset($this->compileTimeClassConsts[$classLc][$constLc])) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            $this->compileTimeClassConsts[$classLc][$constLc] = $stored;
            if (isset($this->compileTimeClassConstVisibility[$traitLc][$constLc])) {
                $this->compileTimeClassConstVisibility[$classLc][$constLc]
                    = $this->compileTimeClassConstVisibility[$traitLc][$constLc];
            }
            if (isset($this->compileTimeClassConstDeprecated[$traitLc][$constLc])) {
                $this->compileTimeClassConstDeprecated[$classLc][$constLc]
                    = $this->compileTimeClassConstDeprecated[$traitLc][$constLc];
            }
            if (isset($this->compileTimeClassConstNames[$traitLc][$constLc])) {
                $this->compileTimeClassConstNames[$classLc][$constLc]
                    = $this->compileTimeClassConstNames[$traitLc][$constLc];
            }
        }
    }

    protected function inheritCompileTimeClassConstsFromInterface(string $classLc, string $ifaceLc): void
    {
        if (!isset($this->compileTimeClassConsts[$ifaceLc])) {
            return;
        }
        if (!isset($this->compileTimeClassConsts[$classLc])) {
            $this->compileTimeClassConsts[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstVisibility[$classLc])) {
            $this->compileTimeClassConstVisibility[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstDeprecated[$classLc])) {
            $this->compileTimeClassConstDeprecated[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstNames[$classLc])) {
            $this->compileTimeClassConstNames[$classLc] = [];
        }
        foreach ($this->compileTimeClassConsts[$ifaceLc] as $constLc => $value) {
            if (isset($this->compileTimeClassConsts[$classLc][$constLc])) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            $this->compileTimeClassConsts[$classLc][$constLc] = $stored;
            if (isset($this->compileTimeClassConstVisibility[$ifaceLc][$constLc])) {
                $this->compileTimeClassConstVisibility[$classLc][$constLc]
                    = $this->compileTimeClassConstVisibility[$ifaceLc][$constLc];
            }
            // Keep #[\Deprecated] so implementor fetches are not constant-folded (#29380).
            if (isset($this->compileTimeClassConstDeprecated[$ifaceLc][$constLc])) {
                $this->compileTimeClassConstDeprecated[$classLc][$constLc]
                    = $this->compileTimeClassConstDeprecated[$ifaceLc][$constLc];
            }
            if (isset($this->compileTimeClassConstNames[$ifaceLc][$constLc])) {
                $this->compileTimeClassConstNames[$classLc][$constLc]
                    = $this->compileTimeClassConstNames[$ifaceLc][$constLc];
            }
        }
    }

    /**
     * Copy public/protected parent class constants for compile-time {@code self::} folding (#13532, zend_constants.c).
     */
    protected function inheritCompileTimeClassConstsFromParent(string $classLc, string $parentLc): void
    {
        if (!isset($this->compileTimeClassConsts[$parentLc])) {
            return;
        }
        if (!isset($this->compileTimeClassConsts[$classLc])) {
            $this->compileTimeClassConsts[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstVisibility[$classLc])) {
            $this->compileTimeClassConstVisibility[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstDeprecated[$classLc])) {
            $this->compileTimeClassConstDeprecated[$classLc] = [];
        }
        if (!isset($this->compileTimeClassConstNames[$classLc])) {
            $this->compileTimeClassConstNames[$classLc] = [];
        }
        foreach ($this->compileTimeClassConsts[$parentLc] as $constLc => $value) {
            if (isset($this->compileTimeClassConsts[$classLc][$constLc])) {
                continue;
            }
            $vis = $this->compileTimeClassConstVisibility[$parentLc][$constLc] ?? CfgFunc::FLAG_PUBLIC;
            if (($vis & CfgFunc::FLAG_PRIVATE) !== 0) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            $this->compileTimeClassConsts[$classLc][$constLc] = $stored;
            $this->compileTimeClassConstVisibility[$classLc][$constLc] = $vis;
            if (isset($this->compileTimeClassConstDeprecated[$parentLc][$constLc])) {
                $this->compileTimeClassConstDeprecated[$classLc][$constLc]
                    = $this->compileTimeClassConstDeprecated[$parentLc][$constLc];
            }
            if (isset($this->compileTimeClassConstNames[$parentLc][$constLc])) {
                $this->compileTimeClassConstNames[$classLc][$constLc]
                    = $this->compileTimeClassConstNames[$parentLc][$constLc];
            }
            if (isset($this->compileTimeEnumCaseConstNames[$parentLc][$constLc])) {
                $this->compileTimeEnumCaseConstNames[$classLc][$constLc] = true;
            }
        }
    }

    protected function applySealedMetadataFromOp(Op $op, OpCode $opcode): void
    {
        if (!$op->hasAttribute('compilerSealed')) {
            return;
        }
        $opcode->isSealed = true;
        $permits = $op->getAttribute('compilerSealedPermits');
        $opcode->sealedPermits = \is_array($permits) ? $permits : [];
    }

    /**
     * @param Operand[] $operands
     *
     * @return list<string>
     */
    protected function interfaceNamesFromOperands(array $operands): array
    {
        return $this->interfaceLcAndDisplayFromOperands($operands)[0];
    }

    /**
     * @param Operand[] $operands
     *
     * @return array{0: list<string>, 1: list<string>} lowercase names, source display names
     */
    protected function interfaceLcAndDisplayFromOperands(array $operands): array
    {
        $lcs = [];
        $displays = [];
        foreach ($operands as $operand) {
            $name = $this->staticNameFromOperand($operand);
            if (null === $name) {
                $this->throwCompileError('Interface name must be a compile-time class reference');
            }
            $display = ltrim($name, '\\');
            $displays[] = $display;
            $lcs[] = strtolower($display);
        }

        return [$lcs, $displays];
    }

    /**
     * Zend defers forbidden user-implement fatals to declaration site (#18781).
     */
    protected function defersRuntimeInterfaceImplementationCheck(Op\Stmt\ClassLike $class): bool
    {
        $className = $this->staticNameFromOperand($class->name);
        if (null === $className) {
            return false;
        }
        $parentLc = null;
        if ($class instanceof Op\Stmt\Class_ && null !== $class->extends) {
            $parentName = $this->staticNameFromOperand($class->extends);
            if (null !== $parentName) {
                $parentLc = strtolower(ltrim($parentName, '\\'));
            }
        }
        $isEnum = $class instanceof Op\Stmt\Enum_;

        return ImplementsHierarchyRuntimeCheck::requiresSourceOrderRegistration(
            ltrim($className, '\\'),
            $this->interfaceNamesFromOperands($class->implements),
            $parentLc,
            $isEnum
        );
    }

    /**
     * Classes whose DECLARE_CLASS must not be hoisted before preceding statements.
     *
     * - Forbidden implements (DateTimeInterface / reserved): fatals at DECLARE (#18781)
     * - Serializable: E_DEPRECATED + class_exists timing match Zend (#22000, #25109)
     * - Any implements: DECLARE must run after prior spl_autoload_register (#25624)
     * - Trait use: abstract residuals / trait bind fatals at DECLARE after prior stmts (#25912)
     */
    protected function requiresSourceOrderClassRegistration(Op\Stmt\ClassLike $class): bool
    {
        if ($this->defersRuntimeInterfaceImplementationCheck($class)) {
            return true;
        }

        if (\in_array('serializable', $this->interfaceNamesFromOperands($class->implements), true)) {
            return true;
        }

        // Keep trait-using classes in source order so preceding opcodes (echo, etc.) run
        // before zend_verify_abstract_class / trait bind fatals (#25912).
        if ($class instanceof Op\Stmt\Class_ && $this->classDeclaresTraitUse($class)) {
            return true;
        }

        // Keep implements classes in source order so autoload callbacks registered above
        // the declaration are visible (Zend early-binds types, not user autoload timing).
        return $class instanceof Op\Stmt\Class_ && [] !== $class->implements;
    }

    /**
     * Same-file classes that must DECLARE in source order, including subclasses of
     * those classes (#29552, #29566).
     *
     * @param list<Op> $ops
     * @return array<string, true> lowercase class names
     */
    protected function sourceOrderClassRegistrationLcs(array $ops): array
    {
        /** @var array<string, Op\Stmt\Class_> */
        $classes = [];
        /** @var array<string, ?string> child lc => parent lc when parent name is static */
        $extends = [];
        foreach ($ops as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $name = $this->staticNameFromOperand($child->name);
            if (null === $name) {
                continue;
            }
            $lc = strtolower(ltrim($name, '\\'));
            $classes[$lc] = $child;
            $parentLc = null;
            if (null !== $child->extends) {
                $parentName = $this->staticNameFromOperand($child->extends);
                if (null !== $parentName) {
                    $parentLc = strtolower(ltrim($parentName, '\\'));
                }
            }
            $extends[$lc] = $parentLc;
        }

        $sourceOrder = [];
        foreach ($classes as $lc => $class) {
            if ($this->requiresSourceOrderClassRegistration($class)) {
                $sourceOrder[$lc] = true;
            }
        }

        // Propagate to same-file subclasses so they are not hoisted before the parent (#29552, #29566).
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($extends as $lc => $parentLc) {
                if (isset($sourceOrder[$lc]) || null === $parentLc || !isset($sourceOrder[$parentLc])) {
                    continue;
                }
                $sourceOrder[$lc] = true;
                $changed = true;
            }
        }

        return $sourceOrder;
    }

    /**
     * @param array<string, true> $sourceOrderClassLcs
     */
    protected function classIsSourceOrderRegistration(Op\Stmt\Class_ $class, array $sourceOrderClassLcs): bool
    {
        $name = $this->staticNameFromOperand($class->name);
        if (null !== $name) {
            $lc = strtolower(ltrim($name, '\\'));
            if (isset($sourceOrderClassLcs[$lc])) {
                return true;
            }
        }

        return $this->requiresSourceOrderClassRegistration($class);
    }

    /**
     * True when the class body contains a `use Trait` statement (#25912).
     */
    protected function classDeclaresTraitUse(Op\Stmt\Class_ $class): bool
    {
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\TraitUse) {
                return true;
            }
        }

        return false;
    }

    protected function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }

    /**
     * PHP 8.4: interfaces may declare static properties only with hook syntax (#9754, zend_compile.c).
     */
    protected function interfaceStaticPropertyHookAllowed(Operand $nameOperand): bool
    {
        $propName = $this->staticNameFromOperand($nameOperand);
        $classLc = $this->compilingClassLc;
        if (null === $propName || null === $classLc || '' === $classLc) {
            return false;
        }

        return isset($this->propertyHookRegistry[$classLc][$propName])
            || isset($this->propertyHookRegistry[$classLc][strtolower($propName)]);
    }

    protected function literalScopeClassName(Operand $class): ?string
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return $class->value;
        }
        if ($class instanceof Operand\Variable) {
            return $this->literalScopeClassName($class->name);
        }
        if (null !== $class->original) {
            if ($class->original instanceof \PhpParser\Node\Name) {
                return $class->original->toString();
            }
            if ($class->original instanceof Operand) {
                return $this->literalScopeClassName($class->original);
            }
        }

        return $this->staticNameFromOperand($class);
    }

    /**
     * True when the enclosing function is a static method (no `$this`, #26252).
     *
     * `parent::staticMethod(...)` FCC must use the Class::method string path, not
     * `[$this, method]` — Zend/zend_compile.c ZEND_AST_CALLABLE_CONVERT.
     */
    protected function blockIsStaticMethodContext(Block $block): bool
    {
        $func = $block->func ?? null;
        if (null === $func) {
            return false;
        }
        if (null === ($func->class ?? null) || null === $func->class->value || '' === $func->class->value) {
            return false;
        }

        return 0 !== (($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC);
    }

    /** True when StaticCall source class is the `parent` keyword (#6735, zend_compile.c). */
    protected function staticCallUsesParentScope(Operand $class): bool
    {
        return 'parent' === $this->firstClassCallableScopeKeyword($class);
    }

    /**
     * FCC / static-call scope keyword: `parent` / `self` / `static`, else null (#17655, #26630).
     *
     * @return null|'parent'|'self'|'static'
     */
    protected function firstClassCallableScopeKeyword(Operand $class): ?string
    {
        $name = $this->literalScopeClassName($class);
        if (null !== $name) {
            $lc = strtolower($name);
            if ('parent' === $lc || 'self' === $lc || 'static' === $lc) {
                return $lc;
            }
        }
        $current = $class;
        while (null !== $current) {
            if ($current instanceof Operand\Variable && $current->name instanceof Operand\Literal) {
                $lc = strtolower((string) $current->name->value);
                if ('parent' === $lc || 'self' === $lc || 'static' === $lc) {
                    return $lc;
                }
            }
            if (property_exists($current, 'original') && null !== $current->original) {
                if ($current->original instanceof \PhpParser\Node\Name) {
                    $parts = $current->original->getParts();
                    if (1 === \count($parts)) {
                        $lc = strtolower($parts[0]);
                        if ('parent' === $lc || 'self' === $lc || 'static' === $lc) {
                            return $lc;
                        }
                    }
                }
                if ($current->original instanceof Operand) {
                    $current = $current->original;
                    continue;
                }
            }

            break;
        }

        return null;
    }

    /**
     * True when the static fetch class operand is an instance (new expr or variable), not a class name (#5477).
     */
    protected function staticPropertyClassIsObjectExpression(Operand $class): bool
    {
        if ($class instanceof Operand\Literal && is_string($class->value)) {
            return false;
        }
        $current = $class;
        while (null !== $current) {
            if ($current instanceof Op\Expr\New_) {
                return true;
            }
            if ($current instanceof Operand\Temporary || $current instanceof Operand\Variable) {
                $next = $current->original;
                $current = $next instanceof Operand ? $next : null;

                continue;
            }

            break;
        }

        return $class instanceof Operand\Temporary || $class instanceof Operand\Variable;
    }

    /**
     * @return list<string>
     */
    protected function intersectionNamesFromCfgType(Op\Type\Intersection $type, ?Block $block = null): array
    {
        $names = [];
        foreach ($type->types as $member) {
            $name = null !== $block && $member instanceof Op\Type\Reference
                ? $this->resolvedDnfReferenceNameFromCfgType($member, $block)
                : $this->staticNameFromCfgType($member);
            if (null === $name) {
                $this->throwCompileError('Intersection type members must be interface names');
            }
            $names[] = strtolower(ltrim($name, '\\'));
        }

        return $names;
    }

    /**
     * True when declared type uses union/intersection/nullable DNF shape (#3094).
     * Plain scalars like `int` stay on paramTypeConstraints / typeConstraint paths.
     */
    /**
     * MCJIT execute for DNF typed property scripts needs at least one try/catch region
     * (empty body is enough — see compliance dnf_property* vs dnf_new_empty_try).
     */
    private function appendMcjitDnfPropertyTryEpilogue(Block $main): void
    {
        $merge = new Block($main->orig);
        $merge->func = $main->func;
        $merge->inheritUndefinedLocals = true;
        $merge->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));

        $tryBody = new Block($main->orig);
        $tryBody->func = $main->func;
        $tryBody->inheritUndefinedLocals = true;
        $tryJump = new OpCode(OpCode::TYPE_JUMP);
        $tryJump->block1 = $merge;
        $tryBody->addOpCode($tryJump);

        $catchBody = new Block($main->orig);
        $catchBody->func = $main->func;
        $catchBody->inheritUndefinedLocals = true;
        $catchJump = new OpCode(OpCode::TYPE_JUMP);
        $catchJump->block1 = $merge;
        $catchBody->addOpCode($catchJump);

        $tryOp = new OpCode(OpCode::TYPE_TRY);
        $tryOp->block1 = $tryBody;
        $tryOp->block2 = $merge;
        $main->addOpCode($tryOp);

        $catchOp = new OpCode(OpCode::TYPE_CATCH);
        $catchOp->block1 = $catchBody;
        $catchOp->block2 = $merge;
        $catchOp->catchTypes = 'throwable';
        $main->addOpCode($catchOp);
    }

    protected function cfgTypeUsesDnfShape(?Op\Type $declared): bool
    {
        if (null === $declared) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }
        if ($declared instanceof Op\Type\Nullable) {
            return true;
        }

        return false;
    }

    protected function cfgTypeIsStandaloneNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }

        return $type instanceof Op\Type\Literal && 'never' === strtolower($type->name);
    }

    /**
     * Zend void type node or literal — standalone only (returns / not properties).
     */
    protected function cfgTypeIsStandaloneVoid(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Void_) {
            return true;
        }

        return $type instanceof Op\Type\Literal && 'void' === strtolower($type->name);
    }

    protected function cfgTypeContainsNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }
        if ($type instanceof Op\Type\Literal && 'never' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNever($type->subtype);
        }

        return false;
    }

    /**
     * True when void appears anywhere in a declared type tree (union / nullable / intersection).
     */
    protected function cfgTypeContainsVoid(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Void_) {
            return true;
        }
        if ($type instanceof Op\Type\Literal && 'void' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsVoid($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsVoid($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsVoid($type->subtype);
        }

        return false;
    }

    /**
     * True when `never` appears inside an intersection (not a top-level union arm only).
     */
    protected function cfgTypeContainsNeverInIntersection(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsStandaloneNever($member)) {
                    return true;
                }
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNeverInIntersection($type->subtype);
        }

        return false;
    }

    protected function cfgTypeIsNullLiteral(?Op\Type $type): bool
    {
        return $type instanceof Op\Type\Literal && 'null' === strtolower($type->name);
    }

    protected function cfgTypeIsLiteralBoolName(?Op\Type $type, string $name): bool
    {
        return $type instanceof Op\Type\Literal && $name === strtolower($type->name);
    }

    protected function cfgTypeContainsLiteralBool(?Op\Type $type, string $name): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsLiteralBoolName($type, $name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsLiteralBool($type->subtype, $name);
        }

        return false;
    }

    protected function cfgTypeContainsNull(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsNullLiteral($type)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNull($type->subtype);
        }

        return false;
    }

    /**
     * Zend: only class/interface types may appear in intersection types (#26401);
     * duplicate intersection members are redundant (#26605); equivalent DNF arms
     * are redundant (#26606); duplicate union arms are redundant (#26556);
     * object + class/interface is redundant (#26563);
     * DNF intersection arm proper-subset is more restrictive (#26607).
     */
    protected function assertIntersectionTypeMembers(?Op\Type $type): void
    {
        $invalid = IntersectionTypeMemberCompileCheck::findInvalidMemberName($type);
        if (null !== $invalid) {
            $this->throwCompileError(IntersectionTypeMemberCompileCheck::messageFor($invalid));
        }
        $duplicate = IntersectionTypeMemberCompileCheck::findDuplicateMemberName($type);
        if (null !== $duplicate) {
            $this->throwCompileError(IntersectionTypeMemberCompileCheck::duplicateMessageFor($duplicate));
        }
        $dnfRedundant = RedundantDnfArmCompileCheck::findRedundantArmPair($type);
        if (null !== $dnfRedundant) {
            $this->throwCompileError(RedundantDnfArmCompileCheck::messageFor(
                $dnfRedundant[0],
                $dnfRedundant[1]
            ));
        }
        $unionDup = DuplicateUnionMemberCompileCheck::findDuplicateMemberName($type);
        if (null !== $unionDup) {
            $this->throwCompileError(DuplicateUnionMemberCompileCheck::duplicateMessageFor($unionDup));
        }
        $subsetMsg = RedundantDnfArmSubsetCompileCheck::findRedundantMessage($type);
        if (null !== $subsetMsg) {
            $this->throwCompileError($subsetMsg);
        }
        if (RedundantObjectClassUnionCompileCheck::isRedundant($type)) {
            $label = $this->dnfTypeLabelFromCfgType($type);
            $this->throwCompileError(RedundantObjectClassUnionCompileCheck::messageFor($label));
        }
    }

    /**
     * Zend zend_compile_type — void is only valid as a standalone return type (#26517).
     * Unions / nullable void → "Void can only be used as a standalone type" (capital V).
     * Intersection members are rejected earlier by {@see assertIntersectionTypeMembers}.
     */
    protected function assertFunctionSignatureVoidType(?Op\Type $type): void
    {
        if (!$this->cfgTypeContainsVoid($type)) {
            return;
        }
        if ($this->cfgTypeIsStandaloneVoid($type)) {
            return;
        }
        $this->throwCompileError('Void can only be used as a standalone type');
    }

    /**
     * Zend zend_handle_never_type — never is only valid as a standalone signature type (#14334).
     */
    protected function assertFunctionSignatureNeverType(?Op\Type $type): void
    {
        if (!$this->cfgTypeContainsNever($type)) {
            return;
        }
        if ($this->cfgTypeIsStandaloneNever($type)) {
            return;
        }
        // Prefer intersection-specific wording when never appears under `&` (#26401).
        if ($this->cfgTypeContainsNeverInIntersection($type)) {
            $this->throwCompileError(IntersectionTypeMemberCompileCheck::messageFor('never'));
        }
        $this->throwCompileError('never can only be used as a standalone type');
    }

    /**
     * Zend zend_compile_type — mixed already includes null; mixed is standalone-only (#26554).
     *
     * {@code ?mixed} → "Type mixed cannot be marked as nullable since mixed already includes null"
     * {@code mixed|null} / {@code mixed|T} → "Type mixed can only be used as a standalone type"
     * Bare {@code Mixed_} (php-cfg untyped) is not a user-written mixed hint — callers skip it.
     */
    protected function assertMixedTypeRules(?Op\Type $type): void
    {
        if (null === $type) {
            return;
        }
        if ($this->cfgTypeIsNullableMixed($type)) {
            $this->throwCompileError('Type mixed cannot be marked as nullable since mixed already includes null');
        }
        if ($this->cfgTypeContainsNonStandaloneMixed($type)) {
            $this->throwCompileError('Type mixed can only be used as a standalone type');
        }
    }

    protected function cfgTypeIsPureMixed(?Op\Type $type): bool
    {
        if ($type instanceof Op\Type\Literal && 'mixed' === strtolower($type->name)) {
            return true;
        }

        return $type instanceof Op\Type\Mixed_;
    }

    protected function cfgTypeIsNullableMixed(?Op\Type $type): bool
    {
        return $type instanceof Op\Type\Nullable && $this->cfgTypeIsPureMixed($type->subtype);
    }

    /**
     * True when user-written {@code mixed} appears in a union/intersection (not as a lone type).
     * Nullable-of-pure-mixed is handled by {@see cfgTypeIsNullableMixed} instead.
     */
    protected function cfgTypeContainsNonStandaloneMixed(?Op\Type $type): bool
    {
        if (null === $type || $this->cfgTypeIsPureMixed($type)) {
            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            if ($this->cfgTypeIsPureMixed($type->subtype)) {
                return false;
            }

            return $this->cfgTypeContainsNonStandaloneMixed($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            $hasMixed = false;
            $hasOther = false;
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsPureMixed($member) || $this->cfgTypeContainsPureMixed($member)) {
                    $hasMixed = true;
                } else {
                    $hasOther = true;
                }
                if ($this->cfgTypeContainsNonStandaloneMixed($member)) {
                    return true;
                }
            }

            return $hasMixed && $hasOther;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsPureMixed($member) || $this->cfgTypeContainsPureMixed($member)) {
                    return true;
                }
                if ($this->cfgTypeContainsNonStandaloneMixed($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function cfgTypeContainsPureMixed(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsPureMixed($type)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_ || $type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsPureMixed($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsPureMixed($type->subtype);
        }

        return false;
    }

    /**
     * True when callable appears anywhere in a declared type tree (union / nullable / intersection).
     */
    protected function cfgTypeContainsCallable(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Literal && 'callable' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsCallable($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsCallable($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsCallable($type->subtype);
        }

        return false;
    }

    /**
     * Zend rejects `parent` type atoms when the current class/enum/interface has no parent (#26540).
     * Traits keep an unresolved `parent` keyword (skipped via {@see ClassCompileRegistry::isTrait}).
     */
    protected function rejectParentTypeHintWithoutParent(?Op\Type $type): void
    {
        if (!PseudoClassTypeHintCompileCheck::containsKeyword($type, 'parent')) {
            return;
        }
        $declaringLc = $this->compilingClassLc;
        if (null === $declaringLc || '' === $declaringLc) {
            return;
        }
        if ($this->classCompileRegistry->isTrait($declaringLc)) {
            return;
        }
        if (null !== $this->compilingClassParentLc && '' !== $this->compilingClassParentLc) {
            return;
        }
        $resolved = $this->classCompileRegistry->parentDisplayName($declaringLc);
        if (null !== $resolved && '' !== $resolved) {
            return;
        }
        $this->throwCompileError(EnumParentCompileCheck::MESSAGE);
    }

    /**
     * Zend zend_handle_property_type — void/never/callable invalid on properties, including unions
     * (#6967, #7052, #26518, #26516).
     */
    protected function assertPropertyDeclaredType(?Op\Type $type, string $propName): void
    {
        $this->rejectParentTypeHintWithoutParent($type);
        $this->assertIntersectionTypeMembers($type);
        $this->assertMixedTypeRules($type);
        // void before never — Zend capitalizes "Void" in the standalone-only message.
        if ($this->cfgTypeContainsVoid($type)) {
            if ($this->cfgTypeIsStandaloneVoid($type)) {
                $class = $this->compilingClassDisplayName ?? 'class';
                $this->throwCompileError(sprintf('Property %s::$%s cannot have type void', $class, $propName));
            }
            $this->throwCompileError('Void can only be used as a standalone type');
        }
        if ($this->cfgTypeContainsNever($type)) {
            if ($this->cfgTypeIsStandaloneNever($type)) {
                $class = $this->compilingClassDisplayName ?? 'class';
                $this->throwCompileError(sprintf('Property %s::$%s cannot have type never', $class, $propName));
            }
            if ($this->cfgTypeContainsNeverInIntersection($type)) {
                $this->throwCompileError(IntersectionTypeMemberCompileCheck::messageFor('never'));
            }
            $this->throwCompileError('never can only be used as a standalone type');
        }
        // callable (including ?callable / unions) — Zend prints the full type string (#26516).
        if ($this->cfgTypeContainsCallable($type)) {
            $class = $this->compilingClassDisplayName ?? 'class';
            $label = DnfType::zendTypeErrorLabel($this->dnfTypeLabelFromCfgType($type));
            $this->throwCompileError(sprintf('Property %s::$%s cannot have type %s', $class, $propName, $label));
        }
    }

    protected function dnfTypeLabelFromCfgType(?Op\Type $declared, ?Block $block = null): string
    {
        if (null === $declared) {
            return 'mixed';
        }

        return DnfType::labelFromCfgType(
            $declared,
            fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t, $block),
            fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t, $block),
            null !== $block
                ? fn (Op\Type\Reference $t) => $this->resolvedDnfReferenceNameFromCfgType($t, $block)
                : fn (Op\Type\Reference $t) => $this->staticNameFromCfgType($t)
        );
    }

    protected function intersectionDisplayFromCfgType(Op\Type\Intersection $type, ?Block $block = null): string
    {
        $names = [];
        foreach ($type->types as $member) {
            $name = null !== $block && $member instanceof Op\Type\Reference
                ? $this->resolvedDnfReferenceNameFromCfgType($member, $block)
                : $this->staticNameFromCfgType($member);
            if (null === $name) {
                $this->throwCompileError('Intersection type members must be interface names');
            }
            $names[] = ltrim($name, '\\');
        }

        return implode('&', $names);
    }

    protected function staticNameFromCfgType(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Literal) {
            return $type->name;
        }
        if ($type instanceof Op\Type\Reference) {
            return $this->staticNameFromOperand($type->declaration);
        }

        return null;
    }

    /**
     * Resolve self/parent in DNF union/nullable arms to the declaring class (zend_compile.c).
     * Leave late-bound {@code static} unresolved so runtime LSB can apply (#25947).
     */
    protected function resolvedDnfReferenceNameFromCfgType(Op\Type\Reference $type, Block $block): ?string
    {
        $name = $this->staticNameFromCfgType($type);
        if (null === $name || '' === $name) {
            return null;
        }
        $lc = strtolower(ltrim($name, '\\'));
        if ('static' === $lc) {
            return $name;
        }
        if ('self' === $lc || 'parent' === $lc) {
            $resolved = $this->resolveTypeHintClassName($name, $block);

            return (null !== $resolved && '' !== $resolved) ? $resolved : $name;
        }

        return $name;
    }

    protected function resolveTypeHintClassName(string $className, Block $block): ?string
    {
        $lexical = ltrim($className, '\\');
        $lc = strtolower($lexical);
        if ('self' === $lc || 'static' === $lc) {
            // Trait `self` stays the keyword; trait import rebinds to the using class
            // (zend_inheritance.c / zend_traits.c, #31744). Baking the trait name here
            // makes TypeError demand T instead of the composing class.
            if ('self' === $lc) {
                $declaringLc = $this->declaringClassLcForTypeHint($block);
                if (null !== $declaringLc && $this->classCompileRegistry->isTrait($declaringLc)) {
                    return $lexical;
                }
            }

            return $this->declaringClassDisplayNameForTypeHint($block);
        }
        if ('parent' === $lc) {
            $declaringLc = $this->declaringClassLcForTypeHint($block);
            if (null === $declaringLc) {
                return $lexical;
            }
            // Traits keep unresolved `parent` (Zend Reflection shows the keyword) (#26540).
            if ($this->classCompileRegistry->isTrait($declaringLc)) {
                return $lexical;
            }
            $resolved = $this->classCompileRegistry->parentDisplayName($declaringLc);
            if ((null === $resolved || '' === $resolved)
                && $declaringLc === $this->compilingClassLc
                && null !== $this->compilingClassParentLc
                && '' !== $this->compilingClassParentLc) {
                $resolved = $this->classCompileRegistry->traitDisplayName($this->compilingClassParentLc);
            }
            if (null !== $resolved && '' !== $resolved) {
                return $resolved;
            }
            // Class/enum/interface/anonymous without a parent — Zend compile fatal (#26540).
            if ($declaringLc === $this->compilingClassLc) {
                $this->throwCompileError(EnumParentCompileCheck::MESSAGE);
            }

            return $lexical;
        }

        return $lexical;
    }

    protected function declaringClassDisplayNameForTypeHint(Block $block): ?string
    {
        if (null !== $this->compilingClassDisplayName) {
            return $this->compilingClassDisplayName;
        }
        if (null !== $block->func && null !== $block->func->class) {
            $name = $this->staticNameFromOperand($block->func->class);

            return null !== $name ? ltrim($name, '\\') : null;
        }
        if (null !== $this->evalClassScopeDisplay && '' !== $this->evalClassScopeDisplay) {
            return $this->evalClassScopeDisplay;
        }

        return null;
    }

    protected function declaringClassLcForTypeHint(Block $block): ?string
    {
        if (null !== $this->compilingClassLc) {
            return $this->compilingClassLc;
        }
        if (null !== $block->func && null !== $block->func->class) {
            $name = $this->staticNameFromOperand($block->func->class);

            return null !== $name ? strtolower(ltrim($name, '\\')) : null;
        }
        if (null !== $this->evalClassScopeLc && '' !== $this->evalClassScopeLc) {
            return $this->evalClassScopeLc;
        }

        return null;
    }

    protected function assertParamDeclaredType(?Op\Type $declared, Block $block, CfgFunc $func): void
    {
        $this->rejectPseudoClassTypeHintOutsideClassScope($declared, $block, $func);
        $this->rejectParentTypeHintWithoutParent($declared);
        $this->assertIntersectionTypeMembers($declared);
        // void before never — Zend prefers "Void can only…" for void|never params (#26517).
        $this->assertFunctionSignatureVoidType($declared);
        $this->assertFunctionSignatureNeverType($declared);
        $this->assertMixedTypeRules($declared);
        if ($this->cfgTypeIsStandaloneVoid($declared)) {
            $this->throwCompileError('void cannot be used as a parameter type');
        }
        if ($this->cfgTypeIsStandaloneNever($declared)) {
            $this->throwCompileError('never cannot be used as a parameter type');
        }
    }

    protected function applyParamDeclaredType(Op\Expr\Param $param, Block $block, int $slot, bool $variadicElement = false): void
    {
        $declared = $param->declaredType;
        if (null === $block->func) {
            throw new \LogicException('applyParamDeclaredType requires block func');
        }
        $this->assertParamDeclaredType($declared, $block, $block->func);
        if (null !== $declared) {
            $block->paramDeclaredTypes[$slot] = $declared;
        }
        if ($declared instanceof Op\Type\Reference) {
            $className = $this->staticNameFromCfgType($declared);
            if (null !== $className && '' !== $className) {
                $resolved = $this->resolveTypeHintClassName($className, $block);
                if (null !== $resolved && '' !== $resolved) {
                    if ($variadicElement) {
                        $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                    } else {
                        $block->paramTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                        $block->paramClassConstraints[$slot] = $resolved;
                        // Zend TypeError prints the resolved class for self/parent (zend_execute_API.c);
                        // keep unresolved trait `parent` as the keyword (#29930; mirrors return #29911/#29912).
                        $block->paramDeclaredTypeLabels[$slot] = ltrim($resolved, '\\');
                    }
                }
            }

            return;
        }
        if ($declared instanceof Op\Type\Intersection) {
            $display = $this->intersectionDisplayFromCfgType($declared, $block);
            if ($variadicElement) {
                $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                $block->paramVariadicElementIntersectionConstraints[$slot] = $this->intersectionNamesFromCfgType($declared, $block);
                $block->paramVariadicElementIntersectionDisplayLabels[$slot] = $display;
            } else {
                $block->paramTypeConstraints[$slot] = Variable::TYPE_OBJECT;
                $block->paramIntersectionConstraints[$slot] = $this->intersectionNamesFromCfgType($declared, $block);
                $block->paramIntersectionDisplayLabels[$slot] = $display;
            }

            return;
        }
        $arraySpec = $this->genericArraySpecFromCfgType($declared);
        if (null !== $arraySpec) {
            if ($variadicElement) {
                $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_ARRAY;
                $block->paramVariadicElementGenericArrayTypeSpecs[$slot] = $arraySpec;
            } else {
                $block->paramTypeConstraints[$slot] = Variable::TYPE_ARRAY;
                $block->paramGenericArrayTypeSpecs[$slot] = $arraySpec;
            }

            return;
        }
        if ($this->cfgTypeUsesDnfShape($declared)) {
            $dnfArms = DnfType::armsFromCfgType(
                $declared,
                fn (Op\Type\Intersection $t) => $this->intersectionNamesFromCfgType($t, $block),
                fn (Op\Type\Intersection $t) => $this->intersectionDisplayFromCfgType($t, $block),
                fn (Op\Type\Reference $t) => $this->resolvedDnfReferenceNameFromCfgType($t, $block)
            );
            if (DnfType::hasConstraints($dnfArms)) {
                if ($variadicElement) {
                    $block->paramVariadicElementDnfConstraints[$slot] = $dnfArms;
                } else {
                    $block->paramDnfConstraints[$slot] = $dnfArms;
                }

                return;
            }
        }
        if ($declared instanceof Op\Type\Literal) {
            $declName = strtolower($declared->name);
            if ('true' === $declName || 'false' === $declName) {
                if ($variadicElement) {
                    $block->paramVariadicElementTypeConstraints[$slot] = Variable::TYPE_BOOLEAN;
                } else {
                    $block->paramTypeConstraints[$slot] = Variable::TYPE_BOOLEAN;
                    $block->paramLiteralBoolTypes[$slot] = $declName;
                }

                return;
            }
            if ('iterable' === $declName) {
                $block->paramIterableSlots[$slot] = true;

                return;
            }
            if ('callable' === $declName) {
                $block->paramCallableSlots[$slot] = true;

                return;
            }
            if ('mixed' !== $declName) {
                $rawType = Type::fromDecl($declared->name);
                $mapped = Variable::mapFromType($rawType);
                if ($mapped !== Variable::TYPE_UNDEFINED) {
                    if ($variadicElement) {
                        $block->paramVariadicElementTypeConstraints[$slot] = $mapped;
                    } else {
                        $block->paramTypeConstraints[$slot] = $mapped;
                    }
                    // php-cfg leaves Param result Temporary->type null; stamp the declared
                    // PHPTypes so JIT fromOp allocates a native slot (int/float/bool), not a
                    // __value__ box. Without this, constructor promotion stores a value-box
                    // pointer into a native-long property and reads back garbage (#24008).
                    $operand = $block->getOperand($slot);
                    if (null !== $operand && null === $operand->type) {
                        $operand->type = $rawType;
                    }
                }
            }
        }
    }

    protected function declNameFromCfgType(?Op\Type $declared): ?string
    {
        if ($declared instanceof Op\Type\Literal) {
            return $declared->name;
        }
        if ($declared instanceof Op\Type\Reference) {
            return $this->staticNameFromOperand($declared->declaration);
        }

        return null;
    }

    protected function genericArraySpecFromCfgType(?Op\Type $declared): ?GenericArrayTypeSpec
    {
        $name = $this->declNameFromCfgType($declared);

        return null !== $name ? GenericArrayTypeSpec::tryParseDeclName($name) : null;
    }

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

    protected function compileClassConstDeclaration(Op\Terminal\Const_ $child, Block $result): void
    {
        $this->rejectStaticScopeInCompileTimeConstExpr($child->valueBlock, $child, $child->value);
        $constName = $this->staticNameFromOperand($child->name);
        $this->rejectReservedClassConstName($constName, $child);
        if (null !== $constName && null !== $this->compilingClassLc) {
            // Case-sensitive — const A and const a are distinct (#25929).
            $constKey = ClassConstName::key($constName);
            if (isset($this->compileTimeClassConstEmitted[$this->compilingClassLc][$constKey])) {
                // Idempotent re-parse when a JIT helper was already inlined from require_once (#9753, #1492).
                return;
            }
        }
        $valueSlot = $this->tryFoldClassConstValueSlot($child, $result);
        if (null === $valueSlot) {
            $this->compileOps($child->valueBlock->children, $result);
            $valueSlot = $this->compileOperand($child->value, $result, true);
        }
        $typeSlot = null;
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            if (null !== $constName) {
                $result->classConstDeclaredTypes[ClassConstName::key($constName)] = $child->declaredType;
            }
            if (!$this->cfgDeclaredTypeIsMixed($child->declaredType)) {
                $this->rejectTypedClassConstantIfUnsupported($child->name);
                $this->rejectTypedTraitConstantIfUnsupported($child->name);
                $this->rejectTypedInterfaceConstantIfUnsupported($child->name);
                $this->assertIntersectionTypeMembers($child->declaredType);
                $declared = $this->typeFromClassConstDecl($child);
                $typeSlot = $this->compileTypeConstrainedVariable($result, $declared, $child->declaredType);
                if (isset($result->constants[$valueSlot])) {
                    $this->verifyClassConstCompileTimeType(
                        $child->name,
                        $result->constants[$valueSlot],
                        $typeSlot,
                        $result
                    );
                }
            }
        }
        $constOp = new OpCode(
            OpCode::TYPE_DECLARE_CLASS_CONST,
            $this->compileOperand($child->name, $result, true),
            $valueSlot,
            $typeSlot
        );
        $constOp->classConstVisibilityFlags = property_exists($child, 'flags')
            ? (int) $child->flags
            : CfgFunc::FLAG_PUBLIC;
        if ($this->cfgTerminalConstIsEnumCase($child)) {
            $constOp->isEnumCaseDeclare = true;
            if (null !== $this->compilingClassLc) {
                $constName = $this->staticNameFromOperand($child->name);
                if (null !== $constName) {
                    $this->compileTimeEnumCaseConstNames[$this->compilingClassLc][ClassConstName::key($constName)] = true;
                }
            }
        }
        $constOp->deprecatedMetadata = DeprecatedMetadata::fromOp($child);
        $this->assignAttributeMetadata($constOp, $child);
        $this->assignSourceMetadata($constOp, $child);
        AttributeNames::assertAttributeMetaClassTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertOverrideMethodTargetOnly($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($constOp->attributeNames, 'class constant', $constOp->attributeEntries);
        $result->addOpCode($constOp);
        if (null !== $this->compilingClassLc && null !== $constName) {
            $this->compileTimeClassConstEmitted[$this->compilingClassLc][ClassConstName::key($constName)] = true;
        }
        if (null !== $this->compilingClassLc && isset($result->constants[$valueSlot])) {
            $constName = $this->staticNameFromOperand($child->name);
            if (null !== $constName) {
                $backing = new Variable();
                $backing->copyFrom($result->constants[$valueSlot]);
                if ($constOp->isEnumCaseDeclare) {
                    $stored = $this->compileTimeEnumCaseVar(
                        $this->compilingClassDisplayName ?? $this->compilingClassLc,
                        $constName,
                        $backing,
                        $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null
                    );
                } else {
                    $stored = new Variable();
                    $stored->copyFrom($backing);
                }
                // Case-sensitive storage key (#25910 fetch / #25929 declare).
                $constKey = ClassConstName::key($constName);
                $this->compileTimeClassConsts[$this->compilingClassLc][$constKey] = $stored;
                $this->compileTimeClassConstNames[$this->compilingClassLc][$constKey] = $constName;
                $this->compileTimeClassConstVisibility[$this->compilingClassLc][$constKey]
                    = ClassConstVisibility::mask($constOp->classConstVisibilityFlags);
                if (null !== $constOp->deprecatedMetadata) {
                    $this->compileTimeClassConstDeprecated[$this->compilingClassLc][$constKey]
                        = $constOp->deprecatedMetadata;
                }
            }
        }
    }

    /**
     * Distinguish enum `case` from user `const` when php-cfg isEnumCase is missing (#5832).
     * Bare `const` without visibility has flags=0 like cases; trust isEnumCase when set (#6878).
     */
    private function cfgTerminalConstIsEnumCase(Op\Terminal\Const_ $child): bool
    {
        if (property_exists($child, 'isEnumCase')) {
            return $child->isEnumCase;
        }
        if (null === $this->compilingClassLc
            || !array_key_exists($this->compilingClassLc, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (property_exists($child, 'declaredType') && null !== $child->declaredType) {
            return false;
        }
        $flags = property_exists($child, 'flags') ? (int) $child->flags : 0;
        // Enum cases cannot be protected/private/final; those must be user `const`.
        if (0 !== ($flags & (\PHPCfg\Func::FLAG_PROTECTED | \PHPCfg\Func::FLAG_PRIVATE | \PHPCfg\Func::FLAG_FINAL))) {
            return false;
        }

        // When php-cfg omits isEnumCase (#5832), try to distinguish backed enum `case` from user `const`.
        // Heuristic: backed enum cases must have a scalar literal backing value of the enum's backed type.
        $backedType = $this->compileTimeEnumBackedTypes[$this->compilingClassLc] ?? null;
        if (null === $backedType) {
            // Unit enums: enum cases have no backing scalar; default to legacy heuristic.
            return 0 === $flags;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($child->value);
        if (null === $vm) {
            return false;
        }
        if ('int' === $backedType) {
            return Variable::TYPE_INTEGER === $vm->type;
        }
        if ('string' === $backedType) {
            return Variable::TYPE_STRING === $vm->type;
        }

        return false;
    }

    /**
     * Compile-time enum case singleton for folds (default args, class const inits; #5514).
     */
    private function compileTimeEnumCaseVar(
        string $enumName,
        string $caseName,
        Variable $backing,
        ?string $backedType
    ): Variable {
        $entry = new ClassEntry(ltrim($enumName, '\\'));
        $entry->isEnum = true;
        $entry->backedType = $backedType;
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        return EnumCaseSupport::compileTimeCaseVariable($entry, $caseName, $backing);
    }

    private function compileTimeStoredValueIsEnumCaseBackingScalar(
        string $lcClass,
        string $lcConst,
        Variable $stored
    ): bool {
        if (!array_key_exists($lcClass, $this->compileTimeEnumBackedTypes)) {
            return false;
        }
        if (!isset($this->compileTimeEnumCaseConstNames[$lcClass][$lcConst])) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
            return false;
        }

        return $stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING);
    }

    protected function tryFoldClassConstValueSlot(Op\Terminal\Const_ $terminal, Block $block): ?int
    {
        if (null !== $terminal->valueBlock && [] !== $terminal->valueBlock->children) {
            $children = $terminal->valueBlock->children;
            if (1 === \count($children) && $children[0] instanceof Op\Expr\Array_) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ClassConstFetch) {
                $vm = $this->tryFoldClassConstFetchDefault($children[0], $block, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (
                2 === \count($children)
                && $children[0] instanceof Op\Expr\ClassConstFetch
                && $children[1] instanceof Op\Expr\ArrayDimFetch
            ) {
                $vm = $this->tryFoldClassConstArraySubscriptExpr(
                    $children[0],
                    $children[1],
                    $block
                );
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr) {
                $vm = $this->tryFoldCompileTimeExprDefault($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            // Multi-op const-expr (e.g. SCALE * 2, LABEL . "y", -A, A ?? 5): php-cfg
            // emits ConstFetch then the operator. Class bodies are hoisted before
            // DECLARE_GLOBAL_CONST, so runtime CONST_FETCH would miss the global (#23997).
            foreach ($children as $child) {
                if (!$child instanceof Op\Expr) {
                    continue;
                }
                if (!property_exists($child, 'result')
                    || !$this->operandsReferToSameVariable($child->result, $terminal->value)
                ) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeExprDefault($child, $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            foreach ($children as $child) {
                if (!$child instanceof Op\Stmt\JumpIf) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeTernaryDefault(
                    $child,
                    $terminal->value,
                    $block,
                    $children,
                    true
                );
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            $vm = $this->tryFoldClassConstMatchValueBlock(
                $terminal->valueBlock,
                $terminal->value,
                $block,
                $children
            );
            if (null !== $vm) {
                return $block->registerConstant(new Operand\Temporary(), $vm);
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($terminal->value);
        if (null === $vm) {
            return null;
        }

        return $block->registerConstant(new Operand\Temporary(), $vm);
    }

    /**
     * Historical fold for lowered match() in class constant initializers (#9987).
     *
     * Unreachable for honest php-src-strict: match is not a const-expr
     * ({@see ThrowInClassConstCompileCheck}, #24904). Kept so accidental CFG shapes
     * still resolve rather than silently emitting a wrong constant.
     *
     * @param list<Op> $defaultBlockChildren
     */
    protected function tryFoldClassConstMatchValueBlock(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $subject = $this->extractClassConstMatchSubject($entry, $result, $block, $defaultBlockChildren);
        if (null === $subject) {
            return null;
        }

        return $this->evaluateClassConstMatchCfgBlock(
            $entry,
            $subject,
            $result,
            $block,
            $defaultBlockChildren,
            0
        );
    }

    private function extractClassConstMatchSubject(
        CfgBlock $entry,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        $start = 0;
        if (isset($entry->children[0]) && $this->isMatchSeedAssign($entry->children[0], $result)) {
            $start = 1;
        }
        $count = \count($entry->children);
        for ($i = $start; $i < $count; ++$i) {
            $child = $entry->children[$i];
            if (!$child instanceof Op\Expr\BinaryOp\Identical) {
                continue;
            }

            return $this->tryFoldCompileTimeOperandDefault(
                $child->left,
                $block,
                $defaultBlockChildren,
                true
            );
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchCfgBlock(
        CfgBlock $cfgBlock,
        Variable $subject,
        Operand $result,
        Block $block,
        array $defaultBlockChildren,
        int $startIndex
    ): ?Variable {
        $children = $cfgBlock->children;
        if (0 === $startIndex && isset($children[0]) && $this->isMatchSeedAssign($children[0], $result)) {
            $startIndex = 1;
        }
        $count = \count($children);
        for ($i = $startIndex; $i < $count; ++$i) {
            $child = $children[$i];
            if (
                $child instanceof Op\Expr\BinaryOp\Identical
                && isset($children[$i + 1])
                && $children[$i + 1] instanceof Op\Stmt\JumpIf
            ) {
                $jumpIf = $children[$i + 1];
                $pattern = $this->tryFoldCompileTimeOperandDefault(
                    $child->right,
                    $block,
                    $defaultBlockChildren,
                    true
                );
                if (null === $pattern) {
                    return null;
                }
                if ($subject->identicalTo($pattern)) {
                    return $this->evaluateClassConstMatchArmBlock(
                        $jumpIf->if,
                        $result,
                        $block,
                        $defaultBlockChildren
                    );
                }

                return $this->evaluateClassConstMatchCfgBlock(
                    $jumpIf->else,
                    $subject,
                    $result,
                    $block,
                    $defaultBlockChildren,
                    0
                );
            }
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    /**
     * @param list<Op> $defaultBlockChildren
     */
    private function evaluateClassConstMatchArmBlock(
        CfgBlock $armBlock,
        Operand $result,
        Block $block,
        array $defaultBlockChildren
    ): ?Variable {
        foreach ($armBlock->children as $child) {
            if ($child instanceof Op\Terminal\Throw_) {
                return null;
            }
            if ($child instanceof Op\Expr\Assign && $this->operandsReferToSameVariable($child->var, $result)) {
                return $this->tryFoldCompileTimeOperandDefault(
                    $child->expr,
                    $block,
                    $defaultBlockChildren,
                    true
                );
            }
        }

        return null;
    }

    private function isMatchSeedAssign(Op $op, Operand $result): bool
    {
        if (!$op instanceof Op\Expr\Assign) {
            return false;
        }
        if (!$this->operandsReferToSameVariable($op->var, $result)) {
            return false;
        }
        $lit = $this->vmVariableFromCfgLiteralOperand($op->expr);

        return null !== $lit && Variable::TYPE_STRING === $lit->type && '' === $lit->toString();
    }

    /**
     * Fold {@code self::ARR[1]} in class constant scalar expressions (#5465, zend_compile.c).
     */
    protected function tryFoldClassConstArraySubscriptExpr(
        Op\Expr\ClassConstFetch $fetch,
        Op\Expr\ArrayDimFetch $dimFetch,
        Block $block
    ): ?Variable {
        if (null === $dimFetch->dim) {
            return null;
        }
        $base = $this->tryFoldClassConstFetchDefault($fetch, $block);
        if (null === $base || !$base->is(Variable::TYPE_ARRAY)) {
            return null;
        }
        $dimVm = $this->vmVariableFromCfgLiteralOperand($dimFetch->dim);
        if (null === $dimVm) {
            return null;
        }
        $table = $base->toArray();
        if (!$table->keyExists($dimVm)) {
            return null;
        }
        $elem = $table->findVariable($dimVm, false);
        if (null === $elem) {
            return null;
        }
        $value = new Variable();
        $value->copyFrom($elem->resolveIndirect());

        return $value;
    }

    protected function typeFromClassConstDecl(Op\Terminal\Const_ $child): Type
    {
        if ($child->declaredType instanceof Op\Type\Literal) {
            return Type::fromDecl($child->declaredType->name);
        }
        if (null !== $child->declaredType) {
            return Type::fromTypeDecl($child->declaredType);
        }

        return Type::mixed();
    }

    protected function cfgDeclaredTypeIsMixed(?Op\Type $declaredType): bool
    {
        if (null === $declaredType) {
            return true;
        }
        if ($declaredType instanceof Op\Type\Mixed_) {
            return true;
        }

        return $declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name);
    }

    protected function verifyClassConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        $className = $this->compilingClassDisplayName;
        try {
            TypeCheck::assertClassConstantTypedValue(
                $value,
                $block->constants[$typeSlot],
                $constName,
                $className
            );
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    protected function verifyGlobalConstCompileTimeType(
        Operand $nameOp,
        Variable $value,
        int $typeSlot,
        Block $block
    ): void {
        if (!isset($block->constants[$typeSlot])) {
            return;
        }
        $constName = $nameOp instanceof Operand\Literal ? (string) $nameOp->value : 'constant';
        try {
            TypeCheck::assertGlobalConstantTypedValue($value, $block->constants[$typeSlot], $constName);
        } catch (\TypeError $e) {
            $this->throwCompileError($e->getMessage());
        }
    }

    /**
     * Zend 8.2 rejects typed class constants at parse time; enable at 8.3+ forward/stable (#12798, #22705).
     */
    protected function rejectTypedClassConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsTypedClassConstants()) {
            return;
        }
        if (null === $this->compilingClassLc) {
            return;
        }
        if (
            $this->classCompileRegistry->isTrait($this->compilingClassLc)
            || $this->classCompileRegistry->isInterface($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * Zend 8.2 rejects typed trait constants at parse time; enable at 8.3+ (#5212).
     */
    protected function rejectTypedTraitConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsTypedTraitConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isTrait($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
    }

    /**
     * Zend 8.2 rejects typed interface constants at parse time; enable at 8.3+ forward/stable (#5980, #7042, #24917).
     */
    protected function rejectTypedInterfaceConstantIfUnsupported(Operand $nameOp): void
    {
        if (CompilerVersion::supportsInterfaceTypedConstants()) {
            return;
        }
        if (
            null === $this->compilingClassLc
            || !$this->classCompileRegistry->isInterface($this->compilingClassLc)
        ) {
            return;
        }
        $constName = $this->staticNameFromOperand($nameOp) ?? 'constant';
        $this->throwCompileError(
            sprintf('syntax error, unexpected identifier "%s", expecting "="', $constName)
        );
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

    public function pushIncludeTargetCompile(): void
    {
        ++$this->includeTargetCompileDepth;
    }

    public function popIncludeTargetCompile(): void
    {
        if ($this->includeTargetCompileDepth > 0) {
            --$this->includeTargetCompileDepth;
        }
    }

    protected function pseudoClassInCompileScope(string $className, Block $block): bool
    {
        $lc = strtolower($className);
        if (!in_array($lc, ['self', 'parent', 'static'], true)) {
            return true;
        }
        if (null !== $this->compilingClassLc) {
            return true;
        }
        if (null !== $this->evalClassScopeLc) {
            return true;
        }
        if ($this->includeTargetCompileDepth > 0 && $block->isMainScript()) {
            return true;
        }

        return null !== $block->func && null !== $block->func->class;
    }

    /**
     * Zend zend_is_scope_known() when !CG(active_class_entry) (#32227, Zend/zend_compile.c).
     *
     * Scope is known-empty in a named free function. File/eval ({main}) inherit the
     * including/eval'ing class; closures can be rebound — both stay runtime.
     */
    protected function compileScopeKnowsNoClassEntry(Block $block): bool
    {
        $func = $block->func;
        if (null === $func) {
            return false;
        }
        if (((int) ($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0) {
            return false;
        }
        $name = $func->name;
        if ($name instanceof Operand\Literal) {
            $name = $name->value;
        }
        if (!is_string($name) || '' === $name || '{main}' === $name) {
            return false;
        }
        if (ClosureRichDisplayName::isClosureCfgName($name)) {
            return false;
        }

        return null === $func->class;
    }

    /**
     * Zend zend_ensure_valid_class_fetch_type() — self/parent/static::method in a free function
     * is a compile-time fatal even when the function is never called (#32227).
     */
    protected function rejectPseudoClassStaticCallOutsideClassScope(Op\Expr\StaticCall $expr, Block $block): void
    {
        $keyword = $this->firstClassCallableScopeKeyword($expr->class);
        $this->rejectPseudoClassFetchOutsideKnownClassScope($keyword, $block, $expr);
    }

    /**
     * Zend zend_compile_new() → zend_ensure_valid_class_fetch_type() — `new self/parent/static`
     * in a named free function is a compile-time fatal even when unused (#32252, re-#32227).
     */
    protected function rejectPseudoClassNewOutsideClassScope(Op\Expr\New_ $expr, Block $block): void
    {
        $keyword = $this->firstClassCallableScopeKeyword($expr->class);
        $this->rejectPseudoClassFetchOutsideKnownClassScope($keyword, $block, $expr);
    }

    /**
     * @param null|'parent'|'self'|'static' $keyword
     */
    protected function rejectPseudoClassFetchOutsideKnownClassScope(?string $keyword, Block $block, Op $source): void
    {
        if (null === $keyword) {
            return;
        }
        if ($this->pseudoClassInCompileScope($keyword, $block)) {
            return;
        }
        if (!$this->compileScopeKnowsNoClassEntry($block)) {
            return;
        }
        $sourceFile = $source->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError(
            PseudoClassTypeHintCompileCheck::messageFor($keyword),
            $sourceFile,
            $source->getLine()
        );
    }

    /**
     * Zend zend_compile.c — self/parent/static return & parameter types require class scope (#17480).
     *
     * @return never
     */
    protected function rejectPseudoClassTypeHintOutsideClassScope(?Op\Type $type, Block $block, CfgFunc $func): void
    {
        $keyword = PseudoClassTypeHintCompileCheck::findKeyword($type);
        if (null === $keyword) {
            return;
        }
        if ($this->pseudoClassInCompileScope($keyword, $block)) {
            return;
        }
        $callable = $func->callableOp;
        throw new CompileFatal(
            $callable instanceof Op ? ($callable->getFile() ?: 'unknown') : 'unknown',
            $callable instanceof Op ? max(1, $callable->getLine()) : 1,
            PseudoClassTypeHintCompileCheck::messageFor($keyword)
        );
    }

    protected function resolveDefaultClassConstScope(string $className, Block $block): ?string
    {
        $lc = strtolower($className);
        if ('self' === $lc || 'static' === $lc) {
            if (null !== $this->compilingClassLc) {
                return $this->compilingClassLc;
            }
            if (null !== $block->func && null !== $block->func->class) {
                $name = $this->staticNameFromOperand($block->func->class);

                return null !== $name ? strtolower(ltrim($name, '\\')) : null;
            }
            // eval() donor is declaring class — `static::` must not fold here (#31912, #19614).
            if ('self' === $lc && null !== $this->evalClassScopeLc) {
                return $this->evalClassScopeLc;
            }

            return null;
        }
        if ('parent' === $lc) {
            return null;
        }

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * Fold {@code self::class} / {@code parent::class} / {@code Named::class} to the FQCN string
     * (Zend zend_compile.c class name resolution; #26629, #3803).
     *
     * {@code static::class} returns null — late-static binding needs the runtime opcode (#19614).
     * Trait {@code self::class} / {@code parent::class} also return null — Zend retargets them to
     * the composing class at use-time (#26659, #19629, Zend/zend_traits.c); folding the trait
     * name would bake the wrong string into method bodies (regression from #26629).
     */
    protected function resolveCompileTimeClassPseudoConstFqcn(string $className, Block $block): ?string
    {
        $lc = strtolower(ltrim($className, '\\'));
        if ('static' === $lc) {
            return null;
        }
        if ('self' === $lc) {
            $declLc = $this->declaringClassLcForTypeHint($block);
            if (null !== $declLc && $this->classCompileRegistry->isTrait($declLc)) {
                return null;
            }
            $display = $this->declaringClassDisplayNameForTypeHint($block);

            return null !== $display && '' !== $display ? $display : null;
        }
        if ('parent' === $lc) {
            $declLc = $this->declaringClassLcForTypeHint($block);
            if (null !== $declLc && $this->classCompileRegistry->isTrait($declLc)) {
                return null;
            }
            if (null !== $this->compilingClassLc) {
                $parent = $this->compilingClassParentDisplayName();
                if (null === $parent || '' === $parent) {
                    throw new CompileFatal(
                        'unknown',
                        1,
                        EnumParentCompileCheck::MESSAGE
                    );
                }

                return $parent;
            }

            return null;
        }

        return ltrim($className, '\\');
    }

    /**
     * Zend message when {@code static::} appears in a property default (#26629, #31145).
     *
     * {@code static::class} keeps the more specific zend_compile.c diagnostic; other
     * {@code static::CONST} fetches use {@see ThrowInClassConstCompileCheck::STATIC_SCOPE_MESSAGE}.
     */
    protected function propertyDefaultStaticClassRejectMessage(Op\Stmt\Property $prop): ?string
    {
        $fetch = $this->findStaticScopeClassConstFetchInBlock($prop->defaultBlock);
        if (null === $fetch) {
            $root = null !== $prop->defaultVar ? $this->unwrapOperandChain($prop->defaultVar) : null;
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (null === $fetch) {
            return null;
        }

        return ThrowInClassConstCompileCheck::staticScopeRejectMessage($fetch);
    }

    /**
     * Reject {@code static::} in class-const / param-default / property-default const-exprs (#31145).
     */
    protected function rejectStaticScopeInCompileTimeConstExpr(
        ?CfgBlock $block,
        Op $site,
        ?Operand $value = null
    ): void {
        $fetch = $this->findStaticScopeClassConstFetchInBlock($block);
        if (null === $fetch && null !== $value) {
            $root = $this->unwrapOperandChain($value);
            if ($root instanceof Op\Expr\ClassConstFetch) {
                $fetch = $root;
            }
        }
        if (null === $fetch) {
            return;
        }
        $msg = ThrowInClassConstCompileCheck::staticScopeRejectMessage($fetch);
        if (null === $msg) {
            return;
        }
        $sourceFile = $fetch->getFile();
        if ('' === $sourceFile) {
            $sourceFile = $site->getFile();
        }
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $line = $fetch->getLine();
        if ($line < 1) {
            $line = $site->getLine();
        }
        throw new CompileFatal($sourceFile, max(1, $line), $msg);
    }

    protected function findStaticScopeClassConstFetchInBlock(?CfgBlock $block): ?Op\Expr\ClassConstFetch
    {
        if (null === $block) {
            return null;
        }
        $queue = [$block];
        $seen = new SplObjectStorage();
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ($seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->children ?? [] as $op) {
                if (!$op instanceof Op) {
                    continue;
                }
                if ($op instanceof Op\Expr\ClassConstFetch
                    && null !== ThrowInClassConstCompileCheck::staticScopeRejectMessage($op)
                ) {
                    return $op;
                }
                if ($op instanceof Op\Expr\Assign
                    && $op->expr instanceof Op\Expr\ClassConstFetch
                    && null !== ThrowInClassConstCompileCheck::staticScopeRejectMessage($op->expr)
                ) {
                    return $op->expr;
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }

        return null;
    }

    protected function compileTimeClassConstFetchCallerLc(Block $block): ?string
    {
        if (null !== $this->compilingClassLc) {
            return $this->compilingClassLc;
        }
        if (null !== $block->func && null !== $block->func->class) {
            $name = $this->staticNameFromOperand($block->func->class);

            return null !== $name ? strtolower(ltrim($name, '\\')) : null;
        }

        return null;
    }

    /**
     * Whether a compile-time class const value may be constant-folded at this site (#6784).
     */
    protected function compileTimeClassConstFetchAllowed(
        string $declaringClassLc,
        string $constLc,
        Block $block
    ): bool {
        $vis = $this->compileTimeClassConstVisibility[$declaringClassLc][$constLc] ?? CfgFunc::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return true;
        }
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $this->compileTimeClassConstFetchCallerLc($block),
                $declaringClassLc,
                $this->classCompileRegistry->traitDisplayName($declaringClassLc),
                $constLc,
                fn (string $callerLc, string $ancestorLc): bool => $this->classCompileRegistry->isClassSubtypeOf(
                    $callerLc,
                    $ancestorLc
                )
            );
        } catch (\LogicException) {
            return false;
        }

        return true;
    }

    /**
     * Non-nullable declared type with `= null` default (php-src implicit nullable, #4449).
     */
    protected function paramIsImplicitNullable(Op\Expr\Param $param, ?int $defaultSlot, Block $block): bool
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return false;
        }
        if ($param->declaredType instanceof Op\Type\Nullable) {
            return false;
        }
        if ($this->cfgTypeUsesDnfShape($param->declaredType)) {
            return false;
        }
        $default = $block->constants[$defaultSlot] ?? null;

        return null !== $default && Variable::TYPE_NULL === $default->type;
    }

    /**
     * Zend compile-time E_DEPRECATED for optional-before-required params (#31904).
     *
     * php-src: Zend/zend_compile.c {@code zend_compile_params} — last required (non-default,
     * non-variadic) parameter names the message; PHP 5-style {@code Type $p = null} is skipped
     * ({@code forced_allow_nullable} / implicit nullable). PHP 8.4+ prefixes the callable label.
     *
     * @param list<Op\Expr\Param> $params
     */
    protected function maybeEmitOptionalBeforeRequiredParamDeprecations(array $params, Block $block): void
    {
        $lastRequired = -1;
        foreach ($params as $i => $param) {
            if ($param->variadic) {
                continue;
            }
            if (null === $param->defaultVar && null === $param->defaultBlock) {
                $lastRequired = $i;
            }
        }
        if ($lastRequired < 0) {
            return;
        }
        $requiredParam = $params[$lastRequired] ?? null;
        if (!$requiredParam instanceof Op\Expr\Param) {
            return;
        }
        $requiredName = $this->displayParamName($requiredParam);
        $callablePrefix = '';
        if (CompilerVersion::supportsOptionalBeforeRequiredCallablePrefix()) {
            $callablePrefix = $this->displayCallableNameForCompileDeprecation($block).'(): ';
        }
        foreach ($params as $i => $param) {
            if ($i >= $lastRequired || $param->variadic) {
                continue;
            }
            if (null === $param->defaultVar && null === $param->defaultBlock) {
                continue;
            }
            if ($this->paramSkipsOptionalBeforeRequiredDeprecation($param, $block, (int) $i)) {
                continue;
            }
            $this->emitCompileTimeInternalDeprecated(
                sprintf(
                    '%sOptional parameter %s declared before required parameter %s is implicitly treated as a required parameter',
                    $callablePrefix,
                    $this->displayParamName($param),
                    $requiredName
                ),
                $block,
                max(0, $param->getLine())
            );
        }
    }

    /**
     * PHP 5-style {@code Type $param = null} is not a true optional (zend_compile.c, #31904).
     */
    protected function paramSkipsOptionalBeforeRequiredDeprecation(Op\Expr\Param $param, Block $block, int $paramIdx): bool
    {
        $defaultSlot = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV === $op->type && (int) $op->arg2 === $paramIdx) {
                $defaultSlot = $op->arg3;
                break;
            }
        }
        if (null !== $defaultSlot) {
            return $this->paramIsImplicitNullable($param, $defaultSlot, $block);
        }
        if (null === $param->declaredType || $param->declaredType instanceof Op\Type\Nullable) {
            return false;
        }
        if ($this->cfgTypeUsesDnfShape($param->declaredType)) {
            return false;
        }

        return $this->paramAstDefaultIsNull($param);
    }

    /**
     * Zend E_DEPRECATED during CFG compile (eval: VM running; file-level: {@see $vmContext}).
     */
    protected function emitCompileTimeInternalDeprecated(string $message, Block $block, int $line): void
    {
        $vm = VM::running();
        $context = $vm instanceof VM ? $vm->context : $this->vmContext;
        if (!$context instanceof VMContext) {
            return;
        }

        $file = $block->scriptPath();
        if ('' === $file) {
            $file = $this->debugLastPhaseInputFile;
        }
        if (null === $file || '' === $file) {
            $file = null;
        }
        $frame = null;
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        if (null === $frame) {
            $frame = new Frame(null, $block, null);
            $frame->vmContext = $context;
            if (null !== $file) {
                $frame->scriptPath = $file;
            }
        }

        $context->errors->internalDeprecated(
            $message,
            $context,
            $frame,
            $file,
            $line
        );
    }

    /**
     * Zend 8.4 compile-time deprecation for implicit nullable typed parameters (#21390, #22987, #29274).
     *
     * Emits during CFG compile for both eval (VM running) and file-level parseAndCompile
     * (VM not running yet — use {@see $vmContext} from Runtime). Message is prefixed with the
     * Zend zend_error callable label ({@see displayCallableNameForCompileDeprecation()}).
     */
    protected function maybeEmitImplicitNullableParamDeprecation(
        Op\Expr\Param $param,
        ?int $defaultSlot,
        Block $block
    ): void {
        if (!CompilerVersion::supportsImplicitNullableParameterDeprecation()) {
            return;
        }
        if (!$this->paramIsImplicitNullable($param, $defaultSlot, $block)) {
            return;
        }

        $this->emitCompileTimeInternalDeprecated(
            sprintf(
                '%s(): Implicitly marking parameter %s as nullable is deprecated, the explicit nullable type must be used instead',
                $this->displayCallableNameForCompileDeprecation($block),
                $this->displayParamName($param)
            ),
            $block,
            max(0, $param->getLine())
        );
    }

    /**
     * Zend zend_error-style callable label for compile-time deprecations (#29274).
     *
     * Named function → {@code f}; method → {@code C::m}; closure/arrow on PROFILE≥8.4 →
     * {@code {closure:file:line}} (same shape as Closure::__debugInfo); else {@code {closure}}.
     */
    protected function displayCallableNameForCompileDeprecation(Block $block): string
    {
        $func = $block->func;
        if (null === $func || !\is_string($func->name) || '' === $func->name || '{main}' === $func->name) {
            return '{closure}';
        }
        $name = $func->name;
        if (str_starts_with($name, '{anonymous}') || str_starts_with($name, '{closure')) {
            if (CompilerVersion::supportsClosureRichDebugInfo()) {
                if (null !== $block->closureRichDisplayName && '' !== $block->closureRichDisplayName) {
                    return $block->closureRichDisplayName;
                }
                $callable = $func->callableOp ?? null;
                if ($callable instanceof Op) {
                    $file = $callable->getFile();
                    $line = max(0, (int) $callable->getLine());
                    if (\is_string($file) && '' !== $file && $line > 0) {
                        return '{closure:'.$file.':'.$line.'}';
                    }
                }
            }

            return '{closure}';
        }
        if (null !== $func->class) {
            $class = $this->staticNameFromOperand($func->class);
            if ((null === $class || '' === $class) && null !== $this->compilingClassDisplayName) {
                $class = $this->compilingClassDisplayName;
            }
            if (null !== $class && '' !== $class) {
                return ltrim($class, '\\').'::'.$name;
            }
        }

        return $name;
    }

    /**
     * PHP 8.4+ {@code {closure:…:line}} for anonymous/arrow (zend_compile.c, #30076).
     *
     * @param Op\Expr\ArrowFunction|Op\Expr\Closure $expr
     */
    private function computeClosureRichDisplayName(Block $enclosing, $expr): ?string
    {
        if (!CompilerVersion::supportsClosureRichDebugInfo()) {
            return null;
        }
        $line = max(0, (int) $expr->getLine());
        $file = $expr->getFile();
        if (!\is_string($file) || '' === $file) {
            $file = $enclosing->scriptPath();
        }
        $parentRich = null;
        $enclosingFunc = $enclosing->func;
        if (null !== $enclosingFunc && null !== $this->closureRichNameByFunc
            && isset($this->closureRichNameByFunc[$enclosingFunc])
        ) {
            $parentRich = (string) $this->closureRichNameByFunc[$enclosingFunc];
        } elseif (null !== $enclosing->closureRichDisplayName && '' !== $enclosing->closureRichDisplayName) {
            $parentRich = $enclosing->closureRichDisplayName;
        }

        return ClosureRichDisplayName::fromEnclosingBlock(
            $enclosing,
            $line,
            $parentRich,
            \is_string($file) ? $file : null,
            $this->compilingClassDisplayName
        );
    }

    /** Declaring class while compiling a method body (null at free function / top-level). */
    private function closureDeclaringClassFromEnclosing(Block $enclosing): ?string
    {
        $func = $enclosing->func;
        if (null !== $func && null !== $func->class) {
            $classVal = $func->class->value ?? null;
            if (\is_string($classVal) && '' !== $classVal) {
                return ltrim($classVal, '\\');
            }
        }
        if (null !== $enclosing->closureDeclaringClass && '' !== $enclosing->closureDeclaringClass) {
            // Nested in a method-scoped closure: keep the outer method's class (#30076).
            return $enclosing->closureDeclaringClass;
        }
        if (null !== $this->compilingClassDisplayName && '' !== $this->compilingClassDisplayName) {
            $name = null !== $func && \is_string($func->name) ? $func->name : '';
            if ('' !== $name && '{main}' !== $name && !ClosureRichDisplayName::isClosureCfgName($name)) {
                return ltrim($this->compilingClassDisplayName, '\\');
            }
        }

        return null;
    }

    /** Stamp PHP 8.4 rich name onto every block in a closure body (#30076). */
    private function propagateClosureRichDisplayName(
        Block $entry,
        ?string $richDisplayName,
        ?string $declaringClass = null
    ): void {
        $queue = [$entry];
        $seen = new SplObjectStorage();
        while ([] !== $queue) {
            $block = array_pop($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen[$block] = true;
            if (null !== $richDisplayName) {
                $block->closureRichDisplayName = $richDisplayName;
            }
            if (null !== $declaringClass) {
                $block->closureDeclaringClass = $declaringClass;
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block) {
                    $queue[] = $child;
                }
            }
            foreach ($block->opCodes as $op) {
                if (null !== $op->block1 && !$seen->contains($op->block1)) {
                    // Stay inside this closure — do not descend into nested TYPE_CLOSURE bodies.
                    if (OpCode::TYPE_CLOSURE === $op->type) {
                        continue;
                    }
                    $queue[] = $op->block1;
                }
                if (null !== $op->block2 && !$seen->contains($op->block2)) {
                    $queue[] = $op->block2;
                }
            }
        }
    }

    private function displayParamName(Op\Expr\Param $param): string
    {
        if ($param->name instanceof Operand\Literal && is_string($param->name->value) && '' !== $param->name->value) {
            return '$'.$param->name->value;
        }

        return '$?';
    }

    /**
     * Zend zend_compile.c: property/param defaults must match declared type (#5347, #6558).
     */
    protected function assertParamDefaultMatchesDeclaredType(Op\Expr\Param $param, ?int $defaultSlot, Block $block): void
    {
        if (null === $defaultSlot || null === $param->declaredType) {
            return;
        }
        $default = $block->constants[$defaultSlot] ?? null;
        if (null === $default) {
            return;
        }
        $paramName = '?';
        if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
            $paramName = '$'.$param->name->value;
        }
        $this->assertCompileTimeDefaultMatchesDeclaredType(
            $default,
            $param->declaredType,
            'parameter',
            $paramName,
            $block,
            $defaultSlot,
            $param
        );
    }

    /**
     * Zend zend_compile.c — zend_verify_const_expr_type() for property/param defaults (#6558).
     */
    protected function assertCompileTimeDefaultMatchesDeclaredType(
        Variable $default,
        ?Op\Type $declaredType,
        string $kind,
        string $targetName,
        ?Block $block = null,
        ?int $defaultSlot = null,
        ?Op\Expr\Param $param = null,
        ?string $sourceFile = null,
        ?int $sourceLine = null
    ): void {
        if (null === $declaredType) {
            return;
        }

        $value = $default->resolveIndirect();

        if ($declaredType instanceof Op\Type\Mixed_) {
            return;
        }
        if ($declaredType instanceof Op\Type\Literal && 'mixed' === strtolower($declaredType->name)) {
            return;
        }

        if (
            'parameter' === $kind
            && null !== $param
            && null !== $defaultSlot
            && null !== $block
            && $this->paramIsImplicitNullable($param, $defaultSlot, $block)
        ) {
            return;
        }

        $checkType = $declaredType;
        if ($declaredType instanceof Op\Type\Nullable) {
            if (Variable::TYPE_NULL === $value->type) {
                return;
            }
            $checkType = $declaredType->subtype;
        }

        if (
            $this->cfgTypeUsesDnfShape($checkType)
            || $checkType instanceof Op\Type\Union
            || $checkType instanceof Op\Type\Intersection
        ) {
            return;
        }

        $typeLabel = $this->declNameFromCfgType($checkType) ?? 'mixed';

        if ($checkType instanceof Op\Type\Literal) {
            $nameLc = strtolower($checkType->name);
            if ('true' === $nameLc || 'false' === $nameLc) {
                if (Variable::TYPE_BOOLEAN === $value->type && $value->toBool() === ('true' === $nameLc)) {
                    return;
                }
                $given = TypeCheck::typeNameForConstraint($value->type);
                $this->throwTypedDefaultMismatch($given, $kind, $targetName, $nameLc, $sourceFile, $sourceLine);

                return;
            }
        }

        if ($value->is(Variable::TYPE_ARRAY)) {
            if ($checkType instanceof Op\Type\Literal) {
                $nameLc = strtolower($checkType->name);
                if ('array' === $nameLc || 'iterable' === $nameLc) {
                    return;
                }
            }
            if (null !== $this->genericArraySpecFromCfgType($checkType)) {
                return;
            }
            $this->throwTypedDefaultMismatch('array', $kind, $targetName, $typeLabel, $sourceFile, $sourceLine);

            return;
        }

        if ($checkType instanceof Op\Type\Literal && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($checkType->name))) {
            return;
        }

        $classOrScalarName = $this->declNameFromCfgType($checkType);
        if (null !== $classOrScalarName && null !== $block) {
            $resolvedClass = $this->resolveTypeHintClassName($classOrScalarName, $block);
            if (null !== $resolvedClass && '' !== $resolvedClass) {
                $classOrScalarName = $resolvedClass;
            }
        }
        if (
            null !== $classOrScalarName
            && $this->compileTimeDefaultMatchesLiteralType($value, strtolower($classOrScalarName))
        ) {
            return;
        }

        $given = TypeCheck::typeNameForConstraint($value->type);
        $this->throwTypedDefaultMismatch($given, $kind, $targetName, $typeLabel, $sourceFile, $sourceLine);
    }

    /**
     * Zend zend_compile.c — property null defaults use a dedicated fatal (#31820);
     * with file/line → CompileFatal / "PHP Fatal error:" CLI shape (follow-up to #31827).
     */
    protected function throwTypedDefaultMismatch(
        string $given,
        string $kind,
        string $targetName,
        string $typeLabel,
        ?string $sourceFile = null,
        ?int $sourceLine = null
    ): void {
        if ('property' === $kind && 'null' === $given) {
            $this->throwCompileError(
                "Default value for property of type {$typeLabel} may not be null. Use the nullable type ?{$typeLabel} to allow null default value",
                $sourceFile,
                $sourceLine
            );
        }

        $this->throwCompileError(
            "Cannot use {$given} as default value for {$kind} {$targetName} of type {$typeLabel}",
            $sourceFile,
            $sourceLine
        );
    }

    protected function compileTimeDefaultMatchesLiteralType(Variable $value, string $typeNameLc): bool
    {
        switch ($typeNameLc) {
            case 'int':
                return $value->is(Variable::TYPE_INTEGER);
            case 'float':
                return $value->is(Variable::TYPE_FLOAT) || $value->is(Variable::TYPE_INTEGER);
            case 'string':
                return $value->is(Variable::TYPE_STRING);
            case 'bool':
                return $value->is(Variable::TYPE_BOOLEAN);
            case 'array':
                return $value->is(Variable::TYPE_ARRAY);
            case 'iterable':
                return $value->is(Variable::TYPE_ARRAY);
            case 'null':
                return $value->is(Variable::TYPE_NULL);
            default:
                return $this->compileTimeDefaultMatchesClassType($value, $typeNameLc);
        }
    }

    protected function compileTimeDefaultMatchesClassType(Variable $value, string $expectedClassLc): bool
    {
        $value = $value->resolveIndirect();
        $expectedClassLc = strtolower(ltrim($expectedClassLc, '\\'));

        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return strtolower(ltrim($value->toEnumCase()->enumClass->name, '\\')) === $expectedClassLc;
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            $obj = $value->toObject();
            if (EnumCaseSupport::isEnumCase($obj)) {
                return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
            }

            return strtolower(ltrim($obj->class->name, '\\')) === $expectedClassLc;
        }

        return false;
    }

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

    /** php-cfg: {@see ErrorSuppressBlock} jump target where silenced reads are lowered (#3546). */
    private function isErrorSuppressEndBlock(CfgBlock $block): bool
    {
        if (1 !== \count($block->parents)) {
            return false;
        }
        $parent = $block->parents[0];

        return $parent instanceof ErrorSuppressBlock;
    }

    /**
     * php-cfg {@see ErrorSuppressBlock}: inner expr result is produced in the suppress branch but
     * consumed in the post-suppress block; inheritScopeFrom alone can miss unnamed SSA temps (#10336).
     */
    private function inheritErrorSuppressExpressionSlots(Block $suppressCompiled, Block $endCompiled): void
    {
        $suppressCfg = $suppressCompiled->orig;
        if (!$suppressCfg instanceof ErrorSuppressBlock) {
            return;
        }
        $endCfg = $endCompiled->orig;
        $primary = $this->findErrorSuppressPrimaryInnerExpr($suppressCfg);
        if (null === $primary || !isset($primary->result)) {
            return;
        }
        $this->inheritErrorSuppressByRefCallArgSlots($suppressCompiled, $endCompiled, $primary);
        $slot = $suppressCompiled->slotForOperand($primary->result);
        if (null === $slot) {
            $slot = $this->findFuncCallExecReturnSlot($suppressCompiled);
        }
        if (null === $slot) {
            $slot = $this->findIncludeReturnSlot($suppressCompiled);
        }
        if (null === $slot && $primary instanceof Op\Expr\ArrayDimFetch) {
            $slot = $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($suppressCompiled, 0);
        }
        if (null === $slot && ($primary instanceof Op\Expr\Isset_ || $primary instanceof Op\Expr\Empty_)) {
            $slot = $this->slotForEmittedIssetOrEmptyProducer($suppressCompiled, $primary);
        }
        if (null === $slot) {
            return;
        }
        $suppressResult = $primary->result;
        if ($this->errorSuppressEndBlockDiscardsInnerResultForErrorGetLast($endCompiled)) {
            return;
        }
        if (!$this->endBlockAssignsErrorGetLastAfterSuppress($endCfg)) {
            $endCompiled->forceBindScopeSlot($suppressResult, $slot);
        }
        $root = Block::cfgVarRoot($suppressResult);
        if (
            null !== $root
            && !$this->endBlockAssignsErrorGetLastAfterSuppress($endCfg)
            && !$this->shouldSkipPrebindCfgVarRootForSuppressResult($endCompiled, $endCfg, $suppressResult)
        ) {
            $endCompiled->prebindCfgVarRoot($root, $slot);
        }
        foreach ($suppressResult->usages as $usage) {
            if (
                !$usage instanceof Op\Expr\FuncCall
                && !$usage instanceof Op\Expr\NsFuncCall
                && !$usage instanceof Op\Expr\MethodCall
                && !$usage instanceof Op\Expr\StaticCall
                && !$usage instanceof Op\Expr\New_
            ) {
                continue;
            }
            if (property_exists($usage, 'args') && is_array($usage->args)) {
                foreach ($usage->args as $arg) {
                    if ($arg instanceof Operand) {
                        $endCompiled->forceBindScopeSlot($arg, $slot);
                    }
                }
            }
        }
        if (null !== $endCfg) {
            foreach ($endCfg->children as $endChild) {
                if (
                    !$endChild instanceof Op\Expr\FuncCall
                    && !$endChild instanceof Op\Expr\NsFuncCall
                    && !$endChild instanceof Op\Expr\MethodCall
                    && !$endChild instanceof Op\Expr\StaticCall
                ) {
                    continue;
                }
                if (!property_exists($endChild, 'args') || !is_array($endChild->args)) {
                    continue;
                }
                foreach ($endChild->args as $arg) {
                    if (
                        !$arg instanceof Operand
                        || $arg instanceof Operand\Literal
                        || $arg instanceof Operand\NullOperand
                    ) {
                        continue;
                    }
                    if (null !== Block::cfgVarRoot($arg)) {
                        if ($this->operandsReferToSameVariable($arg, $suppressResult)) {
                            $endCompiled->forceBindScopeSlot($arg, $slot);
                        }

                        continue;
                    }
                    // PhiResolver can replace the suppress inner result with an unrelated temp (#10336).
                    if (
                        $this->callInErrorSuppressEndBlockUsesInnerResultAsArg($endCompiled, $endChild)
                        && $this->callArgIsErrorSuppressForwardedResult($arg, $endCompiled)
                    ) {
                        $endCompiled->forceBindScopeSlot($arg, $slot);
                    }
                }
            }
            foreach ($endCfg->children as $endChild) {
                $this->bindErrorSuppressResultOperandUsages($endChild, $endCompiled, $suppressResult, $slot);
            }
            foreach ($endCfg->children as $endChild) {
                if (
                    !$endChild instanceof Op\Expr\FuncCall
                    && !$endChild instanceof Op\Expr\NsFuncCall
                ) {
                    continue;
                }
                if (!property_exists($endChild, 'args') || !is_array($endChild->args)) {
                    continue;
                }
                foreach ($endChild->args as $argIndex => $arg) {
                    if (
                        !$arg instanceof Operand
                        || $this->isEmbeddedCallLiteralArg($arg)
                        || !$this->callArgIsDeadInlineTemporary($arg)
                        || $this->callArgOpsContainInlineClosure($arg)
                    ) {
                        continue;
                    }
                    if (
                        $this->errorSuppressEndBlockCallArgHasTrailingIncludeProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingHoistedScalarProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingHoistedArrayProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingArrayDimFetchProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasAdjacentNestedFuncCallProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasAdjacentNestedNewProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->callArgInlineProducerIsNew($endChild, (int) $argIndex, $endCompiled)
                        || $this->errorSuppressEndBlockCallArgHasTrailingComparisonProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingConcatProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingClosureProducer($endCompiled, $endChild, (int) $argIndex)
                        || $this->errorSuppressEndBlockCallArgHasTrailingBitmaskProducer($endCompiled, $endChild, (int) $argIndex)
                    ) {
                        continue;
                    }
                    // First dead temp in the outer call is the @ inner value (#15916, #10302).
                    $endCompiled->forceBindScopeSlot($arg, $slot);
                    break;
                }
            }
        }
    }
}
