<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\VM\Variable;

/**
 * Compile-time check: incompatible trait/class constant composition (#8882, #7012, #5385).
 *
 * php-src: Zend/zend_traits.c — zend_traits_compile_role_constants
 */
final class TraitClassConstConflictCheck
{
    /**
     * @var array<string, array{
     *     display: string,
     *     file: string,
     *     line: int,
     *     constants: array<string, array{display: string, value: ?Variable, file: string, line: int}>,
     *     traitUses: list<array{lc: string, display: string, file: string, line: int}>
     * }>
     */
    private array $traits = [];

    /**
     * @var array<string, array{
     *     display: string,
     *     file: string,
     *     line: int,
     *     constants: array<string, array{display: string, value: ?Variable, file: string, line: int}>,
     *     traitUses: list<array{lc: string, display: string, file: string, line: int}>
     * }>
     */
    private array $compositions = [];

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
                $this->collectClassLike($child);
            } elseif ($child instanceof Op\Stmt\Trait_) {
                $this->collectClassLike($child);
            } elseif ($child instanceof Op\Stmt\Enum_) {
                $this->collectClassLike($child);
            }
        }
    }

    private function collectClassLike(Op\Stmt\Class_|Op\Stmt\Trait_|Op\Stmt\Enum_ $type): void
    {
        $lc = $this->operandLcName($type->name);
        if (null === $lc) {
            return;
        }
        $entry = [
            'display' => $this->operandDisplayName($type->name, $lc),
            'file' => $type->getFile(),
            'line' => max(1, $type->getLine()),
            'constants' => $this->collectConstants($type->stmts->children),
            'traitUses' => $this->collectTraitUses($type->stmts->children),
        ];
        if ($type instanceof Op\Stmt\Trait_) {
            $this->traits[$lc] = $entry;
        } else {
            $this->compositions[$lc] = $entry;
        }
    }

    /**
     * @param list<Op> $members
     *
     * @return list<array{lc: string, display: string, file: string, line: int}>
     */
    private function collectTraitUses(array $members): array
    {
        $traits = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\TraitUse) {
                continue;
            }
            $useFile = $member->getFile();
            $useLine = max(1, $member->getLine());
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->operandLcName($traitOperand);
                if (null === $traitLc) {
                    continue;
                }
                $traits[] = [
                    'lc' => $traitLc,
                    'display' => $this->operandDisplayName($traitOperand, $traitLc),
                    'file' => $useFile,
                    'line' => $useLine,
                ];
            }
        }

        return $traits;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, array{display: string, value: ?Variable, file: string, line: int}>
     */
    private function collectConstants(array $members): array
    {
        $constants = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            if (property_exists($member, 'isEnumCase') && $member->isEnumCase) {
                continue;
            }
            $name = $this->staticNameFromOperand($member->name);
            if (null === $name) {
                continue;
            }
            $constants[strtolower($name)] = [
                'display' => $name,
                'value' => ClassConstValueFold::fold($member),
                'file' => $member->getFile(),
                'line' => max(1, $member->getLine()),
            ];
        }

        return $constants;
    }

    private function verify(): void
    {
        foreach ($this->traits as $lc => $type) {
            $this->verifyTypeComposition($lc, $type, true);
        }
        foreach ($this->compositions as $lc => $type) {
            $this->verifyTypeComposition($lc, $type, false);
        }
    }

    /**
     * @param array{
     *     display: string,
     *     file: string,
     *     line: int,
     *     constants: array<string, array{display: string, value: ?Variable, file: string, line: int}>,
     *     traitUses: list<array{lc: string, display: string, file: string, line: int}>
     * } $type
     */
    private function verifyTypeComposition(string $lc, array $type, bool $isTrait): void
    {
        /** @var array<string, array{display: string, value: Variable, sourceDisplay: string, file: string, line: int}> $merged */
        $merged = [];
        $applied = [];
        foreach ($type['traitUses'] as $traitUse) {
            $traitLc = $traitUse['lc'];
            if (isset($applied[$traitLc])) {
                continue;
            }
            $applied[$traitLc] = true;
            if ($isTrait && $traitLc === $lc) {
                $this->throwTraitNotFound($traitUse['display'], $traitUse['file'], $traitUse['line']);
            }
            if (!isset($this->traits[$traitLc])) {
                $this->throwTraitNotFound($traitUse['display'], $traitUse['file'], $traitUse['line']);
            }
            foreach ($this->effectiveTraitConstants($traitLc) as $constLc => $traitConst) {
                if (null === $traitConst['value']) {
                    continue;
                }
                if (isset($merged[$constLc])) {
                    if (ClassConstValueFold::identical($merged[$constLc]['value'], $traitConst['value'])) {
                        continue;
                    }
                    $this->throwConflict(
                        $type['file'],
                        $type['line'],
                        $merged[$constLc]['sourceDisplay'],
                        $traitConst['sourceDisplay'],
                        $traitConst['display'],
                        $type['display']
                    );
                }
                $merged[$constLc] = [
                    'display' => $traitConst['display'],
                    'value' => $traitConst['value'],
                    'sourceDisplay' => $traitConst['sourceDisplay'],
                    'file' => $traitConst['file'],
                    'line' => $traitConst['line'],
                ];
            }
        }

        foreach ($type['constants'] as $constLc => $ownConst) {
            if (null === $ownConst['value'] || !isset($merged[$constLc])) {
                continue;
            }
            if (ClassConstValueFold::identical($merged[$constLc]['value'], $ownConst['value'])) {
                continue;
            }
            $this->throwConflict(
                $ownConst['file'],
                $ownConst['line'],
                $type['display'],
                $merged[$constLc]['sourceDisplay'],
                $ownConst['display'],
                $type['display']
            );
        }
    }

    /**
     * @return array<string, array{display: string, value: ?Variable, sourceDisplay: string, file: string, line: int}>
     */
    private function effectiveTraitConstants(string $traitLc, array &$visiting = []): array
    {
        if (!isset($this->traits[$traitLc])) {
            return [];
        }
        if (isset($visiting[$traitLc])) {
            return [];
        }
        $visiting[$traitLc] = true;
        $trait = $this->traits[$traitLc];
        /** @var array<string, array{display: string, value: ?Variable, sourceDisplay: string, file: string, line: int}> $merged */
        $merged = [];
        $applied = [];
        foreach ($trait['traitUses'] as $usedTraitUse) {
            $usedTraitLc = $usedTraitUse['lc'];
            if (isset($applied[$usedTraitLc])) {
                continue;
            }
            $applied[$usedTraitLc] = true;
            if (!isset($this->traits[$usedTraitLc])) {
                continue;
            }
            foreach ($this->effectiveTraitConstants($usedTraitLc, $visiting) as $constLc => $usedConst) {
                if (null === $usedConst['value']) {
                    continue;
                }
                if (isset($merged[$constLc])) {
                    if (ClassConstValueFold::identical($merged[$constLc]['value'], $usedConst['value'])) {
                        continue;
                    }
                    $this->throwConflict(
                        $trait['file'],
                        $trait['line'],
                        $merged[$constLc]['sourceDisplay'],
                        $usedConst['sourceDisplay'],
                        $usedConst['display'],
                        $trait['display']
                    );
                }
                $merged[$constLc] = $usedConst;
            }
        }
        foreach ($trait['constants'] as $constLc => $constInfo) {
            if (null === $constInfo['value']) {
                continue;
            }
            if (isset($merged[$constLc])) {
                if (ClassConstValueFold::identical($merged[$constLc]['value'], $constInfo['value'])) {
                    continue;
                }
                $this->throwConflict(
                    $constInfo['file'],
                    $constInfo['line'],
                    $trait['display'],
                    $merged[$constLc]['sourceDisplay'],
                    $constInfo['display'],
                    $trait['display']
                );
            }
            $merged[$constLc] = [
                'display' => $constInfo['display'],
                'value' => $constInfo['value'],
                'sourceDisplay' => $trait['display'],
                'file' => $constInfo['file'],
                'line' => $constInfo['line'],
            ];
        }

        return $merged;
    }

    private function throwConflict(
        string $file,
        int $line,
        string $first,
        string $second,
        string $constDisplay,
        string $classDisplay
    ): void {
        throw new CompileFatal(
            $file,
            $line,
            sprintf(
                '%s and %s define the same constant (%s) in the composition of %s. '
                .'However, the definition differs and is considered incompatible. Class was composed',
                $first,
                $second,
                $constDisplay,
                $classDisplay
            )
        );
    }

    private function throwTraitNotFound(string $display, string $file, int $line): void
    {
        throw new CompileFatal($file, $line, sprintf('Trait "%s" not found', $display));
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
