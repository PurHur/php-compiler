<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassFinal;

/**
 * Compile-time checks for PHP 8.4 final properties (#22241, #22308)
 * and final property hooks (#22474).
 *
 * php-src: Zend/zend_compile.c — final property / hook flags / pre-8.4 reject;
 * Zend/zend_inheritance.c — "Cannot override final property %s::$%s",
 * "Cannot override final property hook %s::$%s::%s()".
 *
 * Override rejects throw {@see CompileFatal} so CLI emits Zend-shaped
 * "Fatal error: … in file on line N" (#25457), not bare parseAndCompile failure.
 */
final class FinalPropertyOverrideCheck
{
    /**
     * @var array<string, array{
     *     display: string,
     *     extends: ?string,
     *     properties: array<string, array{final: bool, fromFlags: bool, display: string, file: string, line: int}>,
     *     traitUses: list<string>,
     *     isTrait: bool
     * }>
     */
    private array $classes = [];

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    public static function validate(Script $script, array $propertyHookRegistry = []): void
    {
        $check = new self($propertyHookRegistry);
        $check->collect($script);
        $check->verifyUnsupportedFinals();
        $check->verifyOverrides();
        $check->verifyHookOverrides();
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $propertyHookRegistry
     */
    private function __construct(
        private array $propertyHookRegistry = [],
    ) {
    }

    private function collect(Script $script): void
    {
        // Class decls after ternaries/branches land in successor blocks, not only
        // main->cfg->children (#24770). Walk the full CFG like other compile checks.
        if (null === $script->main->cfg) {
            return;
        }
        $seen = new \SplObjectStorage();
        $queue = [$script->main->cfg];
        while ([] !== $queue) {
            /** @var CfgBlock $current */
            $current = array_shift($queue);
            if ($seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->children as $child) {
                if ($child instanceof Op\Stmt\Class_) {
                    $this->collectClass($child);
                } elseif ($child instanceof Op\Stmt\Trait_) {
                    $this->collectTrait($child);
                }
                OpSubBlockAccess::enqueueSubBlocks($child, $queue);
            }
        }
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'extends' => $parentLc,
            'properties' => $this->collectProperties($class, $lc),
            'traitUses' => $this->collectTraitUses($class->stmts->children),
            'isTrait' => false,
        ];
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($trait->name, $lc),
            'extends' => null,
            'properties' => $this->collectPropertiesFromMembers($trait->stmts->children, $lc),
            'traitUses' => $this->collectTraitUses($trait->stmts->children),
            'isTrait' => true,
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return list<string>
     */
    private function collectTraitUses(array $members): array
    {
        $traits = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->operandLcName($traitOperand);
                if (null !== $traitLc) {
                    $traits[] = $traitLc;
                }
            }
        }

