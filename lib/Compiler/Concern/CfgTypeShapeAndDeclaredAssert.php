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
 * CFG type-shape probes and declared type/param asserts (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile}: {@code intersectionNamesFromCfgType}
 * through {@code cfgTypeContainsNull} so gen-0 split-TU can hollow a smaller Concern TU.
 * Declared-type asserts + param apply live in {@see CfgDeclaredTypeAssertAndParamApply}.
 * Mirrors php-src Zend/zend_compile.c type-hint / DNF shape queries — move-only, no new C ABI.
 */
trait CfgTypeShapeAndDeclaredAssert
{
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
}
