<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Op\Stmt\Class_;
use PHPCfg\Op\Stmt\ClassLike;
use PHPCfg\Op\Stmt\ClassMethod;
use PHPCfg\Op\Stmt\Interface_;
use PHPCfg\Op\Stmt\Trait_;
use PHPCfg\Op\Stmt\TraitUse;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time parameter contravariance / return covariance, staticness,
 * abstract-from-concrete, visibility, and trait abstract signature composition
 * (Zend zend_inheritance.c / zend_traits.c, issues #3323, #25634, #25660,
 * #25662, #26381, #26520).
 *
 * Visibility applies to concrete overrides and abstract→concrete implementations:
 * child must be the parent visibility or weaker (public > protected > private).
 */
final class InheritanceVariance
{
    public const BUILTIN_SCALARS = [
        'int' => true,
        'float' => true,
        'string' => true,
        'bool' => true,
        'array' => true,
        'callable' => true,
        'iterable' => true,
        'object' => true,
    ];

    /** @var array<string, ClassLike> */
    private array $units = [];

    /** @var array<string, string|null> */
    private array $extends = [];

    /** @var array<string, list<string>> */
    private array $implements = [];

    /** @var array<string, list<string>> parent interface extends */
    private array $interfaceExtends = [];

    /** @var array<string, array<string, MethodSig>> */
    private array $methods = [];

    /**
     * Composing-class trait use lists in source order (Zend zend_traits.c flatten).
     *
     * @var array<string, list<string>>
     */
    private array $classTraitUses = [];

    /**
     * @param callable(string): void $report
     */
    public static function validateScript(Script $script, callable $report): void
    {
        if ('1' === (string) getenv('PHP_COMPILER_VENDOR_PRELINK')) {
            return;
        }

        $checker = new self();
        $checker->indexScript($script);
        $checker->validate($report);
    }

    private function indexScript(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Class_) {
                $this->indexClass($child);
            } elseif ($child instanceof Interface_) {
                $this->indexInterface($child);
            } elseif ($child instanceof Trait_) {
                $this->indexTrait($child);
            }
        }
    }

    private function indexClass(Class_ $class): void
    {
        $lc = $this->classLcFromOperand($class->name);
        if (null === $lc) {
            return;
        }
        $this->units[$lc] = $class;
        $this->extends[$lc] = null !== $class->extends
            ? $this->classLcFromOperand($class->extends)
            : null;
        $this->implements[$lc] = $this->interfaceNamesFromOperands($class->implements);
        $this->methods[$lc] = $this->extractMethods($class, $lc);
        $this->classTraitUses[$lc] = $this->collectTraitUses($class);
    }

    private function indexInterface(Interface_ $iface): void
    {
        $lc = $this->classLcFromOperand($iface->name);
        if (null === $lc) {
            return;
        }
        $this->units[$lc] = $iface;
        $this->interfaceExtends[$lc] = $this->interfaceNamesFromOperands($iface->extends);
        $this->methods[$lc] = $this->extractMethods($iface, $lc, true);
    }

    private function indexTrait(Trait_ $trait): void
    {
        $lc = $this->classLcFromOperand($trait->name);
        if (null === $lc) {
            return;
        }
        $this->units[$lc] = $trait;
        $this->methods[$lc] = $this->extractMethods($trait, $lc);
    }

    /**
     * @return array<string, MethodSig>
     */
    private function extractMethods(ClassLike $unit, string $ownerLc, bool $forceAbstract = false): array
    {
        $methods = [];
        foreach ($unit->stmts->children as $child) {
            if (!$child instanceof ClassMethod) {
                continue;
            }
            $name = strtolower($child->func->name);
            $sig = MethodSig::fromFunc($child->func, $ownerLc);
            if ($forceAbstract) {
                // Interface methods are always abstract in zend_inheritance.c; PHPCfg may omit FLAG_ABSTRACT.
                $sig->isAbstract = true;
            }
            $methods[$name] = $sig;
        }

        return $methods;
    }

    /**
     * @param callable(string): void $report
     */
    private function validate(callable $report): void
    {
        foreach ($this->units as $lc => $unit) {
            if ($unit instanceof Class_) {
                $this->validateClass($lc, $unit, $report);
            } elseif ($unit instanceof Interface_) {
                $this->validateInterface($lc, $unit, $report);
            }
        }
    }

    /**
     * @param callable(string): void $report
     */
    private function validateInterface(string $childLc, Interface_ $iface, callable $report): void
    {
        $childMethods = $this->methods[$childLc] ?? [];
        $childName = $this->displayNameFromOperand($iface->name) ?? $childLc;

        foreach ($this->interfaceExtends[$childLc] ?? [] as $parentIfaceLc) {
            if (!isset($this->units[$parentIfaceLc])) {
                continue;
            }
            $parentMethods = $this->methods[$parentIfaceLc] ?? [];
            $parentName = $this->displayNameFromOperand($this->units[$parentIfaceLc]->name) ?? $parentIfaceLc;
            foreach ($childMethods as $methodLc => $childSig) {
                if (!isset($parentMethods[$methodLc])) {
                    continue;
                }
                $msg = $this->compatibilityError(
                    $childName,
                    $methodLc,
                    $childSig,
                    $parentName,
                    $parentMethods[$methodLc]
                );
                if (null !== $msg) {
                    $this->reportWithLocation($iface, $methodLc, $msg, $report);
                }
            }
        }
    }

    /**
     * @param callable(string): void $report
     */
    private function validateClass(string $childLc, Class_ $class, callable $report): void
    {
        $childMethods = $this->methods[$childLc] ?? [];
        $childName = $this->displayNameFromOperand($class->name) ?? $childLc;

        foreach ($this->ancestorSources($childLc) as $parentLc) {
            $parentMethods = $this->methods[$parentLc] ?? [];
            $parentName = $this->displayNameFromOperand($this->units[$parentLc]->name) ?? $parentLc;
            foreach ($childMethods as $methodLc => $childSig) {
                if (!isset($parentMethods[$methodLc])) {
                    continue;
                }
                $parentSig = $parentMethods[$methodLc];
                $msg = $this->compatibilityError(
                    $childName,
                    $methodLc,
                    $childSig,
                    $parentName,
                    $parentSig
                );
                if (null !== $msg) {
                    $this->reportWithLocation($class, $methodLc, $msg, $report);
                }
            }
        }

        $this->validateTraitComposition($childLc, $class, $childName, $childMethods, $report);
    }

    /**
     * Trait abstract (and concrete) method signatures must be mutually compatible under
     * zend_inheritance.c / zend_traits.c composition (#26381).
     *
     * When the composing class declares the method, check that body against each used trait
     * (class methods take precedence over trait abstracts). Otherwise check earlier traits as
     * overrides of later traits in use-list order.
     *
     * @param array<string, MethodSig> $childMethods
     * @param callable(string): void   $report
     */
    private function validateTraitComposition(
        string $childLc,
        Class_ $class,
        string $childName,
        array $childMethods,
        callable $report
    ): void {
        $traitLcs = $this->classTraitUses[$childLc] ?? [];
        if (count($traitLcs) < 1) {
            return;
        }

        /** @var array<string, list<array{lc: string, name: string, sig: MethodSig}>> */
        $byMethod = [];
        foreach ($traitLcs as $traitLc) {
            if (!isset($this->units[$traitLc]) || !($this->units[$traitLc] instanceof Trait_)) {
                continue;
            }
            $traitMethods = $this->methods[$traitLc] ?? [];
            $traitName = $this->displayNameFromOperand($this->units[$traitLc]->name) ?? $traitLc;
            foreach ($traitMethods as $methodLc => $sig) {
                $byMethod[$methodLc][] = [
                    'lc' => $traitLc,
                    'name' => $traitName,
                    'sig' => $sig,
                ];
            }
        }

        foreach ($byMethod as $methodLc => $sources) {
            if (isset($childMethods[$methodLc])) {
                $childSig = $childMethods[$methodLc];
                foreach ($sources as $source) {
                    $msg = $this->compatibilityError(
                        $childName,
                        $methodLc,
                        $childSig,
                        $source['name'],
                        $source['sig']
                    );
                    if (null !== $msg) {
                        $this->reportWithLocation($class, $methodLc, $msg, $report);
                    }
                }
                continue;
            }

            $n = count($sources);
            for ($i = 0; $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $earlier = $sources[$i];
                    $later = $sources[$j];
                    $msg = $this->compatibilityError(
                        $earlier['name'],
                        $methodLc,
                        $earlier['sig'],
                        $later['name'],
                        $later['sig']
                    );
                    if (null !== $msg) {
                        $traitUnit = $this->units[$earlier['lc']];
                        if ($traitUnit instanceof Trait_) {
                            $this->reportWithLocation($traitUnit, $methodLc, $msg, $report);
                        } else {
                            $report($msg);
                        }

                        return;
                    }
                }
            }
        }
    }

    /**
     * @return list<string> trait name LCs in use order (duplicate uses skipped)
     */
    private function collectTraitUses(Class_ $class): array
    {
        $traits = [];
        $seen = [];
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof TraitUse) {
                continue;
            }
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->classLcFromOperand($traitOperand);
                if (null === $traitLc || isset($seen[$traitLc])) {
                    continue;
                }
                $seen[$traitLc] = true;
                $traits[] = $traitLc;
            }
        }

        return $traits;
    }

    /**
     * Prefer CompileFatal (Zend-shaped "Fatal error: … in file on line N") when the method Op
     * has source location; otherwise fall back to the caller callback.
     *
     * @param callable(string): void $report
     */
    private function reportWithLocation(ClassLike $unit, string $methodLc, string $msg, callable $report): void
    {
        foreach ($unit->stmts->children as $child) {
            if (!$child instanceof ClassMethod) {
                continue;
            }
            if (strtolower($child->func->name) !== $methodLc) {
                continue;
            }
            $file = $child->getFile();
            if ('' === $file) {
                $file = 'unknown';
            }
            throw new CompileFatal($file, max(1, $child->getLine()), $msg);
        }
        $report($msg);
    }

    /**
     * @return list<string>
     */
    private function ancestorSources(string $childLc): array
    {
        $sources = [];
        $parent = $this->extends[$childLc] ?? null;
        if (null !== $parent && isset($this->units[$parent])) {
            $sources[] = $parent;
        }
        foreach ($this->implements[$childLc] ?? [] as $ifaceLc) {
            if (isset($this->units[$ifaceLc])) {
                $sources[] = $ifaceLc;
            }
        }

        return $sources;
    }

    /**
     * @param callable(string, string): bool $isClassSubtypeOf
     * @param callable(string, string): bool $classImplementsInterface
     */
    public static function methodCompatibilityError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent,
        callable $isClassSubtypeOf,
        callable $classImplementsInterface
    ): ?string {
        // Private parent methods are not overridden (zend_inheritance.c).
        if (0 !== ($parent->visibilityFlags & Func::FLAG_PRIVATE)) {
            return null;
        }

        // Cannot redeclare a concrete parent method as abstract (zend_inheritance.c, #25660).
        // Before the __construct signature skip so abstractizing a concrete ctor still fatals.
        if ($child->isAbstract && !$parent->isAbstract) {
            return sprintf(
                'Cannot make non abstract method %s::%s() abstract in class %s',
                $parentClass,
                $methodLc,
                $childClass
            );
        }

        // Concrete parent __construct signatures are not enforced on children.
        if ('__construct' === $methodLc && !$parent->isAbstract) {
            return null;
        }

        // Staticness must match exactly (zend_inheritance.c).
        if ($parent->isStatic !== $child->isStatic) {
            if ($parent->isStatic) {
                return sprintf(
                    'Cannot make static method %s::%s() non static in class %s',
                    $parentClass,
                    $methodLc,
                    $childClass
                );
            }

            return sprintf(
                'Cannot make non static method %s::%s() static in class %s',
                $parentClass,
                $methodLc,
                $childClass
            );
        }

        // Visibility must not weaken — including abstract→concrete (#25662) and concrete overrides (#25634).
        $visErr = self::visibilityCompatibilityError($childClass, $methodLc, $child, $parentClass, $parent);
        if (null !== $visErr) {
            return $visErr;
        }

        // Parent by-ref return must be kept on override (zend_inheritance.c, #26530).
        // Child may add by-ref when the parent returns by-value (Zend accepts that).
        if ($parent->returnsByRef && !$child->returnsByRef) {
            return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
        }

        if (count($child->params) < count($parent->params)) {
            for ($i = count($child->params); $i < count($parent->params); ++$i) {
                if (!($parent->paramHasDefault[$i] ?? false)) {
                    return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
                }
            }
        }
        if (count($child->params) > count($parent->params)) {
            for ($i = count($parent->params); $i < count($child->params); ++$i) {
                if (!($child->paramHasDefault[$i] ?? false)) {
                    return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
                }
            }
        }
        $paramCount = min(count($child->params), count($parent->params));
        for ($i = 0; $i < $paramCount; ++$i) {
            // zend_inheritance.c: by-ref flag must match exactly on overrides (#25633).
            $childByRef = (bool) ($child->paramByRef[$i] ?? false);
            $parentByRef = (bool) ($parent->paramByRef[$i] ?? false);
            if ($childByRef !== $parentByRef) {
                return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
            }
            // zend_inheritance.c: child must keep parent default (cannot make optional→required, #26520).
            // Child may *add* a default when the parent has none.
            $parentHasDefault = (bool) ($parent->paramHasDefault[$i] ?? false);
            $childHasDefault = (bool) ($child->paramHasDefault[$i] ?? false);
            if ($parentHasDefault && !$childHasDefault) {
                return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
            }
            if (!self::isParameterCompatibleStatic(
                $parent->params[$i],
                $child->params[$i],
                $parent->ownerLc,
                $child->ownerLc,
                $isClassSubtypeOf,
                $classImplementsInterface
            )) {
                return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
            }
        }
        // Zend zend_compile.c auto-declares `: string` for untyped `__toString` so LSP against
        // Stringable::__toString(): string (and typed parent overrides) succeeds (#25727).
        $childReturn = self::effectiveToStringReturnType($methodLc, $child->returnType);
        if (!self::isReturnCompatibleStatic(
            $parent->returnType,
            $childReturn,
            $parent->ownerLc,
            $child->ownerLc,
            $isClassSubtypeOf,
            $classImplementsInterface
        )) {
            return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
        }

        return null;
    }

    /**
     * php-src: Zend/zend_compile.c — untyped `__toString` is compiled as returning string.
     * Declared non-string returns stay as-is (rejected by MagicMethodReturnTypeCheck / LSP).
     */
    private static function effectiveToStringReturnType(string $methodLc, ?TypeSig $declared): ?TypeSig
    {
        if ('__tostring' !== $methodLc) {
            return $declared;
        }
        if (null !== $declared && !$declared->isMixed()) {
            return $declared;
        }
        $string = new TypeSig();
        $string->builtinScalar = 'string';

        return $string;
    }

    private function compatibilityError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent
    ): ?string {
        return self::methodCompatibilityError(
            $childClass,
            $methodLc,
            $child,
            $parentClass,
            $parent,
            fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOf($subtype, $supertype),
            fn (string $classLc, string $interfaceLc): bool => $this->classImplementsInterface($classLc, $interfaceLc)
        );
    }

    /**
     * Parameter contravariance (zend_inheritance.c): parent args must be passable to the child
     * parameter type — child type is a supertype of the parent type.
     *
     * @param callable(string, string): bool $isClassSubtypeOf
     * @param callable(string, string): bool $classImplementsInterface
     */
    private static function isParameterCompatibleStatic(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc,
        callable $isClassSubtypeOf,
        ?callable $classImplementsInterface = null
    ): bool {
        if (null === $parent || $parent->isMixed()) {
            return true;
        }
        if (null === $child || $child->isMixed()) {
            return true;
        }
        // Child must accept null if parent does (cannot narrow away nullability).
        if ($parent->nullable && !$child->nullable) {
            return false;
        }
        $implements = $classImplementsInterface ?? static fn (string $a, string $b): bool => false;

        // Parent type must be a subtype of child type (child is wider / contravariant).
        return self::isSubtypeOfStatic(
            $parent,
            $child,
            $parentOwnerLc,
            $childOwnerLc,
            $isClassSubtypeOf,
            $implements
        );
    }

    /**
     * @param callable(string, string): bool $isClassSubtypeOf
     * @param callable(string, string): bool $classImplementsInterface
     */
    /**
     * PHP 8.3 typed class constants are covariant (zend_inheritance.c class_constant_types_compatible, #5953).
     *
     * @param callable(string, string): bool $isClassSubtypeOf
     * @param callable(string, string): bool $classImplementsInterface
     */
    public static function isCovariantTypeCompatible(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc,
        callable $isClassSubtypeOf,
        callable $classImplementsInterface
    ): bool {
        return self::isReturnCompatibleStatic(
            $parent,
            $child,
            $parentOwnerLc,
            $childOwnerLc,
            $isClassSubtypeOf,
            $classImplementsInterface
        );
    }

    private static function isReturnCompatibleStatic(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc,
        callable $isClassSubtypeOf,
        callable $classImplementsInterface
    ): bool {
        if (null === $parent || $parent->isMixed()) {
            return true;
        }
        if (null === $child || $child->isMixed()) {
            return false;
        }
        if ($parent->isVoid()) {
            return $child->isVoid() || $child->isNever();
        }
        if ($parent->isNever()) {
            return $child->isNever();
        }
        // Return covariance: cannot widen nullability (parent string, child ?string).
        if (!$parent->nullable && $child->nullable) {
            return false;
        }
        // Parent ?T, child T is fine; continue with inner assignability.
        if ($parent->static && !$child->static) {
            return false;
        }

        // Child return must be a subtype of parent return (covariant).
        return self::isSubtypeOfStatic(
            $child,
            $parent,
            $childOwnerLc,
            $parentOwnerLc,
            $isClassSubtypeOf,
            $classImplementsInterface
        );
    }

    /**
     * True when a value of $sub is always acceptable where $super is declared
     * (zend_type assignability / LSP subtype). Nullability is checked by callers
     * for variance direction; here nullable flags on either side are ignored for
     * the structural comparison (inners only).
     *
     * @param callable(string, string): bool $isClassSubtypeOf
     * @param callable(string, string): bool $classImplementsInterface
     */
    private static function isSubtypeOfStatic(
        TypeSig $sub,
        TypeSig $super,
        string $subOwnerLc,
        string $superOwnerLc,
        callable $isClassSubtypeOf,
        callable $classImplementsInterface
    ): bool {
        if ($super->isIntersection()) {
            foreach ($super->intersectionMembers as $member) {
                if (!self::isSubtypeOfStatic(
                    $sub,
                    $member,
                    $subOwnerLc,
                    $superOwnerLc,
                    $isClassSubtypeOf,
                    $classImplementsInterface
                )) {
                    return false;
                }
            }

            return true;
        }
        if ($sub->isIntersection()) {
            foreach ($sub->intersectionMembers as $member) {
                if (self::isSubtypeOfStatic(
                    $member,
                    $super,
                    $subOwnerLc,
                    $superOwnerLc,
                    $isClassSubtypeOf,
                    $classImplementsInterface
                )) {
                    return true;
                }
            }

            return false;
        }

        // Union assignability (zend_type / #25632): U|V <: T iff every member <: T;
        // T <: U|V iff T <: any member.
        if ($super->isUnion()) {
            foreach ($super->unionMembers as $member) {
                if (self::isSubtypeOfStatic(
                    $sub,
                    $member,
                    $subOwnerLc,
                    $superOwnerLc,
                    $isClassSubtypeOf,
                    $classImplementsInterface
                )) {
                    return true;
                }
            }

            return false;
        }
        if ($sub->isUnion()) {
            foreach ($sub->unionMembers as $member) {
                if (!self::isSubtypeOfStatic(
                    $member,
                    $super,
                    $subOwnerLc,
                    $superOwnerLc,
                    $isClassSubtypeOf,
                    $classImplementsInterface
                )) {
                    return false;
                }
            }

            return true;
        }

        if ($super->isVoid() || $super->isNever() || $sub->isVoid() || $sub->isNever()) {
            return $sub->signatureKey($subOwnerLc) === $super->signatureKey($superOwnerLc);
        }

        if (null !== $super->builtinScalar) {
            return self::isBuiltinSuperType($super, $sub, $subOwnerLc, $classImplementsInterface);
        }
        if (null !== $sub->builtinScalar) {
            // Sub is a builtin, super is a class-like — only iterable/object cases above apply.
            return false;
        }

        $superClass = $super->resolveClassName($superOwnerLc);
        $subClass = $sub->resolveClassName($subOwnerLc);
        if (null === $superClass || null === $subClass) {
            return $sub->signatureKey($subOwnerLc) === $super->signatureKey($superOwnerLc);
        }
        if ($superClass === $subClass) {
            return true;
        }
        if ($super->self && $sub->static) {
            if ($isClassSubtypeOf($subClass, $superClass)) {
                return true;
            }

            return $classImplementsInterface($subClass, $superClass);
        }
        if ($isClassSubtypeOf($subClass, $superClass)) {
            return true;
        }

        return $classImplementsInterface($subClass, $superClass);
    }

    /**
     * @param callable(string, string): bool $classImplementsInterface
     */
    private static function isBuiltinSuperType(
        TypeSig $super,
        TypeSig $sub,
        string $subOwnerLc,
        callable $classImplementsInterface
    ): bool {
        if (null !== $sub->builtinScalar) {
            if ($super->builtinScalar === $sub->builtinScalar) {
                return true;
            }
            if ('iterable' === $super->builtinScalar && 'array' === $sub->builtinScalar) {
                return true;
            }

            return false;
        }
        if ('object' === $super->builtinScalar) {
            return null !== $sub->resolveClassName($subOwnerLc) || $sub->self || $sub->static;
        }
        if ('iterable' === $super->builtinScalar) {
            $subClass = $sub->resolveClassName($subOwnerLc);
            if (null !== $subClass) {
                return $classImplementsInterface($subClass, 'traversable');
            }
        }

        return false;
    }

    private function isParameterCompatible(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        return self::isParameterCompatibleStatic(
            $parent,
            $child,
            $parentOwnerLc,
            $childOwnerLc,
            fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOf($subtype, $supertype),
            fn (string $classLc, string $interfaceLc): bool => $this->classImplementsInterface($classLc, $interfaceLc)
        );
    }

    private function isReturnCompatible(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        return self::isReturnCompatibleStatic(
            $parent,
            $child,
            $parentOwnerLc,
            $childOwnerLc,
            fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOf($subtype, $supertype),
            fn (string $classLc, string $interfaceLc): bool => $this->classImplementsInterface($classLc, $interfaceLc)
        );
    }

    private function classImplementsInterface(string $classLc, string $interfaceLc): bool
    {
        if ($classLc === $interfaceLc) {
            return true;
        }
        foreach ($this->implements[$classLc] ?? [] as $ifaceLc) {
            if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                return true;
            }
        }
        $parent = $this->extends[$classLc] ?? null;
        if (null !== $parent) {
            return $this->classImplementsInterface($parent, $interfaceLc);
        }

        return false;
    }

    private function interfaceExtendsOrEquals(string $ifaceLc, string $targetLc): bool
    {
        if ($ifaceLc === $targetLc) {
            return true;
        }
        foreach ($this->interfaceExtends[$ifaceLc] ?? [] as $parentIface) {
            if ($this->interfaceExtendsOrEquals($parentIface, $targetLc)) {
                return true;
            }
        }

        return false;
    }

    private function isClassSubtypeOf(string $subtypeLc, string $supertypeLc): bool
    {
        if ($subtypeLc === $supertypeLc) {
            return true;
        }
        $current = $subtypeLc;
        $guard = 0;
        while (null !== ($parent = $this->extends[$current] ?? null)) {
            if (++$guard > 256) {
                return false;
            }
            if ($parent === $supertypeLc) {
                return true;
            }
            if (!isset($this->units[$parent])) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Reject visibility weakening on override (public→protected/private, protected→private).
     * Strengthen (protected→public) and same level are OK.
     */
    private static function visibilityCompatibilityError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent
    ): ?string {
        $parentRank = self::visibilityRank($parent->visibilityFlags);
        $childRank = self::visibilityRank($child->visibilityFlags);
        if ($childRank <= $parentRank) {
            return null;
        }
        if (1 === $parentRank) {
            return sprintf(
                'Access level to %s::%s() must be public (as in class %s)',
                $childClass,
                $methodLc,
                $parentClass
            );
        }

        return sprintf(
            'Access level to %s::%s() must be protected (as in class %s) or weaker',
            $childClass,
            $methodLc,
            $parentClass
        );
    }

    /** 1=public, 2=protected, 3=private (higher = more restricted). */
    private static function visibilityRank(int $flags): int
    {
        if (0 !== ($flags & Func::FLAG_PRIVATE)) {
            return 3;
        }
        if (0 !== ($flags & Func::FLAG_PROTECTED)) {
            return 2;
        }

        return 1;
    }

    private static function formatDeclarationError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent
    ): string {
        // Property-hook synthetics: Zend cites Class::$prop::get/set() (zend_inheritance.c, #29690).
        $methodDisplay = self::hookMethodDisplayName($methodLc);
        $childReturn = $child->formatReturn($childClass);
        $parentReturn = $parent->formatReturn($parentClass);
        // Instance set hooks often omit `: void` in lowered PHP; Zend still prints it.
        if (null !== \PHPCompiler\SourcePreprocessor\PropertyHooks::propertyNameFromSetHookMethod($methodLc)) {
            if ('' === $childReturn) {
                $childReturn = ': void';
            }
            if ('' === $parentReturn) {
                $parentReturn = ': void';
            }
        }
        // Zend prefixes `& ` before Class::method when the declaration returns by-ref
        // (zend_inheritance.c / zend_error, #26530).
        return sprintf(
            'Declaration of %s%s::%s(%s)%s must be compatible with %s%s::%s(%s)%s',
            $child->returnsByRef ? '& ' : '',
            $childClass,
            $methodDisplay,
            $child->formatParams($childClass),
            $childReturn,
            $parent->returnsByRef ? '& ' : '',
            $parentClass,
            $methodDisplay,
            $parent->formatParams($parentClass),
            $parentReturn
        );
    }

    /** `$prop::get` / `$prop::set` for synthetic PropertyHooks methods; else the method LC. */
    private static function hookMethodDisplayName(string $methodLc): string
    {
        return \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($methodLc)
            ?? $methodLc;
    }

    /**
     * @param Operand[] $operands
     *
     * @return list<string>
     */
    private function interfaceNamesFromOperands(array $operands): array
    {
        $names = [];
        foreach ($operands as $operand) {
            $lc = $this->classLcFromOperand($operand);
            if (null !== $lc) {
                $names[] = $lc;
            }
        }

        return $names;
    }

    private function classLcFromOperand(Operand $op): ?string
    {
        $name = $this->displayNameFromOperand($op);

        return null !== $name ? strtolower(ltrim($name, '\\')) : null;
    }

    private function displayNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->displayNameFromOperand($op->name);
        }

        return null;
    }
}

