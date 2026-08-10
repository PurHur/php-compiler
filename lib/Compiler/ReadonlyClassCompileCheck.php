<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\Param;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor\DeclarationFinder;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\CompilerVersion;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ClassReadonly;

/**
 * Compile-time checks for readonly class inheritance, property defaults (#3551, #3149),
 * and readonly property override compatibility (#7359, #7367).
 * Declarations nested under try/catch/finally are included (#26739).
 *
 * php-src: Zend/zend_compile.c — zend_compile_class_decl, zend_compile_property_info();
 * Zend/zend_inheritance.c — inheritance_check_properties(), readonly parent/child checks;
 * per-property MODIFIER_READONLY cannot have default initializer (#3149, #3551);
 * PHP 8.2+ readonly class (ZEND_ACC_READONLY) inherits readonly on instance props — defaults rejected (#18090, re-#18074);
 * PHP 8.2+ readonly class also sets ZEND_ACC_READONLY on static props — Zend then applies
 *   type → default → "Static property … cannot be readonly" (#29980; no separate class-level static ban);
 * PHP 8.3+ anonymous classes may use per-property `readonly` with defaults (#6724);
 * PHP 8.3+ `new readonly class` sets ZEND_ACC_READONLY on the anonymous class (#6991);
 * PHP 8.4+ hooked properties cannot be readonly (#19172, zend_compile_property_hooks);
 * static readonly with default → "cannot have default value" before static ban (#26487).
 */
final class ReadonlyClassCompileCheck
{
    /** php-src: zend_compile_property_hooks — readonly flag on hooked property (#19172). */
    public const HOOKED_PROPERTY_READONLY_COMPILE_ERROR = 'Hooked properties cannot be readonly';
    /** @var array<string, array{display: string, readonly: bool, extends: ?string, properties: array<string, array{readonly: bool, display: string}>}> */
    private array $classes = [];

