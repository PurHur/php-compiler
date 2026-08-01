<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time magic method parameter type rules (PHP 8.0+).
 *
 * php-src: Zend/zend_API.c — zend_check_magic_method_arg_type /
 * zend_check_magic_method_implementation (#26500; arity in #25024)
 *
 * When a parameter type is declared, its type mask must include the required
 * MAY_BE_STRING / MAY_BE_ARRAY bit (unions that contain the type, `mixed`, and
 * `iterable` for array are accepted — matching ZEND_TYPE_FULL_MASK).
 */
final class MagicMethodParamTypeCheck
{
    /**
     * Magic methods → 0-based param index → required builtin ("string"|"array").
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_PARAM_TYPES = [
        '__get' => [0 => 'string'],
        '__set' => [0 => 'string'],
        '__isset' => [0 => 'string'],
        '__unset' => [0 => 'string'],
        '__call' => [0 => 'string', 1 => 'array'],
        '__callstatic' => [0 => 'string', 1 => 'array'],
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
            if (!isset(self::REQUIRED_PARAM_TYPES[$methodLc])) {
                continue;
            }
            $params = $member->func->params;
            foreach (self::REQUIRED_PARAM_TYPES[$methodLc] as $index => $required) {
                if (!isset($params[$index])) {
                    continue;
                }
                $param = $params[$index];
                if (!$this->hasExplicitParamType($param->declaredType)) {
                    continue;
                }
                if ($this->typeAllowsRequired($param->declaredType, $required)) {
                    continue;
                }
                $paramName = $this->paramDisplayName($param->name);
                $this->fatal(
                    $member,
                    "{$classDisplay}::{$methodName}(): Parameter #".($index + 1)
                    ." (\${$paramName}) must be of type {$required} when declared"
                );
            }
        }
    }

    private function hasExplicitParamType(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }

        // php-cfg uses Mixed_ as the placeholder for *untyped* parameters.
        return !$type instanceof Op\Type\Mixed_;
    }

    private function typeAllowsRequired(Op\Type $type, string $required): bool
    {
        $sig = TypeSig::fromCfgType($type);
        // Explicit `mixed` (and unparseable) → ZEND_TYPE_FULL_MASK includes all bits.
        if (null === $sig) {
            return true;
        }

        return $this->sigAllowsRequired($sig, $required);
    }

    private function sigAllowsRequired(TypeSig $sig, string $required): bool
    {
        if ($sig->void || $sig->never) {
            return false;
        }
        if (null !== $sig->unionMembers) {
            foreach ($sig->unionMembers as $member) {
                if ($this->sigAllowsRequired($member, $required)) {
                    return true;
                }
            }

            return false;
        }
        // Intersection / class / self / static → complex type, no MAY_BE_STRING/ARRAY bit.
        if (null !== $sig->intersectionMembers || null !== $sig->classLc || $sig->self || $sig->static) {
            return false;
        }
        if ($required === $sig->builtinScalar) {
            return true;
        }
        // iterable's type mask includes MAY_BE_ARRAY (zend_compile iterable).
        if ('array' === $required && 'iterable' === $sig->builtinScalar) {
            return true;
        }

        return false;
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

    private function paramDisplayName(Operand $op): string
    {
        $name = $this->staticNameFromOperand($op);

        return (null === $name || '' === $name) ? 'param' : $name;
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