final class MethodSig
{
    /** @var list<?TypeSig> */
    public array $params;

    /** @var list<string> */
    public array $paramNames;

    /** @var list<bool> */
    public array $paramHasDefault;

    /**
     * Zend inheritance-error default exports (e.g. "1", "null", "[]", "'hi'").
     *
     * @var list<?string>
     */
    public array $paramDefaultExports;

    /** @var list<bool> per-param by-ref flags (zend_inheritance.c, #25633) */
    public array $paramByRef;

    public ?TypeSig $returnType;

    public string $ownerLc;

    public bool $isAbstract;

    public bool $isFinal;

    /** @see Func::FLAG_PUBLIC|FLAG_PROTECTED|FLAG_PRIVATE */
    public int $visibilityFlags;

    /** Whether the method was declared static (FLAG_STATIC). */
    public bool $isStatic;

    /** Whether declared `function &name()` (FLAG_RETURNS_REF, #26530). */
    public bool $returnsByRef;

    /**
     * @param list<?TypeSig>   $params
     * @param list<string>     $paramNames
     * @param list<bool>       $paramHasDefault
     * @param list<bool>       $paramByRef
     * @param list<?string>    $paramDefaultExports
     */
    public function __construct(
        string $ownerLc,
        array $params,
        array $paramNames,
        array $paramHasDefault,
        ?TypeSig $returnType,
        bool $isAbstract = false,
        int $visibilityFlags = Func::FLAG_PUBLIC,
        bool $isFinal = false,
        array $paramByRef = [],
        bool $isStatic = false,
        bool $returnsByRef = false,
        array $paramDefaultExports = []
    ) {
        $this->ownerLc = $ownerLc;
        $this->params = $params;
        $this->paramNames = $paramNames;
        $this->paramHasDefault = $paramHasDefault;
        $this->returnType = $returnType;
        $this->isAbstract = $isAbstract;
        $this->isFinal = $isFinal;
        $this->visibilityFlags = $visibilityFlags;
        $this->paramByRef = $paramByRef;
        $this->isStatic = $isStatic;
        $this->returnsByRef = $returnsByRef;
        $this->paramDefaultExports = $paramDefaultExports;
    }

