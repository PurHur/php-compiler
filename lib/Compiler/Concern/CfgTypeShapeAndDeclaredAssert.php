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
 * through {@code genericArraySpecFromCfgType} (~842 lines) so gen-0 split-TU can
 * hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c type-hint / DNF / param
 * declared-type checks — move-only, no new C ABI.
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

}
