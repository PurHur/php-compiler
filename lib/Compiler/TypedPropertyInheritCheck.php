<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;
use PHPCompiler\SourcePreprocessor\PropertyHooks;

/**
 * Compile-time check: non-private property types are invariant across inheritance
 * (zend_inheritance.c do_inherit_property / verify_property_type_compatibility, #23505).
 *
 * php-src-strict: child may not change, add, or remove a parent property type.
 * Private parent properties may be redeclared freely.
 *
 * When both sides lower property hooks, Zend reports hook method prototypes first
 * (`Class::$prop::get()` / `::set()`) via inherit_property_hook → emit_incompatible_method_error
 * (#29690), not the plain property-type LSP string.
 */
final class TypedPropertyInheritCheck
{
    /**
     * @var array<string, array{
     *     display: string,
     *     extends: ?string,
     *     properties: array<string, array{display: string, type: ?TypeSig, private: bool, static: bool, file: string, line: int}>,
     *     hooks: array<string, array{get: bool, set: ?array{type: ?TypeSig, name: string}}>
     * }>
     */
    private array $classes = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                $this->collectClass($child);
            }
        }
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $parentLc = null !== $class->extends ? $this->operandLcName($class->extends) : null;
        $properties = $this->collectProperties($class);
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'properties' => $properties,
            'hooks' => $this->collectHookMethods($class),
        ];
    }

    /**
     * @return array<string, array{display: string, type: ?TypeSig, private: bool, static: bool, file: string, line: int}>
     */
    private function collectProperties(Op\Stmt\Class_ $class): array
    {
        $properties = [];
        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $propDisplay = $this->propertyDisplayName($member->name);
                $propLc = strtolower($propDisplay);
                $vis = (int) $member->visibility;
                $properties[$propLc] = [
                    'display' => $propDisplay,
                    'type' => TypeSig::fromCfgPropertyType($member->declaredType ?? null),
                    'private' => 0 !== ($vis & ClassNode::MODIFIER_PRIVATE),
                    'static' => (bool) $member->static,
                    'file' => $member->getFile(),
                    'line' => max(1, $member->getLine()),
                ];
                continue;
            }
            if (!$member instanceof Op\Stmt\ClassMethod || '__construct' !== $member->func->name) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!($param instanceof Op\Expr\Param)) {
                    continue;
                }
                if (!property_exists($param, 'promotionFlags') || 0 === (int) $param->promotionFlags) {
                    continue;
                }
                if (!($param->name instanceof Operand\Literal) || !is_string($param->name->value)) {
                    continue;
                }
                $propDisplay = $param->name->value;
                $propLc = strtolower($propDisplay);
                $flags = (int) $param->promotionFlags;
                $properties[$propLc] = [
                    'display' => $propDisplay,
                    'type' => TypeSig::fromCfgPropertyType($param->declaredType ?? null),
                    'private' => 0 !== ($flags & ClassNode::MODIFIER_PRIVATE),
                    'static' => false,
                    'file' => $param->getFile(),
                    'line' => max(1, $param->getLine()),
                ];
            }
        }

        return $properties;
    }

    /**
     * Synthetic PropertyHooks methods after lowering (`__phpc_property_{get,set}_*`).
     *
     * @return array<string, array{get: bool, set: ?array{type: ?TypeSig, name: string}}>
     */
    private function collectHookMethods(Op\Stmt\Class_ $class): array
    {
        $hooks = [];
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodLc = strtolower($member->func->name);
            $prop = PropertyHooks::propertyNameFromGetHookMethod($methodLc);
            if (null !== $prop) {
                $hooks[$prop]['get'] = true;
                if (!isset($hooks[$prop]['set'])) {
                    $hooks[$prop]['set'] = null;
                }
                continue;
            }
            $prop = PropertyHooks::propertyNameFromSetHookMethod($methodLc);
            if (null === $prop) {
                continue;
            }
            $paramName = 'value';
            $paramType = null;
            if (isset($member->func->params[0]) && $member->func->params[0] instanceof Op\Expr\Param) {
                $param = $member->func->params[0];
                if ($param->name instanceof Operand\Literal && is_string($param->name->value)) {
                    $paramName = $param->name->value;
                }
                $paramType = TypeSig::fromCfgPropertyType($param->declaredType ?? null);
            }
            if (!isset($hooks[$prop]['get'])) {
                $hooks[$prop]['get'] = false;
            }
            $hooks[$prop]['set'] = [
                'type' => $paramType,
                'name' => $paramName,
            ];
        }

        return $hooks;
    }

    private function verify(): void
    {
        foreach ($this->classes as $childLc => $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($class['properties'] as $propLc => $childProp) {
                $inherited = $this->findInheritedProperty($parentLc, $propLc);
                if (null === $inherited) {
                    continue;
                }
                $parentProp = $inherited['prop'];
                if ($parentProp['private']) {
                    continue;
                }
                $parentType = $parentProp['type'];
                $childType = $childProp['type'];
                if (TypeSig::propertyTypesAreInvariant(
                    $parentType,
                    $childType,
                    $inherited['ownerLc'],
                    $childLc
                )) {
                    continue;
                }
                $hookFatal = $this->hookDeclarationCompatibilityError(
                    $class['display'],
                    $childProp['display'],
                    $childType,
                    $inherited['ownerDisplay'],
                    $parentType,
                    $class['hooks'][$propLc] ?? null,
                    $this->classes[$inherited['ownerLc']]['hooks'][$propLc] ?? null
                );
                if (null !== $hookFatal) {
                    throw new CompileFatal(
                        $childProp['file'] !== '' ? $childProp['file'] : 'unknown',
                        $childProp['line'],
                        $hookFatal
                    );
                }
                if (null === $parentType && null !== $childType) {
                    // PHP 8.2 wording (docker profile); master uses "must be omitted…".
                    throw new CompileFatal(
                        $childProp['file'] !== '' ? $childProp['file'] : 'unknown',
                        $childProp['line'],
                        sprintf(
                            'Type of %s::$%s must not be defined (as in class %s)',
                            $class['display'],
                            $childProp['display'],
                            $inherited['ownerDisplay']
                        )
                    );
                }
                $required = null !== $parentType ? $parentType->format() : '';
                throw new CompileFatal(
                    $childProp['file'] !== '' ? $childProp['file'] : 'unknown',
                    $childProp['line'],
                    sprintf(
                        'Type of %s::$%s must be %s (as in class %s)',
                        $class['display'],
                        $childProp['display'],
                        $required,
                        $inherited['ownerDisplay']
                    )
                );
            }
        }
    }

    /**
     * Zend inherit_property_hook runs before verify_property_type_compatibility: when both
     * sides declare the same hook kind, the Fatal cites `$prop::get` / `$prop::set` prototypes
     * (zend_inheritance.c / zend_get_function_declaration, #29690).
     *
     * @param array{get: bool, set: ?array{type: ?TypeSig, name: string}}|null $childHooks
     * @param array{get: bool, set: ?array{type: ?TypeSig, name: string}}|null $parentHooks
     */
    private function hookDeclarationCompatibilityError(
        string $childClass,
        string $propDisplay,
        ?TypeSig $childType,
        string $parentClass,
        ?TypeSig $parentType,
        ?array $childHooks,
        ?array $parentHooks
    ): ?string {
        if (null === $childHooks || null === $parentHooks) {
            return null;
        }
        // GET is checked first in Zend (ZEND_PROPERTY_HOOK_GET = 0).
        if (!empty($childHooks['get']) && !empty($parentHooks['get'])) {
            $childRet = null !== $childType ? $childType->format($childClass) : '';
            $parentRet = null !== $parentType ? $parentType->format($parentClass) : '';

            return sprintf(
                'Declaration of %s::$%s::get(): %s must be compatible with %s::$%s::get(): %s',
                $childClass,
                $propDisplay,
                $childRet,
                $parentClass,
                $propDisplay,
                $parentRet
            );
        }
        $childSet = $childHooks['set'] ?? null;
        $parentSet = $parentHooks['set'] ?? null;
        if (null === $childSet || null === $parentSet) {
            return null;
        }
        $childParamType = $childSet['type'] ?? $childType;
        $parentParamType = $parentSet['type'] ?? $parentType;
        $childParam = (null !== $childParamType ? $childParamType->format($childClass).' ' : '')
            .'$'.$childSet['name'];
        $parentParam = (null !== $parentParamType ? $parentParamType->format($parentClass).' ' : '')
            .'$'.$parentSet['name'];

        return sprintf(
            'Declaration of %s::$%s::set(%s): void must be compatible with %s::$%s::set(%s): void',
            $childClass,
            $propDisplay,
            $childParam,
            $parentClass,
            $propDisplay,
            $parentParam
        );
    }

    /**
     * @return array{prop: array{display: string, type: ?TypeSig, private: bool, static: bool, file: string, line: int}, ownerLc: string, ownerDisplay: string}|null
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
                return [
                    'prop' => $type['properties'][$propLc],
                    'ownerLc' => $current,
                    'ownerDisplay' => $type['display'],
                ];
            }
            $current = $type['extends'] ?? null;
        }

        return null;
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
}