    public static function fromFunc(Func $func, string $ownerLc): self
    {
        $params = [];
        $names = [];
        $hasDefault = [];
        $defaultExports = [];
        $byRef = [];
        foreach ($func->params as $param) {
            $params[] = TypeSig::fromCfgType($param->declaredType);
            $names[] = self::paramNameFromOperand($param->name);
            $hasDef = null !== $param->defaultVar || null !== $param->defaultBlock;
            $hasDefault[] = $hasDef;
            $defaultExports[] = $hasDef ? self::formatParamDefaultExport($param) : null;
            $byRef[] = (bool) $param->byRef;
        }
        $isAbstract = 0 !== ($func->flags & Func::FLAG_ABSTRACT);
        $visibility = $func->flags & (Func::FLAG_PUBLIC | Func::FLAG_PROTECTED | Func::FLAG_PRIVATE);
        if (0 === $visibility) {
            $visibility = Func::FLAG_PUBLIC;
        }

        $isFinal = 0 !== ($func->flags & Func::FLAG_FINAL);
        $isStatic = 0 !== ($func->flags & Func::FLAG_STATIC);
        $returnsByRef = 0 !== ($func->flags & Func::FLAG_RETURNS_REF);

        return new self(
            $ownerLc,
            $params,
            $names,
            $hasDefault,
            TypeSig::fromCfgType($func->returnType),
            $isAbstract,
            $visibility,
            $isFinal,
            $byRef,
            $isStatic,
            $returnsByRef,
            $defaultExports
        );
    }

