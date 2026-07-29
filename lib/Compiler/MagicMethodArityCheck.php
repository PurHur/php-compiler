<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time magic method parameter-count rules (PHP 8.0+).
 *
 * php-src: Zend/zend_API.c — zend_check_magic_method_args /
 * zend_check_magic_method_implementation (#25024, #25029 __toString)
 *
 * Counts declared non-variadic parameters (Zend common.num_args excludes the
 * trailing variadic parameter).
 */
final class MagicMethodArityCheck
{
    /**
     * Exact non-variadic parameter count required for each magic method.
     *
     * @var array<string, int>
     */
    private const EXACT_ARITY = [
        '__destruct' => 0,
        '__clone' => 0,
        '__tostring' => 0,
        '__debuginfo' => 0,
        '__sleep' => 0,
        '__wakeup' => 0,
        '__serialize' => 0,
        '__get' => 1,
        '__isset' => 1,
        '__unset' => 1,
        '__unserialize' => 1,
        '__set_state' => 1,
        '__set' => 2,
        '__call' => 2,
        '__callstatic' => 2,
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
            if (!isset(self::EXACT_ARITY[$methodLc])) {
                continue;
            }
            $expected = self::EXACT_ARITY[$methodLc];
            $actual = $this->nonVariadicParamCount($member->func->params);
            if ($actual === $expected) {
                continue;
            }
            $this->fatal($member, $this->arityMessage($classDisplay, $methodName, $expected));
        }
    }

    /**
     * @param list<Op\Expr\Param> $params
     */
    private function nonVariadicParamCount(array $params): int
    {
        $count = 0;
        foreach ($params as $param) {
            if ($param->variadic) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    private function arityMessage(string $classDisplay, string $methodName, int $expected): string
    {
        if (0 === $expected) {
            return "Method {$classDisplay}::{$methodName}() cannot take arguments";
        }
        if (1 === $expected) {
            return "Method {$classDisplay}::{$methodName}() must take exactly 1 argument";
        }

        return "Method {$classDisplay}::{$methodName}() must take exactly {$expected} arguments";
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
