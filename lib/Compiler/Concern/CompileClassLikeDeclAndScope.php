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
 * Class declaration + compile-time const inheritance / sealed metadata / scope helpers (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile} / {@see \PHPCompiler\Compiler}
 * behind the opcode-corpus-md5 gate. Visibility stays protected/private so LintCompiler
 * and call sites are unchanged.
 */
trait CompileClassLikeDeclAndScope
{
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
}