    /**
     * Build a MethodSig from a live ClassEntry (cross-file / eval inherit path, #25384).
     *
     * Prefers Block AST types when a Func\PHP body exists; falls back to ClassEntry
     * methodReturnDeclaredTypes + methodParameterMetadata for abstract/interface methods.
     */
    public static function fromClassEntry(\PHPCompiler\VM\ClassEntry $entry, string $methodLc): ?self
    {
        $hasMethod = isset($entry->methods[$methodLc]) || isset($entry->abstractMethods[$methodLc]);
        if (!$hasMethod && !isset($entry->methodReturnDeclaredTypes[$methodLc])
            && !isset($entry->methodParameterMetadata[$methodLc])
        ) {
            return null;
        }

        $ownerLc = $entry->methodDeclaringClassLc[$methodLc]
            ?? strtolower(ltrim($entry->name, '\\'));
        $params = [];
        $names = [];
        $hasDefault = [];
        $defaultExports = [];
        $byRef = [];
        $returnType = null;

        $func = $entry->methods[$methodLc] ?? null;
        if ($func instanceof \PHPCompiler\Func\PHP) {
            $block = $func->block;
            $paramMetas = $entry->methodParameterMetadata[$methodLc] ?? [];
            $paramCount = max(
                count($block->paramDeclaredTypes),
                count($block->paramNames),
                count($paramMetas)
            );
            for ($i = 0; $i < $paramCount; ++$i) {
                $cfgType = $block->paramDeclaredTypes[$i] ?? null;
                if (null !== $cfgType) {
                    $params[] = TypeSig::fromCfgType($cfgType);
                } elseif (isset($paramMetas[$i])) {
                    $params[] = TypeSig::fromDumpTypeString($paramMetas[$i]->typeString);
                } else {
                    $params[] = null;
                }
                $names[] = $block->paramNames[$i]
                    ?? ($paramMetas[$i]->name ?? 'param');
                $hasDefault[] = isset($paramMetas[$i])
                    ? $paramMetas[$i]->isOptional
                    : false;
                $defaultExports[] = isset($paramMetas[$i])
                    ? self::inheritanceDefaultExportFromMetadata($paramMetas[$i]->defaultExport)
                    : null;
                $byRef[] = isset($paramMetas[$i])
                    ? $paramMetas[$i]->byRef
                    : false;
            }
            $returnType = TypeSig::fromCfgType($block->returnDeclaredType);
            if (null === $returnType && isset($entry->methodReturnDeclaredTypes[$methodLc])) {
                $returnType = TypeSig::fromCfgType($entry->methodReturnDeclaredTypes[$methodLc]);
            }
        } else {
            $paramMetas = $entry->methodParameterMetadata[$methodLc] ?? [];
            foreach ($paramMetas as $meta) {
                $params[] = TypeSig::fromDumpTypeString($meta->typeString);
                $names[] = $meta->name;
                $hasDefault[] = $meta->isOptional;
                $defaultExports[] = self::inheritanceDefaultExportFromMetadata($meta->defaultExport);
                $byRef[] = $meta->byRef;
            }
            if (isset($entry->methodReturnDeclaredTypes[$methodLc])) {
                $returnType = TypeSig::fromCfgType($entry->methodReturnDeclaredTypes[$methodLc]);
            }
        }

        $vis = $entry->methodVisibility[$methodLc] ?? Func::FLAG_PUBLIC;
        $visibility = $vis & (Func::FLAG_PUBLIC | Func::FLAG_PROTECTED | Func::FLAG_PRIVATE);
        if (0 === $visibility) {
            $visibility = Func::FLAG_PUBLIC;
        }
        $isAbstract = isset($entry->abstractMethods[$methodLc]) && !isset($entry->methods[$methodLc]);
        $isFinal = 0 !== ($vis & Func::FLAG_FINAL);
        $isStatic = 0 !== ($vis & Func::FLAG_STATIC);
        $returnsByRef = 0 !== ($vis & Func::FLAG_RETURNS_REF);
        // Fallback: CFG Func flags when ClassEntry visibility omitted FLAG_STATIC / FLAG_RETURNS_REF.
        $decl = $entry->methods[$methodLc] ?? $entry->abstractMethods[$methodLc] ?? null;
        if ($decl instanceof \PHPCompiler\Func\PHP && null !== $decl->block && null !== $decl->block->func) {
            $cfgFlags = $decl->block->func->flags;
            if (!$isStatic) {
                $isStatic = 0 !== ($cfgFlags & Func::FLAG_STATIC);
            }
            if (!$returnsByRef) {
                $returnsByRef = 0 !== ($cfgFlags & Func::FLAG_RETURNS_REF);
            }
        }

        return new self(
            $ownerLc,
            $params,
            $names,
            $hasDefault,
            $returnType,
            $isAbstract,
            $visibility,
            $isFinal,
            $byRef,
            $isStatic,
            $returnsByRef,
            $defaultExports
        );
    }

