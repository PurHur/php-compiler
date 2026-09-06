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
 * {@see CompileParamFunctionAndStmtDispatch}. Class body / trait adaptations live in
 * {@see CompileClassBodyAndTraitAdaptations}. CFG type-shape / declared-type asserts live in
 * {@see CfgTypeShapeAndDeclaredAssert}. Extracted from {@see \PHPCompiler\Compiler}
 * behind the opcode-corpus-md5 gate. Visibility stays protected/private so LintCompiler
 * and call sites are unchanged.
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
