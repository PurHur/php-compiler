<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\TraitCompositionConflictMessage;

/**
 * Trait composition / deferred trait+parent flush for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resolveTraitEntry} through
 * {@code flushPendingTraitUses} (php-src Zend/zend_traits.c composition +
 * Zend/zend_inheritance.c trait property merge). Concern trait — same namespace
 * as parent so relative Frame / VM helpers resolve. Move-only; no new C ABI.
 */
trait ClassTraitComposition
{
    protected function resolveTraitEntry(string $traitName): ClassEntry
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        if (!isset($this->context->classes[$traitLc])) {
            $this->context->autoloadClass($traitName);
        }
        if (!isset($this->context->classes[$traitLc])) {
            $this->throwTraitNotFoundFatal($traitName);
        }
        $trait = $this->context->classes[$traitLc];
        if (!$trait->isTrait) {
            throw new \LogicException("{$traitName} is not a trait");
        }

        return $trait;
    }

    /**
     * Methods that block trait import — composing-class own methods only.
     *
     * Zend precedence (zend_traits.c): trait methods override inherited parent
     * methods of the same name; only methods declared on the using class win
     * over the trait (#19630, re-#18878). Parent methods are merged later via
     * inheritFromParent() and skip slots already filled by the trait.
     *
     * @param array<string, true> $ownMethods
     *
     * @return array<string, true>
     */
    protected function traitMethodExclusions(ClassEntry $entry, array $ownMethods): array
    {
        return $ownMethods;
    }

    protected function applyTraitUse(ClassEntry $entry, string $traitName, array $ownMethods = [], ?Frame $warningFrame = null): void
    {
        $this->applyTraitUsesWithAdaptations($entry, [$traitName], [], $ownMethods, $warningFrame);
    }

    /**
     * @param list<string> $traitNames
     */
    protected function canResolveAllTraitEntries(array $traitNames): bool
    {
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (!isset($this->context->classes[$traitLc])) {
                $this->context->autoloadClass($traitName);
            }
            if (!isset($this->context->classes[$traitLc])) {
                return false;
            }
            if (!$this->context->classes[$traitLc]->isTrait) {
                throw new \LogicException("{$traitName} is not a trait");
            }
        }

        return true;
    }

    /**
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     */
    protected function queueDeferredTraitUse(
        ClassEntry $entry,
        array $traitNames,
        array $adaptations,
        array $ownMethods,
        ?Frame $warningFrame = null
    ): void {
        $this->context->deferredTraitUses[] = [
            'entry' => $entry,
            'traitNames' => $traitNames,
            'adaptations' => $adaptations,
            'ownMethods' => $ownMethods,
            'warningFrame' => $warningFrame,
        ];
    }

    protected function flushDeferredTraitUses(?Frame $warningFrame = null): void
    {
        if ([] === $this->context->deferredTraitUses) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredTraitUses as $deferred) {
            if (!$this->canResolveAllTraitEntries($deferred['traitNames'])) {
                $remaining[] = $deferred;

                continue;
            }
            $this->applyTraitUsesWithAdaptations(
                $deferred['entry'],
                $deferred['traitNames'],
                $deferred['adaptations'],
                $deferred['ownMethods'],
                $deferred['warningFrame'] ?? $warningFrame
            );
        }
        $this->context->deferredTraitUses = $remaining;
    }

    protected function flushDeferredParentInheritance(?Frame $frame = null): void
    {
        if ([] === $this->context->deferredParentInheritance) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredParentInheritance as $deferred) {
            $childLc = $deferred['childLc'];
            if (!isset($this->context->classes[$childLc])) {
                $remaining[] = $deferred;

                continue;
            }
            $entry = $this->context->classes[$childLc];
            if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
                $remaining[] = $deferred;

                continue;
            }
            $this->assertAllowedBySealedParents($entry->name, $entry->parentLc, $entry->interfaces);
            $this->inheritFromParent($entry);
            $this->linkStaticPropertyHooks($entry);
            VM\ClassValidator::finalizeClassDefinition($entry, $this->context, $frame);
        }
        $this->context->deferredParentInheritance = $remaining;
    }

    protected function finalizeDeferredParentInheritance(?Frame $frame = null): void
    {
        $this->flushDeferredParentInheritance($frame);
        if ([] === $this->context->deferredParentInheritance) {
            return;
        }
        $deferred = $this->context->deferredParentInheritance[0];
        $parentName = $deferred['parentName'];
        // Zend does not leave the child class defined when the parent is missing (#25627).
        // Do not re-invoke autoload here: TYPE_DECLARE_CLASS already tried, and a nested
        // autoload frame would re-enter finalize at SUCCESS and recurse (#25627).
        unset($this->context->classes[$deferred['childLc']]);
        $this->context->deferredParentInheritance = [];
        throw new \Error($this->classNotFoundMessage($parentName));
    }

    protected function finalizeDeferredTraitUses(): void
    {
        $this->flushDeferredTraitUses();
        if ([] === $this->context->deferredTraitUses) {
            return;
        }
        $deferred = $this->context->deferredTraitUses[0];
        $missing = $deferred['traitNames'][0] ?? 'unknown';
        /** @var ClassEntry|null $entry */
        $entry = $deferred['entry'] ?? null;
        /** @var Frame|null $warningFrame */
        $warningFrame = $deferred['warningFrame'] ?? null;
        $this->throwTraitNotFoundFatal($missing, $entry, $warningFrame);
    }

    /**
     * Zend compile/runtime fatal for an unresolved {@code use Trait;} (#30012, zend_compile.c).
     *
     * @return never
     */
    protected function throwTraitNotFoundFatal(
        string $traitName,
        ?ClassEntry $entry = null,
        ?Frame $frame = null,
    ): void {
        $location = $entry?->sourceLocation;
        $file = $location?->filename ?? '';
        if ('' === $file && null !== $frame && '' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $line = $location?->startLine ?? 1;
        TraitCompositionConflictMessage::throwRuntimeFatal(
            TraitCompositionConflictMessage::notFound($traitName),
            $file,
            $line,
        );
    }

    /**
     * Precedence/alias trait must be a direct {@code use} on the composing class
     * (Zend/zend_inheritance.c zend_check_trait_usage, #32129 / #32130).
     *
     * @param array<string, string> $usedTraitNameByLc
     */
    protected function throwIfAdaptationTraitNotDirectlyUsed(
        string $referencedName,
        ClassEntry $entry,
        array $usedTraitNameByLc,
        bool $unknownIsCouldNotFind = true,
    ): void {
        $lc = strtolower(ltrim($referencedName, '\\'));
        if (isset($usedTraitNameByLc[$lc])) {
            return;
        }
        $found = $this->context->classes[$lc] ?? null;
        $existsAsTrait = null !== $found && $found->isTrait;
        $existsAsNonTrait = null !== $found && !$found->isTrait;
        if ($existsAsNonTrait) {
            TraitCompositionConflictMessage::throwUnresolvedAdaptationTrait(
                $referencedName,
                $entry->name,
                false,
                null,
                true,
                $found->name,
            );
        }
        if (!$existsAsTrait && !$unknownIsCouldNotFind) {
            return;
        }
        TraitCompositionConflictMessage::throwUnresolvedAdaptationTrait(
            $referencedName,
            $entry->name,
            $existsAsTrait,
            $existsAsTrait ? $found->name : null,
        );
    }

    protected function flushDeferredClassConstants(): void
    {
        if ([] === $this->context->deferredClassConstants) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredClassConstants as $deferred) {
            $stillPending = $this->finalizeDeferredClassConstants(
                $deferred['entry'],
                $deferred['block'],
                $deferred['frame'],
                $deferred['classBodyOps'],
                $deferred['segments']
            );
            if ([] !== $stillPending) {
                $deferred['segments'] = $stillPending;
                $remaining[] = $deferred;
                $deferred['entry']->pendingConstMaterialization = [
                    'block' => $deferred['block'],
                    'frame' => $deferred['frame'],
                    'classBodyOps' => $deferred['classBodyOps'],
                    'segments' => $stillPending,
                ];
            } else {
                $deferred['entry']->pendingConstMaterialization = null;
            }
        }
        $this->context->deferredClassConstants = $remaining;
    }

    protected function finalizeAllDeferredClassConstants(): void
    {
        $this->flushDeferredClassConstants();
        if ([] === $this->context->deferredClassConstants) {
            return;
        }
        $first = $this->context->deferredClassConstants[0];
        $pendingName = array_key_first($first['segments']);
        if (false === $pendingName) {
            return;
        }
        $declareOp = $first['classBodyOps'][$first['segments'][$pendingName]['declareIndex']];
        $canonical = $first['frame']->scope[$declareOp->arg1]->toString();
        throw new \LogicException(
            "Cannot resolve class constant {$first['entry']->name}::{$canonical}"
        );
    }

    private function assertDeferredDefinitionsBeforeRuntime(int $opType): void
    {
        static $declarationOpcodes = [
            OpCode::TYPE_DECLARE_CLASS => true,
            OpCode::TYPE_DECLARE_ENUM => true,
            OpCode::TYPE_DECLARE_TRAIT => true,
            OpCode::TYPE_DECLARE_INTERFACE => true,
            OpCode::TYPE_FUNCDEF => true,
            OpCode::TYPE_DECLARE_GLOBAL_CONST => true,
        ];
        if (isset($declarationOpcodes[$opType])) {
            return;
        }
        $ctx = $this->context;
        if ([] === $ctx->deferredTraitUses
            && [] === $ctx->deferredClassConstants
            && [] === $ctx->deferredParentInheritance) {
            return;
        }
        $this->finalizeDeferredTraitUses();
        // Forward-ref class constants (e.g. C::ITEM = E::A before enum E) may stay
        // pending until a later declaration opcode flushes them (#9664, #15737).
        $this->flushDeferredClassConstants();
        $this->finalizeDeferredParentInheritance();
    }

    /**
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     */
    protected function applyTraitUsesWithAdaptations(
        ClassEntry $entry,
        array $traitNames,
        array $adaptations,
        array $ownMethods = [],
        ?Frame $warningFrame = null
    ): void {
        if ([] === $traitNames) {
            return;
        }

        if (!$this->canResolveAllTraitEntries($traitNames)) {
            $this->queueDeferredTraitUse($entry, $traitNames, $adaptations, $ownMethods, $warningFrame);

            return;
        }

        $dedupedTraitNames = [];
        $seenTraitLc = [];
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (isset($seenTraitLc[$traitLc])) {
                continue;
            }
            $seenTraitLc[$traitLc] = true;
            $dedupedTraitNames[] = $traitName;
        }
        $traitNames = $dedupedTraitNames;
        if ([] === $traitNames) {
            return;
        }

        $excludedMethods = $this->traitMethodExclusions($entry, $ownMethods);

        /** @var array<string, array<string, array{method: Func, vis: int, traitName: string, methodNames: string, attrs: ?list<string>, deprecated: mixed, attributeEntries: mixed, parameterMetadata: mixed}>> */
        $perTraitMethods = [];
        /** @var array<string, true> */
        $excludedByPrecedence = [];
        /** @var array<string, string> */
        $usedTraitNameByLc = [];

        foreach ($traitNames as $traitName) {
            $trait = $this->resolveTraitEntry($traitName);
            $traitLc = strtolower(ltrim($trait->name, '\\'));
            if (VM\LazyGhostTraitSupport::isLazyGhostTrait($traitLc)) {
                $entry->usesLazyGhostTrait = true;
            }
            $this->emitTraitUseDeprecation($trait, $entry, $warningFrame);
            $entry->usedTraits[$trait->name] = $trait->name;
            $usedTraitNameByLc[$traitLc] = $trait->name;
            if (!isset($perTraitMethods[$traitLc])) {
                $perTraitMethods[$traitLc] = [];
            }
            foreach ($trait->methods as $name => $method) {
                $perTraitMethods[$traitLc][$name] = [
                    'method' => $method,
                    'vis' => $trait->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC,
                    'traitName' => $trait->name,
                    'methodNames' => $trait->methodNames[$name] ?? $name,
                    'attrs' => $trait->methodAttributeNames[$name] ?? null,
                    'deprecated' => $trait->methodDeprecated[$name] ?? null,
                    'attributeEntries' => $trait->methodAttributeEntries[$name] ?? null,
                    'parameterMetadata' => $trait->methodParameterMetadata[$name] ?? null,
                    'sourceLocation' => $trait->methodSourceLocations[$name] ?? null,
                ];
            }
            foreach ($trait->abstractMethods as $name => $_) {
                if (!isset($entry->methods[$name]) && !isset($entry->abstractMethods[$name])) {
                    $entry->abstractMethods[$name] = true;
                }
            }
            foreach ($trait->staticProperties as $name => $storage) {
                if (isset($entry->staticProperties[$name])) {
                    $declaringLc = $entry->staticPropertyDeclaringClassLc[$name] ?? null;
                    if ($declaringLc === $traitLc) {
                        continue;
                    }
                    $existing = $entry->staticProperties[$name];
                    if ($this->traitStaticPropertiesCompatible($entry, $name, $existing, $trait, $storage)) {
                        // Zend: identical definitions merge; keep the earlier property (#22850).
                        continue;
                    }
                    $prevTrait = $usedTraitNameByLc[$declaringLc]
                        ?? $this->context->classes[$declaringLc]->name
                        ?? $declaringLc;
                    $this->throwTraitPropertyCompositionFatal(
                        TraitCompositionConflictMessage::incompatibleProperty(
                            $prevTrait,
                            $trait->name,
                            $name,
                            $entry->name
                        ),
                        $entry
                    );
                }
                $entry->staticProperties[$name] = $this->cloneStaticPropertyStorage($storage);
                $this->linkStaticTypedPropertySlot(
                    $entry->staticProperties[$name],
                    $entry,
                    $storage->objectPropertyName ?? $name
                );
                $entry->traitStaticPropertyNames[$name] = true;
                $entry->staticPropertyVisibility[$name] = $trait->staticPropertyVisibility[$name]
                    ?? \PHPCfg\Func::FLAG_PUBLIC;
                $entry->staticPropertySetVisibility[$name] = $trait->staticPropertySetVisibility[$name] ?? 0;
                $entry->staticPropertyGetVisibility[$name] = $trait->staticPropertyGetVisibility[$name] ?? 0;
                if (isset($trait->staticPropertyAsymmetricExplicitRead[$name])) {
                    $entry->staticPropertyAsymmetricExplicitRead[$name] = $trait->staticPropertyAsymmetricExplicitRead[$name];
                }
                $entry->staticPropertyDeclaringClassLc[$name] = $trait->staticPropertyDeclaringClassLc[$name]
                    ?? $traitLc;
                if (isset($trait->staticPropertyFinal[$name])) {
                    $entry->staticPropertyFinal[$name] = true;
                }
            }
            $this->inheritTraitStaticPropertyHooks($entry, $trait);
            $this->inheritTraitInstanceProperties($entry, $trait, $trait->name);
            foreach ($trait->constants as $name => $value) {
                if (isset($entry->constants[$name])) {
                    if ($this->classConstValuesIdentical($entry->constants[$name], $value)) {
                        continue;
                    }
                    $prevTrait = $entry->traitConstSources[$name] ?? $entry->name;
                    $constDisplay = $entry->constNames[$name]
                        ?? $trait->constNames[$name]
                        ?? $name;
                    throw new \LogicException(sprintf(
                        '%s and %s define the same constant (%s) in the composition of %s. '
                        .'However, the definition differs and is considered incompatible. Class was composed',
                        $prevTrait,
                        $trait->name,
                        $constDisplay,
                        $entry->name
                    ));
                }
                $entry->constants[$name] = $value;
                $entry->traitConstSources[$name] = $trait->name;
                if (isset($trait->constNames[$name])) {
                    $entry->constNames[$name] = $trait->constNames[$name];
                }
                $entry->constDeclaringClassLc[$name] = $trait->constDeclaringClassLc[$name]
                    ?? strtolower(ltrim($trait->name, '\\'));
                if (isset($trait->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $trait->constVisibility[$name];
                }
                if (isset($trait->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $trait->constDeprecated[$name];
                }
                if (isset($trait->constFinal[$name])) {
                    $entry->constFinal[$name] = true;
                }
                if (isset($trait->constDeclaredTypes[$name])) {
                    $entry->constDeclaredTypes[$name] = $trait->constDeclaredTypes[$name];
                }
                if (isset($trait->constSourceLocations[$name])) {
                    $entry->constSourceLocations[$name] = $trait->constSourceLocations[$name];
                }
            }
        }

        foreach ($adaptations as $adaptation) {
            if ('precedence' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $winnerTraitLc = strtolower(ltrim((string) ($adaptation['trait'] ?? ''), '\\'));
            if ('' === $winnerTraitLc) {
                throw new \LogicException('Trait precedence adaptation must specify a trait');
            }
            $this->throwIfAdaptationTraitNotDirectlyUsed(
                (string) ($adaptation['trait'] ?? ''),
                $entry,
                $usedTraitNameByLc,
            );
            $methodLc = strtolower((string) $adaptation['method']);
            if (!isset($perTraitMethods[$winnerTraitLc][$methodLc])) {
                throw new \LogicException(
                    'A precedence rule was defined for '
                    . $usedTraitNameByLc[$winnerTraitLc]
                    . '::' . (string) ($adaptation['method'] ?? '')
                    . ' but this method does not exist'
                );
            }
            foreach ($adaptation['insteadof'] as $loserTrait) {
                $loserLc = strtolower(ltrim((string) $loserTrait, '\\'));
                $this->throwIfAdaptationTraitNotDirectlyUsed(
                    (string) $loserTrait,
                    $entry,
                    $usedTraitNameByLc,
                );
                if (!isset($perTraitMethods[$loserLc][$methodLc])) {
                    throw new \LogicException(
                        'A precedence rule was defined for '
                        . $usedTraitNameByLc[$winnerTraitLc]
                        . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist in '
                        . $usedTraitNameByLc[$loserLc]
                    );
                }
                $excludedByPrecedence["{$loserLc}\0{$methodLc}"] = true;
            }
        }

        /** @var array<string, array{traitLc: string, method: Func, vis: int, traitName: string, methodNames: string, attrs: ?list<string>, deprecated: mixed, attributeEntries: mixed, parameterMetadata: mixed}> */
        $merged = [];
        foreach ($perTraitMethods as $traitLc => $methods) {
            foreach ($methods as $methodLc => $data) {
                if (isset($excludedByPrecedence["{$traitLc}\0{$methodLc}"])) {
                    continue;
                }
                if (isset($excludedMethods[$methodLc])) {
                    continue;
                }
                if (isset($merged[$methodLc])) {
                    if ($merged[$methodLc]['traitLc'] === $traitLc) {
                        continue;
                    }
                    $prevTrait = $merged[$methodLc]['traitName'];
                    throw new \LogicException(
                        "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$entry->name}::{$methodLc}, "
                        ."because of collision with {$prevTrait}::{$methodLc}"
                    );
                }
                $merged[$methodLc] = [
                    'traitLc' => $traitLc,
                    'method' => $data['method'],
                    'vis' => $data['vis'],
                    'traitName' => $data['traitName'],
                    'methodNames' => $data['methodNames'],
                    'attrs' => $data['attrs'],
                    'deprecated' => $data['deprecated'],
                    'attributeEntries' => $data['attributeEntries'],
                    'parameterMetadata' => $data['parameterMetadata'],
                    'sourceLocation' => $data['sourceLocation'] ?? null,
                ];
            }
        }

        foreach ($adaptations as $adaptation) {
            if ('alias' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $methodLc = strtolower((string) $adaptation['method']);
            $traitLcFilter = null !== ($adaptation['trait'] ?? null)
                ? strtolower(ltrim((string) $adaptation['trait'], '\\'))
                : null;
            if (null !== $traitLcFilter) {
                // Existing unused trait: Zend required-not-added before alias-method checks (#32130).
                // Declared class: Zend "not a trait" (#32129).
                $this->throwIfAdaptationTraitNotDirectlyUsed(
                    (string) $adaptation['trait'],
                    $entry,
                    $usedTraitNameByLc,
                    false,
                );
            }
            $newName = $adaptation['newName'] ?? null;
            $newModifier = $adaptation['newModifier'] ?? null;
            if (null === $newName && null === $newModifier) {
                continue;
            }

            $traitPrefix = null !== ($adaptation['trait'] ?? null)
                ? (string) $adaptation['trait'] . '::'
                : '';

            if (null === $newName) {
                if (!isset($merged[$methodLc])) {
                    // Own class methods are excluded from $merged before adaptations so they
                    // can suppress trait collisions (Zend). Visibility-only `g as private`
                    // must still succeed when the class also declares g() — the trait method
                    // exists in $perTraitMethods; class method wins on install (#25577).
                    $existsInTraits = false;
                    if (null !== $traitLcFilter) {
                        $existsInTraits = isset($perTraitMethods[$traitLcFilter][$methodLc]);
                    } else {
                        foreach ($perTraitMethods as $methods) {
                            if (isset($methods[$methodLc])) {
                                $existsInTraits = true;
                                break;
                            }
                        }
                    }
                    if (isset($excludedMethods[$methodLc]) && $existsInTraits) {
                        continue;
                    }
                    throw new \LogicException(
                        'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $traitLcFilter && $merged[$methodLc]['traitLc'] !== $traitLcFilter) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $newModifier) {
                    $merged[$methodLc]['vis'] = (int) $newModifier;
                }

                continue;
            }

            $newNameLc = strtolower((string) $newName);

            if (null !== $traitLcFilter) {
                if (!isset($usedTraitNameByLc[$traitLcFilter]) || !isset($perTraitMethods[$traitLcFilter][$methodLc])) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                $orig = $perTraitMethods[$traitLcFilter][$methodLc];
                $data = [
                    'traitLc' => $traitLcFilter,
                    'method' => $orig['method'],
                    'vis' => $orig['vis'],
                    'traitName' => $orig['traitName'],
                    'methodNames' => $orig['methodNames'],
                    'attrs' => $orig['attrs'],
                    'deprecated' => $orig['deprecated'],
                    'attributeEntries' => $orig['attributeEntries'],
                    'parameterMetadata' => $orig['parameterMetadata'],
                    'sourceLocation' => $orig['sourceLocation'] ?? null,
                ];
            } else {
                if (isset($merged[$methodLc])) {
                    $data = $merged[$methodLc];
                } else {
                    $source = null;
                    foreach ($perTraitMethods as $methods) {
                        if (isset($methods[$methodLc])) {
                            $source = $methods[$methodLc];
                            break;
                        }
                    }
                    if (null === $source) {
                        throw new \LogicException(
                            'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                            . ' but this method does not exist'
                        );
                    }
                    $data = $source;
                }
            }

            // Zend zend_traits.c: alias onto an existing composed name is a trait collision
            // fatal (not "Cannot redefine method") — #25080.
            if (isset($merged[$newNameLc])) {
                $prev = $merged[$newNameLc];
                $aliasName = (string) $newName;
                $sourceMethod = (string) ($adaptation['method'] ?? '');
                throw new \LogicException(
                    "Trait method {$data['traitName']}::{$sourceMethod} has not been applied as {$entry->name}::{$aliasName}, "
                    ."because of collision with {$prev['traitName']}::{$aliasName}"
                );
            }

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['methodNames'] = (string) $newName;
            // Zend zend_traits.c: `as` aliases — original method stays callable (#22718).
            // Trait-qualified `TB::f as g` likewise keeps the merged winner `f`.
            $merged[$newNameLc] = $data;
            $entry->traitAliases[(string) $newName] = $data['traitName'] . '::' . (string) $adaptation['method'];
        }

        foreach ($merged as $methodLc => $data) {
            if (isset($excludedMethods[$methodLc])) {
                continue;
            }
            if (isset($entry->methods[$methodLc]) && !isset($entry->traitMethodSources[$methodLc])) {
                continue;
            }
            if (isset($entry->traitMethodSources[$methodLc])) {
                $prevTrait = $entry->traitMethodSources[$methodLc];
                if ($prevTrait === $data['traitName']) {
                    continue;
                }
                throw new \CompileError(
                    "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$entry->name}::{$methodLc}, "
                    ."because of collision with {$prevTrait}::{$methodLc}"
                );
            }
            $entry->methods[$methodLc] = TraitMethodFunctionStatic::bindMethod(
                $data['method'],
                $entry->name,
                $data['traitName'],
                $data['methodNames']
            );
            $entry->traitMethodSources[$methodLc] = $data['traitName'];
            $entry->methodVisibility[$methodLc] = $data['vis'];
            $entry->methodDeclaringClassLc[$methodLc] = strtolower(ltrim($data['traitName'], '\\'));
            $entry->methodNames[$methodLc] = $data['methodNames'];
            if (null !== ($data['attrs'] ?? null)) {
                $entry->methodAttributeNames[$methodLc] = $data['attrs'];
            }
            if (null !== ($data['deprecated'] ?? null)) {
                $entry->methodDeprecated[$methodLc] = $data['deprecated'];
            }
            if (null !== ($data['attributeEntries'] ?? null)) {
                $entry->methodAttributeEntries[$methodLc] = $data['attributeEntries'];
            }
            if (null !== ($data['parameterMetadata'] ?? null)) {
                $entry->methodParameterMetadata[$methodLc] = $data['parameterMetadata'];
            }
            if (null !== ($data['sourceLocation'] ?? null)) {
                $entry->methodSourceLocations[$methodLc] = $data['sourceLocation'];
            }
            if ('__construct' === $methodLc && null === $entry->constructor) {
                $entry->constructor = $entry->methods[$methodLc];
            }
        }
        $this->linkStaticPropertyHooks($entry);
    }

    /**
     * Merge trait static property-hook metadata into using class (#6624, zend_property_hooks.c + zend_traits.c).
     */
    protected function inheritTraitStaticPropertyHooks(ClassEntry $entry, ClassEntry $trait): void
    {
        $traitLc = strtolower($trait->name);
        $childLc = strtolower($entry->name);
        if (isset($this->context->propertyHookRegistry[$traitLc])) {
            foreach ($this->context->propertyHookRegistry[$traitLc] as $prop => $meta) {
                if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                    $this->context->propertyHookRegistry[$childLc][$prop] = $meta;
                }
            }
        }
        foreach ($trait->staticPropertyHooks as $name => $hooks) {
            if (!isset($entry->staticPropertyHooks[$name])) {
                $entry->staticPropertyHooks[$name] = $hooks;
            }
        }
    }

    /**
     * Zend linkage-time fatal for incompatible trait properties (#17995, zend_inheritance.c).
     *
     * @return never
     */
    protected function throwTraitPropertyCompositionFatal(
        string $message,
        ClassEntry $entry,
        ?SourceLocation $opLocation = null,
        ?Frame $frame = null,
    ): void {
        $location = $opLocation ?? $entry->sourceLocation;
        $file = $location?->filename ?? '';
        if ('' === $file && null !== $frame && '' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $line = $location?->startLine ?? 1;
        TraitCompositionConflictMessage::throwRuntimeFatal($message, $file, $line);
    }

    /**
     * Compare an existing class/trait static slot against a trait static being merged (#22850).
     */
    private function traitStaticPropertiesCompatible(
        ClassEntry $entry,
        string $name,
        Variable $existing,
        ClassEntry $trait,
        Variable $incoming,
    ): bool {
        return $this->traitStaticPropertySlotsCompatible(
            $existing,
            (int) ($entry->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($entry->staticPropertySetVisibility[$name] ?? 0),
            (int) ($entry->staticPropertyGetVisibility[$name] ?? 0),
            !empty($entry->staticPropertyAsymmetricExplicitRead[$name]),
            $incoming,
            (int) ($trait->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($trait->staticPropertySetVisibility[$name] ?? 0),
            (int) ($trait->staticPropertyGetVisibility[$name] ?? 0),
            !empty($trait->staticPropertyAsymmetricExplicitRead[$name]),
        );
    }

    private function traitStaticPropertySlotsCompatible(
        Variable $left,
        int $leftVisibility,
        int $leftSetVisibility,
        int $leftGetVisibility,
        bool $leftAsymmetricExplicitRead,
        Variable $right,
        int $rightVisibility,
        int $rightSetVisibility,
        int $rightGetVisibility,
        bool $rightAsymmetricExplicitRead,
    ): bool {
        return VM\TraitPropertyCompatibility::staticPropertiesCompatible(
            $left,
            $leftVisibility,
            $left,
            $right,
            $rightVisibility,
            $right,
            $leftSetVisibility,
            $rightSetVisibility,
            $leftGetVisibility,
            $rightGetVisibility,
            $leftAsymmetricExplicitRead,
            $rightAsymmetricExplicitRead,
        );
    }

    protected function inheritTraitInstanceProperties(ClassEntry $entry, ClassEntry $trait, string $traitName): void
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        $classLc = strtolower($entry->name);
        foreach ($trait->properties as $property) {
            $propLc = strtolower($property->name);
            foreach ($entry->properties as $existing) {
                if (strtolower($existing->name) === $propLc) {
                    $existingFromTraitLc = isset($entry->traitPropertySources[$propLc])
                        ? strtolower(ltrim($entry->traitPropertySources[$propLc], '\\'))
                        : (
                            // Legacy / trait-using-trait: declaringClassLc may still name the trait.
                            (isset($this->context->classes[$existing->declaringClassLc])
                                && $this->context->classes[$existing->declaringClassLc]->isTrait)
                                ? $existing->declaringClassLc
                                : null
                        );
                    if ($existingFromTraitLc === $traitLc) {
                        continue 2;
                    }
                    if (null === $existingFromTraitLc && $existing->declaringClassLc === $classLc) {
                        // Zend: either side hooked → compose Fatal; class redeclare is not an
                        // implementation of abstract trait hooks (#30009, zend_inheritance.c).
                        $existingOwner = isset($this->context->classes[$existing->declaringClassLc])
                            ? $this->context->classes[$existing->declaringClassLc]
                            : $entry;
                        if (VM\AbstractPropertyHookCheck::propertyHasHooks($existingOwner, $existing, $this->context)
                            || VM\AbstractPropertyHookCheck::propertyHasHooks($trait, $property, $this->context)) {
                            $this->throwTraitPropertyCompositionFatal(
                                TraitCompositionConflictMessage::sameHookedClassTraitProperty(
                                    $entry->name,
                                    $traitName,
                                    $property->name
                                ),
                                $entry
                            );
                        }
                        // Identical class+trait definitions merge; keep the class property (#22850).
                        if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $property)) {
                            continue 2;
                        }
                        $this->throwTraitPropertyCompositionFatal(
                            TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                $entry->name,
                                $traitName,
                                $property->name
                            ),
                            $entry
                        );
                    }
                    $prevTraitEntry = isset($entry->traitPropertySources[$propLc])
                        ? ($this->context->classes[strtolower(ltrim($entry->traitPropertySources[$propLc], '\\'))] ?? null)
                        : ($this->context->classes[$existing->declaringClassLc] ?? null);
                    $prevHasHooks = null !== $prevTraitEntry
                        && VM\AbstractPropertyHookCheck::propertyHasHooks($prevTraitEntry, $existing, $this->context);
                    $traitHasHooks = VM\AbstractPropertyHookCheck::propertyHasHooks($trait, $property, $this->context);
                    if ($prevHasHooks || $traitHasHooks) {
                        $prevTrait = $entry->traitPropertySources[$propLc]
                            ?? (
                                isset($this->context->classes[$existing->declaringClassLc])
                                    ? $this->context->classes[$existing->declaringClassLc]->name
                                    : $existing->declaringClassLc
                            );
                        $this->throwTraitPropertyCompositionFatal(
                            TraitCompositionConflictMessage::sameHookedProperty(
                                $prevTrait,
                                $traitName,
                                $property->name,
                                $entry->name
                            ),
                            $entry
                        );
                    }
                    if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $property)) {
                        // Two traits with identical definitions — keep the first (#22850).
                        continue 2;
                    }
                    $prevTrait = $entry->traitPropertySources[$propLc]
                        ?? (
                            isset($this->context->classes[$existing->declaringClassLc])
                                ? $this->context->classes[$existing->declaringClassLc]->name
                                : $existing->declaringClassLc
                        );
                    $this->throwTraitPropertyCompositionFatal(
                        TraitCompositionConflictMessage::incompatibleProperty(
                            $prevTrait,
                            $traitName,
                            $property->name,
                            $entry->name
                        ),
                        $entry
                    );
                }
            }
            $cloned = $this->cloneClassPropertyForEntry($property, $entry);
            // zend_inheritance.c: trait instance properties are owned by the composing class (#26593).
            if (!$entry->isTrait) {
                $cloned->declaringClassLc = $classLc;
                $entry->traitPropertySources[$propLc] = $trait->name !== '' ? $trait->name : $traitName;
            }
            $entry->properties[] = $cloned;
            if (isset($trait->propertyAttributeNames[$propLc])) {
                $entry->propertyAttributeNames[$propLc] = $trait->propertyAttributeNames[$propLc];
            }
            if (isset($trait->propertyAttributeEntries[$propLc])) {
                $entry->propertyAttributeEntries[$propLc] = $trait->propertyAttributeEntries[$propLc];
            }
            if (isset($trait->propDeprecated[$propLc])) {
                $entry->propDeprecated[$propLc] = $trait->propDeprecated[$propLc];
            }
            if (isset($trait->propertySourceLocations[$propLc])) {
                $entry->propertySourceLocations[$propLc] = $trait->propertySourceLocations[$propLc];
            }
        }
    }

    private function cloneClassPropertyForEntry(VM\ClassProperty $property, ClassEntry $entry): VM\ClassProperty
    {
        $prototype = clone $property->prototype;
        $default = null !== $property->default ? clone $property->default : null;
        $declaringLc = '' !== $property->declaringClassLc
            ? $property->declaringClassLc
            : strtolower($entry->name);
        $cloned = new VM\ClassProperty(
            $property->name,
            $default,
            $prototype,
            $property->readonly,
            $property->visibility,
            $declaringLc,
            $property->setVisibility,
            $property->getVisibility,
            $property->asymmetricExplicitRead
        );
        $cloned->getHookMethodLc = $property->getHookMethodLc;
        $cloned->setHookMethodLc = $property->setHookMethodLc;
        $cloned->unsetHookMethodLc = $property->unsetHookMethodLc;
        $cloned->getHookParameterized = $property->getHookParameterized;
        $cloned->getHookByRef = $property->getHookByRef;
        $cloned->propertyHookVirtual = $property->propertyHookVirtual;
        $cloned->propertyFinal = $property->propertyFinal;
        $cloned->fromConstructorPromotion = $property->fromConstructorPromotion;
        $cloned->defaultInitBlock = $property->defaultInitBlock;
        $cloned->defaultInitResultSlot = $property->defaultInitResultSlot;

        return $cloned;
    }

    /**
     * @param list<string> $pendingTraits
     * @param array<string, true> $ownMethods
     */
    protected function flushPendingTraitUses(
        ClassEntry $entry,
        array $pendingTraits,
        array $ownMethods = [],
        ?Frame $warningFrame = null
    ): void {
        if ([] === $pendingTraits) {
            return;
        }
        $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, [], $ownMethods, $warningFrame);
    }
}