    /** Private parent methods are not visible to subclasses for #[\Override] (Zend find_override_method). */
    public function isVisibleForOverrideFrom(string $childClassLc): bool
    {
        if (0 !== ($this->visibilityFlags & Func::FLAG_PRIVATE)) {
            return $this->ownerLc === $childClassLc;
        }

        return true;
    }

    private static function paramNameFromOperand(Operand $name): string
    {
        if ($name instanceof Operand\Literal && is_string($name->value)) {
            return $name->value;
        }
        if ($name instanceof Operand\Variable) {
            return self::paramNameFromOperand($name->name);
        }

        return 'param';
    }

    /**
     * Map Reflection dump defaults (NULL) to zend_inheritance error form (null).
     */
    private static function inheritanceDefaultExportFromMetadata(?string $defaultExport): ?string
    {
        if (null === $defaultExport || '' === $defaultExport) {
            return null;
        }
        if ('NULL' === $defaultExport) {
            return 'null';
        }

        return $defaultExport;
    }

    /**
     * Zend inheritance fatal default fragment (zend_inheritance.c / zend_error).
     *
     * Prefer literal / const-foldable values; empty arrays render as `[]`.
     * Named constants keep their identifier (e.g. SOME_CONST).
     */
    private static function formatParamDefaultExport(Op\Expr\Param $param): ?string
    {
        if (null === $param->defaultVar) {
            return null;
        }

        return self::formatDefaultOperand($param->defaultVar);
    }

