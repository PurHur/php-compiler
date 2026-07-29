<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time static-ness checks for magic methods (Zend parity).
 *
 * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation
 * - "Method …::…() cannot be static" (#25026, #25027)
 * - "Method …::…() must be static" for __callStatic / __set_state (#25028)
 *
 * `__construct` / `__destruct` / `__clone` are already rejected by nikic/php-parser
 * at parse time. `__toString` is also covered by MagicMethodReturnTypeCheck (#25025);
 * kept here so the "cannot be static" set stays complete.
 */
final class MagicMethodStaticCheck
{
    /**
     * Magic methods that must not be declared static.
     *
     * @var array<string, true>
     */
    private const CANNOT_BE_STATIC = [
        '__sleep' => true,
        '__wakeup' => true,
        '__invoke' => true,
        '__get' => true,
        '__set' => true,
        '__isset' => true,
        '__unset' => true,
        '__call' => true,
        '__tostring' => true,
    ];

    /**
     * Magic methods that must be declared static.
     *
     * @var array<string, true>
     */
    private const MUST_BE_STATIC = [
        '__callstatic' => true,
        '__set_state' => true,
    ];

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\ClassLike) {
                $check->validateClassLike($child);
            }
        }
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        $classDisplay = $this->classDisplayName($class->name);
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodName = $member->func->name;
            if (!is_string($methodName) || $methodName === '') {
                continue;
            }
            $methodLc = strtolower($methodName);
            $isStatic = 0 !== ($member->func->flags & Func::FLAG_STATIC);
            if (isset(self::CANNOT_BE_STATIC[$methodLc])) {
                if ($isStatic) {
                    $this->fatal(
                        $member,
                        "Method {$classDisplay}::{$methodName}() cannot be static"
                    );
                }
                continue;
            }
            if (isset(self::MUST_BE_STATIC[$methodLc]) && !$isStatic) {
                $this->fatal(
                    $member,
                    "Method {$classDisplay}::{$methodName}() must be static"
                );
            }
        }
    }

    private function fatal(Op\Stmt\ClassMethod $method, string $message): void
    {
        throw new CompileFatal(
            $method->getFile(),
            $method->getLine(),
            $message
        );
    }

    private function classDisplayName(Operand $op): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name || '' === $name) {
            return 'class';
        }

        return ltrim($name, '\\');
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
