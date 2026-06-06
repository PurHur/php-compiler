<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: enums must implement declared abstract methods (#6618).
 *
 * php-src: Zend/zend_compile.c — enum abstract method validation;
 * Zend/zend_enum.c — enum method table / abstract enforcement.
 */
final class EnumAbstractMethodCompileCheck
{
    /** @var array<string, array{abstract: array<string, string>}> */
    private array $traits = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collectTraits($script);
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Enum_) {
                $check->validateEnum($child);
            }
        }
    }

    private function collectTraits(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Trait_) {
                continue;
            }
            $lc = $this->operandLcName($child->name);
            if (null === $lc) {
                continue;
            }
            $this->traits[$lc] = [
                'abstract' => $this->collectAbstractMethodNames($child->stmts->children),
            ];
        }
    }

    private function validateEnum(Op\Stmt\Enum_ $enum): void
    {
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        $abstract = $this->collectAbstractMethodNames($enum->stmts->children);
        $concrete = $this->collectConcreteMethodNames($enum->stmts->children);

        foreach ($enum->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\TraitUse) {
                continue;
            }
            foreach ($member->traits as $traitOperand) {
                $traitLc = $this->operandLcName($traitOperand);
                if (null === $traitLc || !isset($this->traits[$traitLc])) {
                    continue;
                }
                foreach ($this->traits[$traitLc]['abstract'] as $methodLc => $methodName) {
                    if (!isset($abstract[$methodLc])) {
                        $abstract[$methodLc] = $methodName;
                    }
                }
            }
        }

        $missing = [];
        foreach ($abstract as $methodLc => $methodName) {
            if (!isset($concrete[$methodLc])) {
                $missing[$methodLc] = $methodName;
            }
        }
        if ([] === $missing) {
            return;
        }

        $count = count($missing);
        $suffix = 1 === $count ? '' : 's';
        $list = implode(', ', array_map(
            static fn (string $name): string => $enumDisplay.'::'.$name,
            array_values($missing)
        ));
        throw new CompileFatal(
            $enum->getFile(),
            max(1, $enum->getLine()),
            "Enum {$enumDisplay} must implement {$count} abstract private method{$suffix} ({$list})"
        );
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, string> lowercase name => display name
     */
    private function collectAbstractMethodNames(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if ($this->methodHasBody($member)) {
                continue;
            }
            $name = $member->func->name;
            if (!is_string($name)) {
                continue;
            }
            $methods[strtolower($name)] = $name;
        }

        return $methods;
    }

    /**
     * @param list<Op> $members
     *
     * @return array<string, true>
     */
    private function collectConcreteMethodNames(array $members): array
    {
        $methods = [];
        foreach ($members as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            if (!$this->methodHasBody($member)) {
                continue;
            }
            $name = $member->func->name;
            if (!is_string($name)) {
                continue;
            }
            $methods[strtolower($name)] = true;
        }

        return $methods;
    }

    private function methodHasBody(Op\Stmt\ClassMethod $method): bool
    {
        $cfg = $method->func->cfg;
        if (null === $cfg) {
            return false;
        }

        return [] !== $cfg->children;
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $fallback;
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