    private static function formatDefaultOperand(Operand $var): ?string
    {
        if ($var instanceof Operand\Literal) {
            return self::formatLiteralDefaultValue($var->value);
        }
        $ops = $var->ops ?? [];
        foreach ($ops as $op) {
            if ($op instanceof Op\Expr\ConstFetch) {
                $constName = self::operandStringName($op->name);
                if (null === $constName) {
                    return null;
                }
                $lc = strtolower(ltrim($constName, '\\'));
                if ('null' === $lc) {
                    return 'null';
                }
                if ('true' === $lc || 'false' === $lc) {
                    return $lc;
                }

                return $constName;
            }
            if ($op instanceof Op\Expr\Array_) {
                $n = is_countable($op->values ?? null) ? count($op->values) : 0;

                return 0 === $n ? '[]' : null;
            }
            if ($op instanceof Op\Expr\BinaryOp) {
                $folded = self::foldBinaryDefaultOp($op);
                if (null !== $folded) {
                    return self::formatLiteralDefaultValue($folded);
                }
            }
        }

        return null;
    }

    /** Fold compile-time numeric/string binary defaults (Zend shows the result, e.g. 1+2 → 3). */
    private static function foldBinaryDefaultOp(Op\Expr\BinaryOp $op): int|float|string|null
    {
        $left = self::scalarOperandValue($op->left ?? null);
        $right = self::scalarOperandValue($op->right ?? null);
        if (null === $left || null === $right) {
            return null;
        }
        if ($op instanceof Op\Expr\BinaryOp\Plus) {
            return $left + $right;
        }
        if ($op instanceof Op\Expr\BinaryOp\Minus) {
            return $left - $right;
        }
        if ($op instanceof Op\Expr\BinaryOp\Mul) {
            return $left * $right;
        }
        if ($op instanceof Op\Expr\BinaryOp\Div) {
            return 0 != $right ? $left / $right : null;
        }
        if ($op instanceof Op\Expr\BinaryOp\Concat) {
            return (string) $left.(string) $right;
        }

        return null;
    }

    private static function scalarOperandValue(?Operand $op): int|float|string|null
    {
        if (null === $op) {
            return null;
        }
        if ($op instanceof Operand\Literal) {
            $v = $op->value;
            if (is_int($v) || is_float($v) || is_string($v)) {
                return $v;
            }

            return null;
        }
        // Nested temps (e.g. (1+2)+3) — recurse through producing BinaryOp.
        foreach ($op->ops ?? [] as $prod) {
            if ($prod instanceof Op\Expr\BinaryOp) {
                return self::foldBinaryDefaultOp($prod);
            }
            if ($prod instanceof Op\Expr\ConstFetch) {
                $name = self::operandStringName($prod->name);
                if (null !== $name && 'null' === strtolower(ltrim($name, '\\'))) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function formatLiteralDefaultValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return var_export($value, true);
        }
        if (is_array($value) && [] === $value) {
            return '[]';
        }

        return var_export($value, true);
    }

    private static function operandStringName(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::operandStringName($op->name);
        }

        return null;
    }

