<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PhpParser\Node\Stmt\Class_ as ClassNode;

/**
 * Compile-time check: child class constant visibility must not be narrower than
 * an inherited (non-private) parent constant (#22929).
 *
 * php-src: Zend/zend_inheritance.c — do_inherit_class_constant / access level check
 * Message shapes:
 *   - parent public:  "Access level to B::X must be public (as in class A)"
 *   - parent protected: "Access level to B::X must be protected (as in class A) or weaker"
 *
 * Interface / trait composition use different Zend diagnostics and are out of scope here.
 */
final class ClassConstVisibilityInheritCheck
{
    private const VIS_PUBLIC = 1;
    private const VIS_PROTECTED = 2;
    private const VIS_PRIVATE = 3;

    /**
     * @var array<string, array{
     *     display: string,
     *     constants: array<string, array{display: string, vis: int, file: string, line: int}>,
     *     extends: ?string
     * }>
     */
    private array $types = [];

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
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->types[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'constants' => $this->collectConstants($class->stmts->children),
            'extends' => $parentLc,
        ];
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, array{display: string, vis: int, file: string, line: int}>
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
                'vis' => $this->visibilityRank($member),
                'file' => $member->getFile(),
                'line' => max(1, $member->getLine()),
            ];
        }

        return $constants;
    }

    private function verify(): void
    {
        foreach ($this->types as $type) {
            if (null === $type['extends'] || '' === $type['extends']) {
                continue;
            }
            foreach ($type['constants'] as $constLc => $childConst) {
                $parent = $this->findInheritedConstant($type['extends'], $constLc);
                if (null === $parent) {
                    continue;
                }
                // Child may widen or keep the same visibility; narrowing is fatal.
                if ($childConst['vis'] <= $parent['vis']) {
                    continue;
                }
                throw new CompileFatal(
                    $childConst['file'],
                    $childConst['line'],
                    $this->accessLevelMessage(
                        $type['display'],
                        $childConst['display'],
                        $parent['classDisplay'],
                        $parent['vis']
                    )
                );
            }
        }
    }

    /**
     * Walk the extends chain for the nearest non-private inherited constant.
     *
     * @return array{classDisplay: string, vis: int}|null
     */
    private function findInheritedConstant(string $parentLc, string $constLc): ?array
    {
        $seen = [];
        $current = $parentLc;
        while ('' !== $current && !isset($seen[$current])) {
            $seen[$current] = true;
            if (!isset($this->types[$current])) {
                break;
            }
            $class = $this->types[$current];
            if (isset($class['constants'][$constLc])) {
                $const = $class['constants'][$constLc];
                // Private parent constants are not inherited (zend_constants.c / #19615).
                if (self::VIS_PRIVATE === $const['vis']) {
                    $current = $class['extends'] ?? '';
                    if (null === $current) {
                        break;
                    }

                    continue;
                }

                return [
                    'classDisplay' => $class['display'],
                    'vis' => $const['vis'],
                ];
            }
            $current = $class['extends'] ?? '';
            if (null === $current) {
                break;
            }
        }

        return null;
    }

    private function accessLevelMessage(
        string $childClass,
        string $constName,
        string $parentClass,
        int $parentVis
    ): string {
        if (self::VIS_PUBLIC === $parentVis) {
            return sprintf(
                'Access level to %s::%s must be public (as in class %s)',
                $childClass,
                $constName,
                $parentClass
            );
        }

        return sprintf(
            'Access level to %s::%s must be protected (as in class %s) or weaker',
            $childClass,
            $constName,
            $parentClass
        );
    }

    private function visibilityRank(Op\Terminal\Const_ $const): int
    {
        $flags = property_exists($const, 'flags') ? (int) $const->flags : 0;
        $vis = $flags & (CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_PROTECTED | CfgFunc::FLAG_PRIVATE);
        if (0 === $vis) {
            $vis = ClassNode::MODIFIER_PUBLIC;
        }
        if (0 !== ($vis & (CfgFunc::FLAG_PRIVATE | ClassNode::MODIFIER_PRIVATE))) {
            return self::VIS_PRIVATE;
        }
        if (0 !== ($vis & (CfgFunc::FLAG_PROTECTED | ClassNode::MODIFIER_PROTECTED))) {
            return self::VIS_PROTECTED;
        }

        return self::VIS_PUBLIC;
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
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
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
