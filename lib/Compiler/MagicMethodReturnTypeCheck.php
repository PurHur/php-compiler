<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;

/**
 * Compile-time magic method return type rules (PHP 8.0+).
 *
 * php-src: Zend/zend_compile.c — zend_compile_method magic return checks
 */
final class MagicMethodReturnTypeCheck
{
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
        $classDisplay = $this->operandDisplayName($class->name, 'class');
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodLc = strtolower($member->func->name);
            $returnType = $member->func->returnType;
            switch ($methodLc) {
                case '__construct':
                    if ($this->hasExplicitReturnType($returnType)) {
                        $this->fatal(
                            $member,
                            "Method {$classDisplay}::__construct() cannot declare a return type"
                        );
                    }
                    break;
                case '__sleep':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isExactArrayType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__sleep(): Return type must be array when declared"
                        );
                    }
                    break;
                case '__wakeup':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__wakeup(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__serialize':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isExactArrayType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__serialize(): Return type must be array when declared"
                        );
                    }
                    break;
                case '__unserialize':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__unserialize(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__clone':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__clone(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__debuginfo':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isArrayOrNullableArrayType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__debugInfo(): Return type must be ?array when declared"
                        );
                    }
                    break;
            }
        }
    }

    private function hasExplicitReturnType(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }

        return !$type instanceof Op\Type\Mixed_;
    }

    private function isExactArrayType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);

        return null !== $sig
            && 'array' === $sig->builtinScalar
            && !$sig->nullable
            && null === $sig->classLc;
    }

    private function isVoidType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);

        return null !== $sig && $sig->void;
    }

    private function isArrayOrNullableArrayType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);

        return null !== $sig
            && 'array' === $sig->builtinScalar
            && null === $sig->classLc;
    }

    private function fatal(Op\Stmt\ClassMethod $method, string $message): void
    {
        throw new CompileFatal(
            $method->getFile(),
            $method->getLine(),
            $message
        );
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
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
