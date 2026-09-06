<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;

/**
 * Trait-use adaptations, property-hook virtual marks, and deferred abstract
 * trait body flush for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileClassAndTraitUses}: {@code markJitPropertyVirtualFromHookRegistry}
 * through {@code compileDeferredAbstractTraitMethodBodies}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.c (zend_compile_traits), Zend/zend_inheritance.c
 * (zend_check_trait_usage), Zend/zend_traits.c — move-only Concern extract; no new C ABI.
 */
trait CompileTraitUsesWithAdaptations
{
    /**
     * Record ZEND_ACC_VIRTUAL from PropertyHooks registry for AOT isVirtual() (#27516).
     */
    private function markJitPropertyVirtualFromHookRegistry(string $className, int $classId, string $propName): void
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
        if ('' === $lcClass) {
            return;
        }
        $registry = $this->context->runtime->vmContext->propertyHookRegistry[$lcClass] ?? null;
        if (!is_array($registry)) {
            return;
        }
        $meta = $registry[$propName] ?? $registry[strtolower($propName)] ?? null;
        if (is_array($meta) && !empty($meta['virtual'])) {
            $this->context->type->object->markPropertyVirtual($classId, $propName);
        }
    }

    /**
     * @param list<OpCode> $pendingOps
     */
    private function jitPropertyNewClassNameFromOps(Block $block, array $pendingOps): ?string
    {
        foreach (array_reverse($pendingOps) as $newOp) {
            if (OpCode::TYPE_NEW !== $newOp->type) {
                continue;
            }
            $classOp = $block->getOperand($newOp->arg2);
            if (!$classOp instanceof Operand\Literal) {
                return null;
            }

            return $classOp->value;
        }

        return null;
    }

    /**
     * @param list<string> $pendingTraitNames
     * @param array<string, true> $ownMethods
     * @param array<string, string> $traitMethodSources
     */
    private function flushPendingJitTraitUses(
        Block $block,
        array &$pendingTraitNames,
        int $classId,
        array $ownMethods,
        array &$traitMethodSources
    ): void {
        if ([] === $pendingTraitNames || $this->shouldSkipExternalClassBodyLowering($classId)) {
            $pendingTraitNames = [];

            return;
        }
        $this->applyJitTraitUsesWithAdaptations(
            $block,
            $pendingTraitNames,
            [],
            $classId,
            $ownMethods,
            $traitMethodSources
        );
        $pendingTraitNames = [];
    }

    /**
     * Precedence/alias trait must be a direct {@code use} on the composing class
     * (Zend/zend_inheritance.c zend_check_trait_usage, #32129 / #32130).
     *
     * @param array<string, string> $usedTraitNameByLc
     */
    private function throwIfJitAdaptationTraitNotDirectlyUsed(
        string $referencedName,
        string $className,
        array $usedTraitNameByLc,
        bool $unknownIsCouldNotFind = true,
    ): void {
        $lc = strtolower(ltrim($referencedName, '\\'));
        if (isset($usedTraitNameByLc[$lc])) {
            return;
        }
        $object = $this->context->type->object;
        $existsAsTrait = $object->hasDeclaredClass($lc) && $object->isTraitClass($lc);
        $existsAsNonTrait = $object->hasDeclaredClass($lc) && !$object->isTraitClass($lc);
        $declaredName = null;
        if ($existsAsTrait || $existsAsNonTrait) {
            try {
                $declaredName = $object->classNameForId($object->lookup($lc));
            } catch (\LogicException $e) {
                $declaredName = ltrim($referencedName, '\\');
            }
        }
        if ($existsAsNonTrait) {
            VM\TraitCompositionConflictMessage::throwUnresolvedAdaptationTrait(
                $referencedName,
                $className,
                false,
                null,
                true,
                $declaredName,
            );
        }
        if (!$existsAsTrait && !$unknownIsCouldNotFind) {
            return;
        }
        VM\TraitCompositionConflictMessage::throwUnresolvedAdaptationTrait(
            $referencedName,
            $className,
            $existsAsTrait,
            $existsAsTrait ? $declaredName : null,
        );
    }

    /**
     * Merge trait methods/constants onto a using class (Zend zend_compile_traits; #3238).
     *
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     * @param array<string, string> $traitMethodSources method lc => trait FQCN
     */
    private function applyJitTraitUsesWithAdaptations(
        Block $block,
        array $traitNames,
        array $adaptations,
        int $classId,
        array $ownMethods,
        array &$traitMethodSources
    ): void {
        if ([] === $traitNames) {
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
        $classLc = '' !== ($this->context->scope->className ?? '')
            ? strtolower(ltrim($this->context->scope->className, '\\'))
            : strtolower(ltrim($this->context->type->object->classNameForId($classId), '\\'));
        $className = $this->context->type->object->classNameForId($classId);
        $object = $this->context->type->object;
        // Zend: trait methods override inherited parent methods; only composing-class
        // own methods exclude the trait (#19630, zend_traits.c).
        $excluded = $ownMethods;

        /** @var array<string, array<string, array{traitId: int, traitName: string, traitLc: string, methodLc: string}>> */
        $perTraitMethods = [];
        /** @var array<string, string> */
        $usedTraitNameByLc = [];
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (!$object->hasDeclaredClass($traitName)) {
                // Missing use-target: Zend Trait "%s" not found (#30012, zend_compile.c).
                // Adaptation losers still use "Could not find trait" below (zend_traits.c).
                VM\TraitCompositionConflictMessage::throwRuntimeFatal(
                    VM\TraitCompositionConflictMessage::notFound($traitName),
                    '',
                    1,
                );
            }
            if (!$object->isTraitClass($traitLc)) {
                throw new \LogicException("{$traitName} is not a trait");
            }
            $traitId = $object->lookup($traitName);
            if (VM\LazyGhostTraitSupport::isLazyGhostTrait($traitLc)) {
                $object->markLazyGhostTraitClass($classId);
            }
            $object->inheritTraitConstants($classId, $traitId, $traitName);
            $object->inheritTraitStaticProperties($classId, $traitId, $traitName);
            $object->inheritTraitInstanceProperties($classId, $traitId, $traitName);
            if (!isset($perTraitMethods[$traitLc])) {
                $perTraitMethods[$traitLc] = [];
            }
            $usedTraitNameByLc[$traitLc] = $traitName;
            $object->recordClassUsedTrait($classLc, $traitName);
            foreach ($object->declaredMethodNames($traitId) as $methodLc) {
                $perTraitMethods[$traitLc][$methodLc] = [
                    'traitId' => $traitId,
                    'traitName' => $traitName,
                    'traitLc' => $traitLc,
                    'methodLc' => $methodLc,
                    'sourceMethodLc' => $methodLc,
                ];
            }
        }

        /** @var array<string, true> */
        $excludedByPrecedence = [];
        foreach ($adaptations as $adaptation) {
            if ('precedence' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $winnerTraitLc = strtolower(ltrim((string) ($adaptation['trait'] ?? ''), '\\'));
            if ('' === $winnerTraitLc) {
                throw new \LogicException('Trait precedence adaptation must specify a trait');
            }
            $this->throwIfJitAdaptationTraitNotDirectlyUsed(
                (string) ($adaptation['trait'] ?? ''),
                $className,
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
                $this->throwIfJitAdaptationTraitNotDirectlyUsed(
                    (string) $loserTrait,
                    $className,
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

        /** @var array<string, array{traitId: int, traitName: string, traitLc: string, methodLc: string}> */
        $merged = [];
        foreach ($perTraitMethods as $traitLc => $methods) {
            foreach ($methods as $methodLc => $data) {
                if (isset($excludedByPrecedence["{$traitLc}\0{$methodLc}"])) {
                    continue;
                }
                if (isset($excluded[$methodLc])) {
                    continue;
                }
                if (isset($merged[$methodLc])) {
                    if ($merged[$methodLc]['traitLc'] === $traitLc) {
                        continue;
                    }
                    $prev = $merged[$methodLc]['traitName'];
                    throw new \CompileError(
                        "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$className}::{$methodLc}, "
                        ."because of collision with {$prev}::{$methodLc}"
                    );
                }
                $merged[$methodLc] = $data;
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
                $this->throwIfJitAdaptationTraitNotDirectlyUsed(
                    (string) $adaptation['trait'],
                    $className,
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
                    if (isset($excluded[$methodLc]) && $existsInTraits) {
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
                $data = $perTraitMethods[$traitLcFilter][$methodLc];
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
                    "Trait method {$data['traitName']}::{$sourceMethod} has not been applied as {$className}::{$aliasName}, "
                    ."because of collision with {$prev['traitName']}::{$aliasName}"
                );
            }

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['sourceMethodLc'] = $methodLc;
            $data['methodLc'] = $newNameLc;
            // Zend zend_traits.c: `as` aliases — original method stays callable (#22718).
            $merged[$newNameLc] = $data;
            // ReflectionClass::getTraitAliases() (#34129; peer VM ClassEntry::$traitAliases).
            $object->recordClassTraitAlias(
                $classLc,
                (string) $newName,
                $data['traitName'].'::'.(string) ($adaptation['method'] ?? '')
            );
        }

        // Abstract composer + unimplemented trait abstracts (LoggerTrait::log on AbstractLogger):
        // defer concrete trait bodies until a subclass exists so `$this->log()` subtype-dispatch
        // sees NullLogger / Slim\Logger (IncludeHelper NestedJIT order, #36382).
        $deferTraitBodies = false;
        if ($object->isAbstractClassLc($classLc)) {
            foreach ($merged as $checkLc => $checkData) {
                if (isset($ownMethods[$checkLc]) || isset($excluded[$checkLc])) {
                    continue;
                }
                $checkSrc = $checkData['sourceMethodLc'] ?? $checkData['methodLc'];
                if (null !== $object->traitMethodBlock($checkData['traitId'], $checkSrc)) {
                    continue;
                }
                $checkVis = $checkData['vis'] ?? $object->methodVisibility($checkData['traitId'], $checkLc);
                if (0 !== ($checkVis & \PHPCfg\Func::FLAG_ABSTRACT)) {
                    $deferTraitBodies = true;
                    break;
                }
            }
        }

        foreach ($merged as $methodLc => $data) {
            if (isset($excluded[$methodLc])) {
                continue;
            }
            if (isset($ownMethods[$methodLc]) && !isset($traitMethodSources[$methodLc])) {
                continue;
            }
            if (isset($traitMethodSources[$methodLc])) {
                $prevTrait = $traitMethodSources[$methodLc];
                if ($prevTrait === $data['traitName']) {
                    continue;
                }
                throw new \CompileError(
                    "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$className}::{$methodLc}, "
                    ."because of collision with {$prevTrait}::{$methodLc}"
                );
            }
            $traitMethodSources[$methodLc] = $data['traitName'];
            $traitId = $data['traitId'];
            $vis = $data['vis'] ?? $object->methodVisibility($traitId, $methodLc);
            $object->defineMethodVisibility(
                $classId,
                $methodLc,
                $vis
            );
            if ('__construct' === $methodLc) {
                $object->markHasConstructor($classId);
            }
            $object->recordTraitMethodSource($classId, $methodLc, $data['traitLc']);
            $sourceMethodLc = $data['sourceMethodLc'] ?? $data['methodLc'];
            $methodBlock = $object->traitMethodBlock($traitId, $sourceMethodLc);
            if (null !== $methodBlock) {
                $methodBlock = TraitMethodFunctionStatic::bindBlock(
                    $methodBlock,
                    $className,
                    $data['traitName']
                );
                if ($this->context->scope->blockStorage->contains($methodBlock)) {
                    $this->context->scope->blockStorage->detach($methodBlock);
                }
                if ($deferTraitBodies) {
                    $this->deferredAbstractTraitMethodBodies[$classLc][] = [
                        'block' => $methodBlock,
                        'funcName' => $classLc.'::'.$methodLc,
                        'className' => $className,
                        'traitName' => $data['traitName'],
                    ];
                    continue;
                }
                $this->compileTraitMethodBodyOntoClass($methodBlock, $className, $classLc.'::'.$methodLc);
            }
        }
    }

    /**
     * Lower a trait method body with traitComposingClassName bound (#18878 / #36382).
     */
    private function compileTraitMethodBodyOntoClass(Block $methodBlock, string $className, string $funcName): void
    {
        $savedTraitComposing = $this->context->scope->traitComposingClassName;
        $savedScopeClassName = $this->context->scope->className;
        $this->context->scope->traitComposingClassName = $className;
        if ('' === $savedScopeClassName
            || $this->context->type->object->isTraitClass(strtolower(ltrim($savedScopeClassName, '\\')))) {
            $this->context->scope->className = strtolower(ltrim($className, '\\'));
        }
        try {
            $this->compileBlock($methodBlock, $funcName);
        } finally {
            $this->context->scope->traitComposingClassName = $savedTraitComposing;
            $this->context->scope->className = $savedScopeClassName;
        }
    }

    /**
     * Flush deferred abstract-composer trait bodies once a concrete subclass is declared (#36382).
     */
    private function flushDeferredAbstractTraitMethodBodiesForConcrete(string $className): void
    {
        $object = $this->context->type->object;
        $current = strtolower(ltrim($className, '\\'));
        if ('' === $current || $object->isAbstractClassLc($current)) {
            return;
        }
        $visited = [];
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $parent = $object->parentClassLc($current);
            if (null === $parent || '' === $parent) {
                break;
            }
            if (isset($this->deferredAbstractTraitMethodBodies[$parent])) {
                $this->compileDeferredAbstractTraitMethodBodies($parent);
            }
            $current = $parent;
        }
    }

    /**
     * @param string $abstractLc lowercase abstract composing class
     */
    private function compileDeferredAbstractTraitMethodBodies(string $abstractLc): void
    {
        $pending = $this->deferredAbstractTraitMethodBodies[$abstractLc] ?? [];
        unset($this->deferredAbstractTraitMethodBodies[$abstractLc]);
        foreach ($pending as $item) {
            $funcName = $item['funcName'];
            if ($this->context->functionIsRegistered($funcName)) {
                continue;
            }
            $this->compileTraitMethodBodyOntoClass($item['block'], $item['className'], $funcName);
        }
    }
}
