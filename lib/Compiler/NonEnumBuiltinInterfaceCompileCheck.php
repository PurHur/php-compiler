<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time check: non-enum classes cannot implement UnitEnum/BackedEnum (#15447).
 *
 * php-src: Zend/zend_enum.c — zend_register_enum non-enum implements guard
 */
final class NonEnumBuiltinInterfaceCompileCheck
{
    /** @var array<string, string> lc interface name => display */
    private const RESERVED_ENUM_INTERFACES = [
        'unitenum' => 'UnitEnum',
        'backedenum' => 'BackedEnum',
    ];

    public static function validate(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $classDisplay = self::operandDisplayName($child->name, 'class');
            foreach ($child->implements as $ifaceOperand) {
                $ifaceLc = self::operandLcName($ifaceOperand);
                if (null === $ifaceLc || !isset(self::RESERVED_ENUM_INTERFACES[$ifaceLc])) {
                    continue;
                }
                throw new CompileFatal(
                    $child->getFile(),
                    max(1, $child->getLine()),
                    'Non-enum class '.$classDisplay.' cannot implement interface '
                    .self::RESERVED_ENUM_INTERFACES[$ifaceLc]
                );
            }
        }
    }

    private static function operandLcName(Operand $op): ?string
    {
        $name = self::staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private static function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = self::staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $fallback;
        }

        return $name;
    }

    private static function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::staticNameFromOperand($op->name);
        }

        return null;
    }
}
