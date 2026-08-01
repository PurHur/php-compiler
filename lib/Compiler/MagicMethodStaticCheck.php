<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\MethodVisibility;

/**
 * Compile-time static-ness checks for magic methods (Zend parity).
 *
 * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation
 * - "Method …::…() cannot be static" (#25026, #25027)
 * - "Method …::…() must be static" for __callStatic / __set_state (#25028)
 * - "The magic method … must have public visibility" for __callStatic / __set_state (#26437)
 *
 * `__construct` / `__destruct` / `__clone` are already rejected by nikic/php-parser
 * at parse time. `__toString` is also covered by MagicMethodReturnTypeCheck (#25025);
 * kept here so the "cannot be static" set stays complete.
 *
 * Instance magic visibility Warnings are #26439 (separate).
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
     * Canonical Zend display names for visibility Warning text.
     *
     * @var array<string, string> lowercase => display name
     */
    private const MUST_BE_STATIC = [
        '__callstatic' => '__callStatic',
        '__set_state' => '__set_state',
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
            if (isset(self::MUST_BE_STATIC[$methodLc])) {
                $canonical = self::MUST_BE_STATIC[$methodLc];
                if (!$isStatic) {
                    $this->fatal(
                        $member,
                        "Method {$classDisplay}::{$methodName}() must be static"
                    );
                }
                if (!MethodVisibility::isPublic(MethodVisibility::mask($member->func->flags))) {
                    $this->compileWarning(
                        $member,
                        "The magic method {$classDisplay}::{$canonical}() must have public visibility"
                    );
                }
            }
        }
    }

    private function compileWarning(Op\Stmt\ClassMethod $method, string $message): void
    {
        $file = $method->getFile();
        $line = max(1, $method->getLine());
        $text = sprintf("Warning: %s in %s on line %d\n", $message, $file, $line);
        if (\defined('STDERR') && \is_resource(STDERR)) {
            @fwrite(STDERR, $text);

            return;
        }
        @trigger_error($message, E_USER_WARNING);
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