    /**
     * @param array<string, array{display: string, readonly: bool, extends: ?string}> $knownClasses
     *        Already-registered user classes (eval parent lookup, #7170).
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    public static function validate(Script $script, array $knownClasses = [], array $propertyHookRegistry = []): void
    {
        $check = new self($knownClasses, $propertyHookRegistry);
        $check->collect($script);
        // Inheritance before per-property defaults so MCJIT readonly pads do not mask extends errors (#8967).
        $check->verifyInheritance();
        $check->verifyPropertyReadonlyOverrides();
        $check->verifyReadonlyPropertyRequiresType();
        $check->verifyNoHookedReadonlyProperties();
        $check->verifyAllPropertyDefaults();
    }

    /**
     * @param array<string, array{display: string, readonly: bool, extends: ?string}> $knownClasses
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    private function __construct(
        private array $knownClasses = [],
        private array $propertyHookRegistry = [],
    ) {
    }

    private function collect(Script $script): void
    {
        // php-cfg nests declarations after try/catch merge blocks — not only main->cfg children
        // (#26739 / FinalClassExtensionCheck #9722). Zend compile-fatals the unit; try cannot catch it.
        $finder = new DeclarationFinder();
        $traverser = new Traverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($script);
        foreach ($finder->getClasses() as $class) {
            $this->collectClass($class);
        }
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $readonly = ClassReadonly::fromClassFlags($class->flags);
        $display = $this->operandDisplayName($class->name, $lc);
        if ($readonly) {
            AttributeNames::assertAllowDynamicPropertiesNotOnReadonlyClass(
                AttributeNames::fromOp($class),
                $display
            );
            // php-src zend_compile_property_info: ZEND_ACC_READONLY_CLASS ⇒ ZEND_ACC_READONLY on
            // every property (including static). Fatal order: type → default → static (#29980).
            $this->verifyReadonlyClassStaticPropertySemantics($class, $display);
        }
        $this->verifyNoStaticReadonlyProperties($class, $display);
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $display,
            'readonly' => $readonly,
            'extends' => $parentLc,
            'properties' => $this->collectInstanceProperties($class, $readonly),
        ];
        $this->scriptClasses[$lc][] = $class;
    }

    /**
     * @return array<string, array{readonly: bool, display: string}>
     */
    private function collectInstanceProperties(Op\Stmt\Class_ $class, bool $classReadonly): array
    {
        $properties = [];
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property || $member->static) {
                continue;
            }
            $propDisplay = $this->propertyDisplayName($member->name);
            $propLc = strtolower($propDisplay);
            $properties[$propLc] = [
                'readonly' => $classReadonly || $this->isCfgPropertyReadonly($member),
                'display' => $propDisplay,
            ];
        }

        return $properties;
    }

    /**
     * Readonly-class static members: match zend_compile_property_info fatal order (#29980).
     * php-src does not emit "Readonly class … cannot declare static properties".
     */
    private function verifyReadonlyClassStaticPropertySemantics(Op\Stmt\Class_ $class, string $classDisplay): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property || !$member->static) {
                continue;
            }
            $propName = $this->propertyDisplayName($member->name);
            if (!$this->propertyHasDeclaredType($member->declaredType ?? null)) {
                throw new \CompileError(
                    "Readonly property {$classDisplay}::\${$propName} must have type"
                );
            }
            if (null !== $member->defaultVar || null !== $member->defaultBlock) {
                throw new \CompileError(
                    "Readonly property {$classDisplay}::\${$propName} cannot have default value"
                );
            }
            throw new \CompileError(
                "Static property {$classDisplay}::\${$propName} cannot be readonly"
            );
        }
    }

    private function verifyNoStaticReadonlyProperties(Op\Stmt\Class_ $class, string $classDisplay): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property || !$member->static) {
                continue;
            }
            // Readonly-class statics already handled in verifyReadonlyClassStaticPropertySemantics.
            if (!$this->isCfgPropertyReadonly($member)) {
                continue;
            }
            $propName = $this->propertyDisplayName($member->name);
            // php-src zend_compile_property_info: default-value fatal before static-readonly (#26487)
            if (null !== $member->defaultVar || null !== $member->defaultBlock) {
                throw new \CompileError(
                    "Readonly property {$classDisplay}::\${$propName} cannot have default value"
                );
            }
            throw new \CompileError(
                "Static property {$classDisplay}::\${$propName} cannot be readonly"
            );
        }
    }

    private function verifyReadonlyPropertyRequiresType(): void
    {
        foreach ($this->classes as $lc => $meta) {
            foreach ($this->scriptClasses[$lc] ?? [] as $class) {
                $this->verifyReadonlyPropertiesHaveType($class, $meta['display'], $meta['readonly']);
            }
        }
    }

    private function verifyReadonlyPropertiesHaveType(
        Op\Stmt\Class_ $class,
        string $classDisplay,
        bool $classReadonly
    ): void {
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property && !$member->static) {
                if (!$classReadonly && !$this->isCfgPropertyReadonly($member)) {
                    continue;
                }
                if ($this->propertyHasDeclaredType($member->declaredType ?? null)) {
                    continue;
                }
                $propName = $this->propertyDisplayName($member->name);
                throw new \CompileError(
                    "Readonly property {$classDisplay}::\${$propName} must have type"
                );
            }
            if (!$member instanceof Op\Stmt\ClassMethod || !$this->isConstructor($member)) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                if (!$classReadonly && !$this->isPromotedParamReadonly($param)) {
                    continue;
                }
                if ($this->propertyHasDeclaredType($param->declaredType ?? null)) {
                    continue;
                }
                $propName = $this->propertyDisplayName($param->name);
                throw new \CompileError(
                    "Readonly property {$classDisplay}::\${$propName} must have type"
                );
            }
        }
    }

    private function propertyHasDeclaredType(?Op\Type $declaredType): bool
    {
        return null !== $declaredType && !$declaredType instanceof Op\Type\Mixed_;
    }

    private function verifyNoHookedReadonlyProperties(): void
    {
        foreach ($this->classes as $lc => $meta) {
            $hooks = $this->propertyHookRegistry[$lc] ?? [];
            if ([] === $hooks) {
                continue;
            }
            foreach (array_keys($hooks) as $propLc) {
                if ($this->hookedPropertyIsReadonly($lc, $propLc, $meta)) {
                    throw new \CompileError(self::HOOKED_PROPERTY_READONLY_COMPILE_ERROR);
                }
            }
        }
    }

    /**
     * @param array{display: string, readonly: bool, extends: ?string, properties: array<string, array{readonly: bool, display: string}>} $meta
     */
    private function hookedPropertyIsReadonly(string $classLc, string $propLc, array $meta): bool
    {
        if ($meta['readonly']) {
            return true;
        }
        if ($meta['properties'][$propLc]['readonly'] ?? false) {
            return true;
        }
        foreach ($this->scriptClasses[$classLc] ?? [] as $class) {
            foreach ($class->stmts->children as $member) {
                if (!$member instanceof Op\Stmt\ClassMethod || !$this->isConstructor($member)) {
                    continue;
                }
                foreach ($member->func->params as $param) {
                    if (!$this->isPromotedParam($param)) {
                        continue;
                    }
                    $paramLc = strtolower($this->propertyDisplayName($param->name));
                    if ($paramLc !== $propLc) {
                        continue;
                    }
                    if ($this->isPromotedParamReadonly($param)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function verifyAllPropertyDefaults(): void
    {
        foreach ($this->classes as $lc => $meta) {
            // Re-walk CFG for property default checks (deferred until after inheritance, #8967).
            foreach ($this->scriptClasses[$lc] ?? [] as $class) {
                $this->verifyNoPropertyDefaults($class, $meta['display'], $meta['readonly']);
            }
        }
    }

    /** @var array<string, list<Op\Stmt\Class_>> */
    private array $scriptClasses = [];

    private function verifyNoPropertyDefaults(Op\Stmt\Class_ $class, string $classDisplay, bool $classReadonly): void
    {
        // php-src ZEND_ACC_ANON_READONLY: per-property readonly on anonymous classes (#6724, PHP 8.3+).
        if ($this->isAnonymousClass($class->name) && CompilerVersion::supportsReadonlyAnonymousClass()) {
            return;
        }
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\Property) {
                continue;
            }
            if (!$classReadonly && !$this->isCfgPropertyReadonly($member)) {
                continue;
            }
            if (null === $member->defaultVar && null === $member->defaultBlock) {
                continue;
            }
            $propName = $this->propertyDisplayName($member->name);
            throw new \CompileError(
                "Readonly property {$classDisplay}::\${$propName} cannot have default value"
            );
        }
    }

    private function verifyInheritance(): void
    {
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc) {
                continue;
            }
            $parent = $this->classes[$parentLc] ?? $this->knownClasses[$parentLc] ?? null;
            if (null === $parent) {
                continue;
            }
            if ($parent['readonly'] && !$class['readonly']) {
                throw new \CompileError(
                    "Non-readonly class {$class['display']} cannot extend readonly class {$parent['display']}"
                );
            }
            if ($class['readonly'] && !$parent['readonly']) {
                throw new \CompileError(
                    "Readonly class {$class['display']} cannot extend non-readonly class {$parent['display']}"
                );
            }
        }
    }

    private function verifyPropertyReadonlyOverrides(): void
    {
        foreach ($this->classes as $classLc => $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc) {
                continue;
            }
            foreach ($class['properties'] as $propLc => $childProp) {
                $parentProp = $this->findInheritedProperty($parentLc, $propLc);
                if (null === $parentProp) {
                    continue;
                }
                if ($parentProp['readonly'] && !$childProp['readonly']) {
                    throw new \CompileError(sprintf(
                        'Cannot redeclare readonly property %s::$%s as non-readonly %s::$%s',
                        $parentProp['ownerDisplay'],
                        $parentProp['display'],
                        $class['display'],
                        $childProp['display']
                    ));
                }
                if (!$parentProp['readonly'] && $childProp['readonly']) {
                    throw new \CompileError(sprintf(
                        'Cannot redeclare non-readonly property %s::$%s as readonly %s::$%s',
                        $parentProp['ownerDisplay'],
                        $parentProp['display'],
                        $class['display'],
                        $childProp['display']
                    ));
                }
            }
        }
    }

    /**
     * @return array{readonly: bool, display: string, ownerDisplay: string}|null
     */
    private function findInheritedProperty(string $startParentLc, string $propLc): ?array
    {
        $current = $startParentLc;
        $visited = [];
        $guard = 0;
        while (null !== $current && '' !== $current && !isset($visited[$current])) {
            if (++$guard > 256) {
                break;
            }
            $visited[$current] = true;
            $type = $this->classes[$current] ?? null;
            if (null !== $type && isset($type['properties'][$propLc])) {
                $prop = $type['properties'][$propLc];

                return [
                    'readonly' => $prop['readonly'],
                    'display' => $prop['display'],
                    'ownerDisplay' => $type['display'],
                ];
            }
            if (null !== $type) {
                $current = $type['extends'];
            } elseif (null !== ($known = $this->knownClasses[$current] ?? null)) {
                $current = $known['extends'];
            } else {
                break;
            }
        }

        return null;
    }

    private function isConstructor(Op\Stmt\ClassMethod $method): bool
    {
        $name = $method->func->name ?? null;
        if (!is_string($name)) {
            return false;
        }

        return '__construct' === strtolower($name);
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== MethodVisibility::mask($param->promotionFlags)
            || (property_exists($param, 'promotionSetVisibility') && 0 !== (int) $param->promotionSetVisibility)
            || 0 !== AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($param->getAttributes());
    }

    private function isPromotedParamReadonly(Param $param): bool
    {
        return property_exists($param, 'promotionReadonly') && $param->promotionReadonly;
    }

    private function isCfgPropertyReadonly(Op\Stmt\Property $member): bool
    {
        if (property_exists($member, 'readonly') && $member->readonly) {
            return true;
        }
        if (property_exists($member, 'propertyFlags')
            && ClassReadonly::fromClassFlags($member->propertyFlags)) {
            return true;
        }

        return ClassReadonly::fromClassFlags($member->visibility);
    }

    private function propertyDisplayName(Operand $op): string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->propertyDisplayName($op->name);
        }

        return 'property';
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallbackLc): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallbackLc;
        }
        $short = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));
            $short = end($parts) ?: $name;
        }

        return $short;
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }

    /** php-src: zend_compile.c — anonymous class names contain @anonymous. */
    private function isAnonymousClass(Operand $op): bool
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return false;
        }

        return str_contains($name, '@anonymous');
    }
}