    public function formatParams(?string $selfDisplayClass = null): string
    {
        $parts = [];
        foreach ($this->params as $i => $type) {
            $prefix = $type instanceof TypeSig ? $type->format($selfDisplayClass).' ' : '';
            $amp = !empty($this->paramByRef[$i]) ? '&' : '';
            $default = '';
            if (!empty($this->paramHasDefault[$i])) {
                $export = $this->paramDefaultExports[$i] ?? null;
                if (null !== $export && '' !== $export) {
                    $default = ' = '.$export;
                }
            }
            $parts[] = $prefix.$amp.'$'.($this->paramNames[$i] ?? 'param').$default;
        }

        return implode(', ', $parts);
    }

    public function formatReturn(?string $selfDisplayClass = null): string
    {
        if (null === $this->returnType || $this->returnType->isMixed()) {
            return '';
        }

        return ': '.$this->returnType->format($selfDisplayClass);
    }
}

final class TypeSig
{
    public ?string $builtinScalar = null;

    public ?string $classLc = null;

    /** Original casing for diagnostics (Zend-shaped messages). */
    public ?string $classDisplay = null;

    public bool $self = false;

    public bool $static = false;

    public bool $nullable = false;

    public bool $void = false;

    public bool $never = false;

    /** @var list<TypeSig>|null Intersection members (A&B); mutually exclusive with scalar/class. */
    public ?array $intersectionMembers = null;

    /** @var list<TypeSig>|null Union members (A|B); params/returns (#25632) and properties (#23505). */
    public ?array $unionMembers = null;

    public static function fromCfgType(?Op\Type $type): ?self
    {
        if (null === $type) {
            return null;
        }
        $sig = new self();
        if ($type instanceof Op\Type\Void_) {
            $sig->void = true;

            return $sig;
        }
        if ($type instanceof Op\Type\Never_) {
            $sig->never = true;

            return $sig;
        }
        if ($type instanceof Op\Type\Nullable) {
            $inner = self::fromCfgType($type->subtype);
            if (null === $inner) {
                return null;
            }
            $inner->nullable = true;

            return $inner;
        }
        if ($type instanceof Op\Type\Union_) {
            // Keep unions for param/return LSP (#25632); mirrors fromCfgPropertyType (#23505).
            $members = [];
            foreach ($type->types as $memberType) {
                $member = self::fromCfgType($memberType);
                if (null === $member || $member->isMixed()) {
                    return null;
                }
                $members[] = $member;
            }
            if ([] === $members) {
                return null;
            }
            $sig->unionMembers = $members;

            return $sig;
        }
        if ($type instanceof Op\Type\Intersection) {
            $members = [];
            foreach ($type->types as $memberType) {
                $member = self::fromCfgType($memberType);
                if (null === $member || $member->isMixed()) {
                    return null;
                }
                $members[] = $member;
            }
            if ([] === $members) {
                return null;
            }
            $sig->intersectionMembers = $members;

            return $sig;
        }
        if ($type instanceof Op\Type\Literal) {
            $name = strtolower($type->name);
            if ('self' === $name) {
                $sig->self = true;

                return $sig;
            }
            if ('static' === $name) {
                $sig->static = true;

                return $sig;
            }
            if ('mixed' === $name) {
                return null;
            }
            if (isset(InheritanceVariance::BUILTIN_SCALARS[$name]) || 'void' === $name || 'never' === $name) {
                if ('void' === $name) {
                    $sig->void = true;
                } elseif ('never' === $name) {
                    $sig->never = true;
                } else {
                    $sig->builtinScalar = $name;
                }

                return $sig;
            }
            $sig->classDisplay = ltrim($type->name, '\\');
            $sig->classLc = strtolower($sig->classDisplay);

            return $sig;
        }
        if ($type instanceof Op\Type\Reference) {
            $decl = $type->declaration;
            if ($decl instanceof Operand\Literal && is_string($decl->value)) {
                $name = strtolower(ltrim($decl->value, '\\'));
                if ('self' === $name) {
                    $sig->self = true;

                    return $sig;
                }
                if ('static' === $name) {
                    $sig->static = true;

                    return $sig;
                }
                $sig->classDisplay = ltrim($decl->value, '\\');
                $sig->classLc = strtolower($sig->classDisplay);

                return $sig;
            }
        }

        return null;
    }

    /**
     * Parse Reflection/ParameterMetadata dump type strings ("int", "?Foo\\Bar") for #25384.
     */
    public static function fromDumpTypeString(?string $typeString): ?self
    {
        if (null === $typeString || '' === $typeString) {
            return null;
        }
        $nullable = false;
        if ('?' === $typeString[0]) {
            $nullable = true;
            $typeString = substr($typeString, 1);
        }
        if ('' === $typeString || 'mixed' === strtolower($typeString)) {
            return null;
        }
        // Unions / intersections are uncommon in ParameterMetadata dumps; reject to null (unchecked).
        if (false !== strpos($typeString, '|') || false !== strpos($typeString, '&')) {
            return null;
        }
        $literal = new Op\Type\Literal($typeString);
        $sig = self::fromCfgType($literal);
        if (null === $sig) {
            return null;
        }
        if ($nullable) {
            $sig->nullable = true;
        }

        return $sig;
    }

    /**
     * Property type parsing — unlike {@see fromCfgType()}, keeps `mixed` and unions
     * (zend_inheritance.c property invariance, #23505).
     */
    public static function fromCfgPropertyType(?Op\Type $type): ?self
    {
        if (null === $type) {
            return null;
        }
        // php-cfg uses Mixed_ as the placeholder for *untyped* properties; explicit
        // `mixed` arrives as Op\Type\Literal("mixed") below (#23505).
        if ($type instanceof Op\Type\Mixed_) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            $inner = self::fromCfgPropertyType($type->subtype);
            if (null === $inner) {
                return null;
            }
            $inner->nullable = true;

            return $inner;
        }
        if ($type instanceof Op\Type\Union_) {
            $members = [];
            foreach ($type->types as $memberType) {
                $member = self::fromCfgPropertyType($memberType);
                if (null === $member || $member->isMixed()) {
                    return null;
                }
                $members[] = $member;
            }
            if ([] === $members) {
                return null;
            }
            $sig = new self();
            $sig->unionMembers = $members;

            return $sig;
        }
        if ($type instanceof Op\Type\Intersection) {
            $members = [];
            foreach ($type->types as $memberType) {
                $member = self::fromCfgPropertyType($memberType);
                if (null === $member || $member->isMixed()) {
                    return null;
                }
                $members[] = $member;
            }
            if ([] === $members) {
                return null;
            }
            $sig = new self();
            $sig->intersectionMembers = $members;

            return $sig;
        }
        if ($type instanceof Op\Type\Literal && 'mixed' === strtolower($type->name)) {
            $sig = new self();
            $sig->builtinScalar = 'mixed';

            return $sig;
        }

