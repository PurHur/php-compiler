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
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time parameter contravariance / return covariance (Zend zend_inheritance.c, issue #3323).
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
    }

    private function indexInterface(Interface_ $iface): void
    {
        $lc = $this->classLcFromOperand($iface->name);
        if (null === $lc) {
            return;
        }
        $this->units[$lc] = $iface;
        $this->interfaceExtends[$lc] = $this->interfaceNamesFromOperands($iface->extends);
        $this->methods[$lc] = $this->extractMethods($iface, $lc);
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
    private function extractMethods(ClassLike $unit, string $ownerLc): array
    {
        $methods = [];
        foreach ($unit->stmts->children as $child) {
            if (!$child instanceof ClassMethod) {
                continue;
            }
            $name = strtolower($child->func->name);
            $methods[$name] = MethodSig::fromFunc($child->func, $ownerLc);
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
                    $report($msg);
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
                    $report($msg);
                }
            }
        }
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
        if ('__construct' === $methodLc && !$parent->isAbstract) {
            return null;
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
        if (!self::isReturnCompatibleStatic(
            $parent->returnType,
            $child->returnType,
            $parent->ownerLc,
            $child->ownerLc,
            $isClassSubtypeOf,
            $classImplementsInterface
        )) {
            return self::formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
        }

        return null;
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

    private static function formatDeclarationError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent
    ): string {
        return sprintf(
            'Declaration of %s::%s(%s)%s must be compatible with %s::%s(%s)%s',
            $childClass,
            $methodLc,
            $child->formatParams(),
            $child->formatReturn(),
            $parentClass,
            $methodLc,
            $parent->formatParams(),
            $parent->formatReturn()
        );
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

    public ?TypeSig $returnType;

    public string $ownerLc;

    public bool $isAbstract;

    public bool $isFinal;

    /** @see Func::FLAG_PUBLIC|FLAG_PROTECTED|FLAG_PRIVATE */
    public int $visibilityFlags;

    /**
     * @param list<?TypeSig>   $params
     * @param list<string>     $paramNames
     * @param list<bool>       $paramHasDefault
     */
    public function __construct(
        string $ownerLc,
        array $params,
        array $paramNames,
        array $paramHasDefault,
        ?TypeSig $returnType,
        bool $isAbstract = false,
        int $visibilityFlags = Func::FLAG_PUBLIC,
        bool $isFinal = false
    ) {
        $this->ownerLc = $ownerLc;
        $this->params = $params;
        $this->paramNames = $paramNames;
        $this->paramHasDefault = $paramHasDefault;
        $this->returnType = $returnType;
        $this->isAbstract = $isAbstract;
        $this->isFinal = $isFinal;
        $this->visibilityFlags = $visibilityFlags;
    }

    public static function fromFunc(Func $func, string $ownerLc): self
    {
        $params = [];
        $names = [];
        $hasDefault = [];
        foreach ($func->params as $param) {
            $params[] = TypeSig::fromCfgType($param->declaredType);
            $names[] = self::paramNameFromOperand($param->name);
            $hasDefault[] = null !== $param->defaultVar;
        }
        $isAbstract = 0 !== ($func->flags & Func::FLAG_ABSTRACT);
        $visibility = $func->flags & (Func::FLAG_PUBLIC | Func::FLAG_PROTECTED | Func::FLAG_PRIVATE);
        if (0 === $visibility) {
            $visibility = Func::FLAG_PUBLIC;
        }

        $isFinal = 0 !== ($func->flags & Func::FLAG_FINAL);

        return new self(
            $ownerLc,
            $params,
            $names,
            $hasDefault,
            TypeSig::fromCfgType($func->returnType),
            $isAbstract,
            $visibility,
            $isFinal
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

    public function formatParams(): string
    {
        $parts = [];
        foreach ($this->params as $i => $type) {
            $prefix = $type instanceof TypeSig ? $type->format().' ' : '';
            $parts[] = $prefix.'$'.($this->paramNames[$i] ?? 'param');
        }

        return implode(', ', $parts);
    }

    public function formatReturn(): string
    {
        if (null === $this->returnType || $this->returnType->isMixed()) {
            return '';
        }

        return ': '.$this->returnType->format();
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

    public function isIntersection(): bool
    {
        return null !== $this->intersectionMembers && [] !== $this->intersectionMembers;
    }

    public function isMixed(): bool
    {
        return !$this->void && !$this->never && null === $this->builtinScalar && null === $this->classLc
            && !$this->self && !$this->static && !$this->isIntersection();
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

    public function format(): string
    {
        if ($this->void) {
            return 'void';
        }
        if ($this->never) {
            return 'never';
        }
        if ($this->isIntersection()) {
            $parts = [];
            foreach ($this->intersectionMembers as $member) {
                $parts[] = $member->format();
            }

            return ($this->nullable ? '?' : '').implode('&', $parts);
        }
        if (null !== $this->builtinScalar) {
            return ($this->nullable ? '?' : '').$this->builtinScalar;
        }
        if ($this->self) {
            return ($this->nullable ? '?' : '').'self';
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
