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
            if (!$unit instanceof Class_) {
                continue;
            }
            $this->validateClass($lc, $unit, $report);
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

    private function compatibilityError(
        string $childClass,
        string $methodLc,
        MethodSig $child,
        string $parentClass,
        MethodSig $parent
    ): ?string {
        // Zend: concrete inherited constructors are not subject to parameter/return
        // compatibility (child may call parent::__construct with a different signature).
        // Abstract/interface constructors still enforce compatibility (#55375).
        if ('__construct' === $methodLc && !$parent->isAbstract) {
            return null;
        }

        if (count($child->params) < count($parent->params)) {
            for ($i = count($child->params); $i < count($parent->params); ++$i) {
                if (!($parent->paramHasDefault[$i] ?? false)) {
                    return $this->formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
                }
            }
        }
        if ($parent->isAbstract && count($child->params) > count($parent->params)) {
            for ($i = count($parent->params); $i < count($child->params); ++$i) {
                if (!($child->paramHasDefault[$i] ?? false)) {
                    return $this->formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
                }
            }
        }
        $paramCount = min(count($child->params), count($parent->params));
        for ($i = 0; $i < $paramCount; ++$i) {
            if (!$this->isParameterCompatible($parent->params[$i], $child->params[$i], $parent->ownerLc, $child->ownerLc)) {
                return $this->formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
            }
        }
        if (!$this->isReturnCompatible($parent->returnType, $child->returnType, $parent->ownerLc, $child->ownerLc)) {
            return $this->formatDeclarationError($childClass, $methodLc, $child, $parentClass, $parent);
        }

        return null;
    }

    private function isParameterCompatible(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        if (null === $parent || $parent->isMixed()) {
            return true;
        }
        if (null === $child || $child->isMixed()) {
            return true;
        }
        if ($parent->nullable && !$child->nullable) {
            return false;
        }
        if ($parent->builtinScalar !== null || $child->builtinScalar !== null) {
            return $parent->signatureKey($parentOwnerLc) === $child->signatureKey($childOwnerLc);
        }
        $parentClass = $parent->resolveClassName($parentOwnerLc);
        $childClass = $child->resolveClassName($childOwnerLc);
        if (null === $parentClass || null === $childClass) {
            return $parent->signatureKey($parentOwnerLc) === $child->signatureKey($childOwnerLc);
        }
        if ($parentClass === $childClass) {
            return true;
        }

        return $this->isClassSubtypeOf($parentClass, $childClass);
    }

    private function isReturnCompatible(
        ?TypeSig $parent,
        ?TypeSig $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        if (null === $parent || $parent->isMixed()) {
            return true;
        }
        if (null === $child || $child->isMixed()) {
            return false;
        }
        if ($parent->isVoid()) {
            return $child->isVoid();
        }
        if ($parent->isNever()) {
            return $child->isNever();
        }
        if (!$parent->nullable && $child->nullable) {
            return true;
        }
        if ($parent->nullable && !$child->nullable && !$child->isVoid() && !$child->isNever()) {
            return false;
        }
        if ($parent->builtinScalar !== null || $child->builtinScalar !== null) {
            return $parent->signatureKey($parentOwnerLc) === $child->signatureKey($childOwnerLc);
        }
        $parentClass = $parent->resolveClassName($parentOwnerLc);
        $childClass = $child->resolveClassName($childOwnerLc);
        if (null === $parentClass || null === $childClass) {
            return $parent->signatureKey($parentOwnerLc) === $child->signatureKey($childOwnerLc);
        }
        if ($parentClass === $childClass) {
            return true;
        }
        if ($this->isClassSubtypeOf($childClass, $parentClass)) {
            return true;
        }

        return $this->classImplementsInterface($childClass, $parentClass);
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

    private function formatDeclarationError(
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
        bool $isAbstract = false
    ) {
        $this->ownerLc = $ownerLc;
        $this->params = $params;
        $this->paramNames = $paramNames;
        $this->paramHasDefault = $paramHasDefault;
        $this->returnType = $returnType;
        $this->isAbstract = $isAbstract;
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

        return new self(
            $ownerLc,
            $params,
            $names,
            $hasDefault,
            TypeSig::fromCfgType($func->returnType),
            $isAbstract
        );
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
                $sig->classDisplay = ltrim($decl->value, '\\');
                $sig->classLc = strtolower($sig->classDisplay);

                return $sig;
            }
        }

        return null;
    }

    public function isMixed(): bool
    {
        return !$this->void && !$this->never && null === $this->builtinScalar && null === $this->classLc && !$this->self && !$this->static;
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