        return self::fromCfgType($type);
    }

    /**
     * Structural key for property type invariance (keeps self/static unresolved).
     * Union members are sorted so declaration order does not matter.
     */
    public function propertyInvariantKey(string $ownerLc): string
    {
        if ($this->void) {
            return 'void';
        }
        if ($this->never) {
            return 'never';
        }
        if ($this->isUnion()) {
            $parts = [];
            foreach ($this->unionMembers as $member) {
                $parts[] = $member->propertyInvariantKey($ownerLc);
            }
            sort($parts);

            return implode('|', $parts).($this->nullable ? '?' : '');
        }
        if ($this->isIntersection()) {
            $parts = [];
            foreach ($this->intersectionMembers as $member) {
                $parts[] = $member->propertyInvariantKey($ownerLc);
            }
            sort($parts);

            return '('.implode('&', $parts).')'.($this->nullable ? '?' : '');
        }
        if ($this->self) {
            return ($this->nullable ? '?' : '').'self';
        }
        if ($this->static) {
            return ($this->nullable ? '?' : '').'static';
        }
        if (null !== $this->builtinScalar) {
            return ($this->nullable ? '?' : '').$this->builtinScalar;
        }
        if (null !== $this->classLc) {
            return ($this->nullable ? '?' : '').$this->classLc;
        }

        return $this->signatureKey($ownerLc);
    }

    /**
     * Like {@see propertyInvariantKey()} but resolves self/static to the declaring class
     * so `self` on A matches an explicit `A` on a child (#23505).
     */
    public function propertyResolvedKey(string $ownerLc): string
    {
        if ($this->isUnion()) {
            $parts = [];
            foreach ($this->unionMembers as $member) {
                $parts[] = $member->propertyResolvedKey($ownerLc);
            }
            sort($parts);

            return implode('|', $parts).($this->nullable ? '?' : '');
        }
        if ($this->isIntersection()) {
            $parts = [];
            foreach ($this->intersectionMembers as $member) {
                $parts[] = $member->propertyResolvedKey($ownerLc);
            }
            sort($parts);

            return '('.implode('&', $parts).')'.($this->nullable ? '?' : '');
        }
        if ($this->self || $this->static) {
            return ($this->nullable ? '?' : '').$ownerLc;
        }

        return $this->propertyInvariantKey($ownerLc);
    }

    /**
     * Zend property type invariance: identical unresolved shape, or same type after
     * resolving self/static on the declaring class (zend_inheritance.c, #23505).
     */
    public static function propertyTypesAreInvariant(
        ?self $parent,
        ?self $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        if (null === $parent && null === $child) {
            return true;
        }
        if (null === $parent || null === $child) {
            return false;
        }
        if ($parent->propertyInvariantKey($parentOwnerLc) === $child->propertyInvariantKey($childOwnerLc)) {
            return true;
        }

        return $parent->propertyResolvedKey($parentOwnerLc) === $child->propertyResolvedKey($childOwnerLc);
    }

    public function isIntersection(): bool
    {
        return null !== $this->intersectionMembers && [] !== $this->intersectionMembers;
    }

    public function isUnion(): bool
    {
        return null !== $this->unionMembers && [] !== $this->unionMembers;
    }

    public function isMixed(): bool
    {
        return !$this->void && !$this->never && null === $this->builtinScalar && null === $this->classLc
            && !$this->self && !$this->static && !$this->isIntersection() && !$this->isUnion();
    }

    public function isVoid(): bool
    {
        return $this->void;
    }

    public function isNever(): bool
    {
        return $this->never;
    }

    public function resolveClassName(string $ownerLc): ?string
    {
        if ($this->self || $this->static) {
            return $ownerLc;
        }

        return $this->classLc;
    }

    public function signatureKey(string $ownerLc): string
    {
        if ($this->void) {
            return 'void';
        }
        if ($this->never) {
            return 'never';
        }
        if ($this->isUnion()) {
            $parts = [];
            foreach ($this->unionMembers as $member) {
                $parts[] = $member->signatureKey($ownerLc);
            }
            sort($parts);

            return implode('|', $parts).($this->nullable ? '?' : '');
        }
        if ($this->isIntersection()) {
            $parts = [];
            foreach ($this->intersectionMembers as $member) {
                $parts[] = $member->signatureKey($ownerLc);
            }
            sort($parts);

            return '('.implode('&', $parts).')'.($this->nullable ? '?' : '');
        }
        if (null !== $this->builtinScalar) {
            return $this->builtinScalar.($this->nullable ? '?' : '');
        }
        $class = $this->resolveClassName($ownerLc) ?? '';

        return $class.($this->nullable ? '?' : '').($this->static ? ':static' : '');
    }

    /**
     * Human-readable type for diagnostics.
     *
     * When $selfDisplayClass is set (LSP fatals), Zend resolves `self` to the declaring
     * class name and keeps `static` as `static` (zend_inheritance.c, #26641).
     */
    public function format(?string $selfDisplayClass = null): string
    {
        if ($this->void) {
            return 'void';
        }
        if ($this->never) {
            return 'never';
        }
        if ($this->isUnion()) {
            $parts = [];
            foreach ($this->unionMembers as $member) {
                $parts[] = $member->format($selfDisplayClass);
            }

            return ($this->nullable ? '?' : '').implode('|', $parts);
        }
        if ($this->isIntersection()) {
            $parts = [];
            foreach ($this->intersectionMembers as $member) {
                $parts[] = $member->format($selfDisplayClass);
            }

            return ($this->nullable ? '?' : '').implode('&', $parts);
        }
        if (null !== $this->builtinScalar) {
            return ($this->nullable ? '?' : '').$this->builtinScalar;
        }
        if ($this->self) {
            $name = null !== $selfDisplayClass && '' !== $selfDisplayClass
                ? $selfDisplayClass
                : 'self';

            return ($this->nullable ? '?' : '').$name;
        }
        if ($this->static) {
            return ($this->nullable ? '?' : '').'static';
        }
        if (null !== $this->classLc) {
            return ($this->nullable ? '?' : '').($this->classDisplay ?? $this->classLc);
        }

        return '';
    }
}