        return $traits;
    }

    /**
     * Instance + static properties (PHP 8.4 allows final static; pre-8.4 rejects all finals, #23403).
     *
     * @return array<string, array{final: bool, fromFlags: bool, display: string, file: string, line: int}>
     */
    private function collectProperties(Op\Stmt\Class_ $class, string $classLc): array
    {
        return $this->collectPropertiesFromMembers($class->stmts->children, $classLc);
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, array{final: bool, fromFlags: bool, display: string, file: string, line: int}>
     */
    private function collectPropertiesFromMembers(array $members, string $classLc): array
    {
        $properties = [];
        foreach ($members as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $propDisplay = $this->propertyDisplayName($member->name);
                $propLc = strtolower($propDisplay);
                $fromFlags = $this->isFinalFromFlags($member);
                $fromPrivateSet = !$member->static && $this->isImplicitlyFinalFromPrivateSet($member);
                $fromRegistry = !$member->static && $this->isFinalFromHookRegistry($classLc, $propDisplay);
                $file = $member->getFile();
                $properties[$propLc] = [
                    'final' => $fromFlags || $fromPrivateSet || $fromRegistry,
                    'fromFlags' => $fromFlags,
                    'display' => $propDisplay,
                    'file' => '' !== $file ? $file : 'unknown',
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
                $fromMarker = \PHPCompiler\Ast\FinalPromotedPropertyRewriter::isFinalFromAttributes(
                    $param->getAttributes()
                );
                $fromField = property_exists($param, 'promotionFinal') && $param->promotionFinal;
                $fromPrivateSet = $this->isImplicitlyFinalFromPrivateSetParam($param);
                $isFinal = $fromMarker || $fromField || $fromPrivateSet;
                // Always record promoted props so a non-final child redeclaration can
                // trip "Cannot override final property" against a final parent (#22451).
                $file = $param->getFile();
                $properties[$propLc] = [
                    'final' => $isFinal,
                    'fromFlags' => $fromMarker || $fromField,
                    'display' => $propDisplay,
                    'file' => '' !== $file ? $file : 'unknown',
                    'line' => max(1, $param->getLine()),
                ];
            }
        }

        return $properties;
    }

    private function isFinalFromFlags(Op\Stmt\Property $member): bool
    {
        if (property_exists($member, 'propertyFlags')
            && ClassFinal::fromClassFlags((int) $member->propertyFlags)) {
            return true;
        }

        return ClassFinal::fromClassFlags((int) $member->visibility);
    }

    /** php-src zend_API.c — private(set) ⇒ ZEND_ACC_FINAL (#23068). */
    private function isImplicitlyFinalFromPrivateSet(Op\Stmt\Property $member): bool
    {
        $setVis = 0;
        if (property_exists($member, 'setVisibility')) {
            $setVis = (int) $member->setVisibility;
        }
        if (0 === $setVis) {
            $setVis = \PHPCompiler\Ast\AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes(
                $member->getAttributes()
            );
        }

        return \PHPCompiler\PropertyVisibility::isImplicitlyFinalFromPrivateSet($setVis);
    }

    /** @param Op\Expr\Param $param */
    private function isImplicitlyFinalFromPrivateSetParam(Op\Expr\Param $param): bool
    {
        $setVis = 0;
        if (property_exists($param, 'setVisibility')) {
            $setVis = (int) $param->setVisibility;
        }
        if (0 === $setVis && property_exists($param, 'promotionSetVisibility')) {
            $setVis = (int) $param->promotionSetVisibility;
        }
        if (0 === $setVis) {
            $setVis = \PHPCompiler\Ast\AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes(
                $param->getAttributes()
            );
        }

        return \PHPCompiler\PropertyVisibility::isImplicitlyFinalFromPrivateSet($setVis);
    }

    private function isFinalFromHookRegistry(string $classLc, string $propDisplay): bool
    {
        $hooks = $this->propertyHookRegistry[$classLc][$propDisplay]
            ?? $this->propertyHookRegistry[$classLc][strtolower($propDisplay)]
            ?? null;

        return is_array($hooks) && !empty($hooks['finalProperty']);
    }

    private function verifyUnsupportedFinals(): void
    {
        // Hooked finals (#16799) use the property-hook registry and are gated by
        // supportsPropertyHooks(); only plain MODIFIER_FINAL needs supportsFinalProperties().
        if (CompilerVersion::supportsFinalProperties()) {
            return;
        }
        foreach ($this->classes as $class) {
            foreach ($class['properties'] as $prop) {
                if (!empty($prop['fromFlags'])) {
                    // Zend 8.2 Fatal (zend_compile.c) — #24895 / #22308.
                    throw new CompileFatal(
                        $prop['file'] ?? 'unknown',
                        max(1, (int) ($prop['line'] ?? 1)),
                        sprintf(
                            'Cannot declare property %s::$%s final, the final modifier is allowed only for methods, classes, and class constants',
                            $class['display'],
                            $prop['display']
                        )
                    );
                }
            }
        }
    }

    private function verifyOverrides(): void
    {
        if (!CompilerVersion::supportsFinalProperties()
            && !CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($class['properties'] as $propLc => $childProp) {
                $parentProp = $this->findInheritedProperty($parentLc, $propLc);
                if (null === $parentProp || !$parentProp['final']) {
                    continue;
                }
                // Plain final override requires 8.4 final-property support; hooked finals
                // already enforce via hooks path when property hooks are enabled.
                if ($parentProp['fromFlags'] && !CompilerVersion::supportsFinalProperties()) {
                    continue;
                }
                if (!$parentProp['fromFlags'] && !CompilerVersion::supportsPropertyHooks()) {
                    continue;
                }
                // CompileFatal → Zend-shaped "Fatal error: … in file on line N" (#25457).
                throw new CompileFatal(
                    $childProp['file'],
                    max(1, (int) $childProp['line']),
                    sprintf(
                        'Cannot override final property %s::$%s',
                        $parentProp['ownerDisplay'],
                        $parentProp['display']
                    )
                );
            }
        }
    }

    /**
     * @return array{final: bool, fromFlags: bool, display: string, ownerDisplay: string}|null
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
            if (null === $type) {
                break;
            }
            if (isset($type['properties'][$propLc])) {
                $prop = $type['properties'][$propLc];

                return [
                    'final' => $prop['final'],
                    'fromFlags' => $prop['fromFlags'],
                    'display' => $prop['display'],
                    'ownerDisplay' => $type['display'],
                ];
            }
            // Trait-imported properties belong to the composing class for inheritance
            // fatals (zend_traits.c / zend_inheritance.c — "Cannot override … A::$x") (#27818).
            $fromTrait = $this->findPropertyFromTraitUses($type['traitUses'] ?? [], $propLc, $type['display'], $visited);
            if (null !== $fromTrait) {
                return $fromTrait;
            }
            $current = $type['extends'] ?? null;
        }

        return null;
    }

    /**
     * @param list<string> $traitUses
     * @param array<string, true> $visited
     *
     * @return array{final: bool, fromFlags: bool, display: string, ownerDisplay: string}|null
     */
    private function findPropertyFromTraitUses(
        array $traitUses,
        string $propLc,
        string $ownerDisplay,
        array &$visited
    ): ?array {
        $queue = $traitUses;
        $traitGuard = 0;
        while ([] !== $queue) {
            if (++$traitGuard > 256) {
                break;
            }
            $traitLc = array_shift($queue);
            if (!is_string($traitLc) || '' === $traitLc || isset($visited['trait:'.$traitLc])) {
                continue;
            }
            $visited['trait:'.$traitLc] = true;
            $trait = $this->classes[$traitLc] ?? null;
            if (null === $trait) {
                continue;
            }
            if (isset($trait['properties'][$propLc])) {
                $prop = $trait['properties'][$propLc];

                return [
                    'final' => $prop['final'],
                    'fromFlags' => $prop['fromFlags'],
                    'display' => $prop['display'],
                    'ownerDisplay' => $ownerDisplay,
                ];
            }
            foreach ($trait['traitUses'] ?? [] as $nested) {
                if (is_string($nested) && '' !== $nested) {
                    $queue[] = $nested;
                }
            }
        }

        return null;
    }

    /**
     * Per-hook finality: child must not redeclare a parent `final get` / `final set` / `final unset`
     * (#22474, Zend/zend_inheritance.c — Cannot override final property hook).
     */
    private function verifyHookOverrides(): void
    {
        if (!CompilerVersion::supportsPropertyHooks()) {
            return;
        }
        foreach ($this->classes as $childLc => $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc || '' === $parentLc) {
                continue;
            }
            foreach ($this->hooksDeclaredOnClass($childLc) as $propLc => $childDecl) {
                $parentHook = $this->findInheritedFinalHooks($parentLc, $propLc);
                if (null === $parentHook) {
                    continue;
                }
                foreach (['get', 'set', 'unset'] as $kind) {
                    if (!$this->hookIsFinal($parentHook['hooks'], $kind)) {
                        continue;
                    }
                    if (!$this->childDeclaresHook($childDecl['hooks'], $kind)) {
                        continue;
                    }
                    $childPropMeta = $class['properties'][$propLc] ?? null;
                    $file = is_array($childPropMeta) ? ($childPropMeta['file'] ?? 'unknown') : 'unknown';
                    $line = is_array($childPropMeta) ? max(1, (int) ($childPropMeta['line'] ?? 1)) : 1;
                    // CompileFatal → Zend-shaped Fatal error (#25457, same as plain override).
                    throw new CompileFatal(
                        $file,
                        $line,
                        sprintf(
                            'Cannot override final property hook %s::$%s::%s()',
                            $parentHook['ownerDisplay'],
                            $parentHook['display'],
                            $kind
                        )
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array{hooks: array<string, mixed>, display: string}> propLc => meta
     */
    private function hooksDeclaredOnClass(string $classLc): array
    {
        $byClass = $this->propertyHookRegistry[$classLc] ?? null;
        if (!is_array($byClass)) {
            return [];
        }
        $out = [];
        foreach ($byClass as $propKey => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            $display = (string) $propKey;
            $out[strtolower($display)] = [
                'hooks' => $hooks,
                'display' => $display,
            ];
        }

        return $out;
    }

    /**
     * @return array{hooks: array<string, mixed>, display: string, ownerDisplay: string}|null
     */
    private function findInheritedFinalHooks(string $startParentLc, string $propLc): ?array
    {
        $current = $startParentLc;
        $visited = [];
        $guard = 0;
        while (null !== $current && '' !== $current && !isset($visited[$current])) {
            if (++$guard > 256) {
                break;
            }
            $visited[$current] = true;
            $found = $this->lookupHooksWithDisplay($current, $propLc);
            if (null !== $found) {
                $type = $this->classes[$current] ?? null;
                if ($this->hooksHaveAnyFinal($found['hooks'])) {
                    return [
                        'hooks' => $found['hooks'],
                        'display' => $found['display'],
                        'ownerDisplay' => $type['display'] ?? $current,
                    ];
                }
                // Parent declared hooks but none final — stop (child overrides non-final).
                return null;
            }
            $type = $this->classes[$current] ?? null;
            $current = $type['extends'] ?? null;
        }

        return null;
    }

    /**
     * @return array{hooks: array<string, mixed>, display: string}|null
     */
    private function lookupHooksWithDisplay(string $classLc, string $propLc): ?array
    {
        $byClass = $this->propertyHookRegistry[$classLc] ?? null;
        if (!is_array($byClass)) {
            return null;
        }
        if (isset($byClass[$propLc]) && is_array($byClass[$propLc])) {
            return ['hooks' => $byClass[$propLc], 'display' => $propLc];
        }
        foreach ($byClass as $propKey => $hooks) {
            if (is_array($hooks) && 0 === strcasecmp((string) $propKey, $propLc)) {
                return ['hooks' => $hooks, 'display' => (string) $propKey];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $hooks
     */
    private function hooksHaveAnyFinal(array $hooks): bool
    {
        return !empty($hooks['finalGet'])
            || !empty($hooks['finalSet'])
            || !empty($hooks['finalUnset']);
    }

    /**
     * @param array<string, mixed> $hooks
     * @param 'get'|'set'|'unset' $kind
     */
    private function hookIsFinal(array $hooks, string $kind): bool
    {
        return !empty($hooks['final'.ucfirst($kind)]);
    }

    /**
     * @param array<string, mixed> $hooks
     * @param 'get'|'set'|'unset' $kind
     */
    private function childDeclaresHook(array $hooks, string $kind): bool
    {
        if (isset($hooks[$kind]) && (is_string($hooks[$kind]) || true === $hooks[$kind])) {
            return true;
        }
        $requires = 'requires'.ucfirst($kind);

        return !empty($hooks[$requires]);
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
