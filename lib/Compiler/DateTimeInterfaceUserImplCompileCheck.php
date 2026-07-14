<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\VM\DateTimeInterfaceSupport;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Zend-shaped fatal when user classes/enums implement DateTimeInterface (#18781).
 *
 * php-src: Zend/zend_compile.c — zend_check_implement_interface (runtime fatal channel)
 */
final class DateTimeInterfaceUserImplCompileCheck
{
    public static function validate(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                self::rejectImplements($child->implements, $child->getFile(), max(1, $child->getLine()));

                continue;
            }
            if ($child instanceof Op\Stmt\Enum_) {
                self::rejectImplements($child->implements, $child->getFile(), max(1, $child->getLine()));
            }
        }
    }

    /**
     * @param list<Operand> $implements
     */
    private static function rejectImplements(array $implements, string $file, int $line): void
    {
        foreach ($implements as $ifaceOperand) {
            $ifaceLc = self::operandLcName($ifaceOperand);
            if (null === $ifaceLc || !DateTimeInterfaceSupport::rejectsUserImplementationLc($ifaceLc)) {
                continue;
            }
            throw new CompileFatal(
                $file,
                $line,
                DateTimeInterfaceSupport::USER_IMPLEMENTATION_FORBIDDEN_MESSAGE
            );
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
