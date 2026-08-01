<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\MethodVisibility;

/**
 * Compile-time magic method return type and arity rules (PHP 8.0+).
 *
 * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation
 * (#4988 return types; #25023 zero-parameter magic methods; #25025/#25029 __toString;
 * #26432 __set/__unset void return; #26463 __isset bool return;
 * #26484 __set_state object return)
 */
final class MagicMethodReturnTypeCheck
{
    /**
     * Canonical display names for magic methods that must take zero parameters.
     *
     * @var array<string, string> lowercase => Zend message casing
     */
    private const NO_ARGS_MAGIC = [
        '__wakeup' => '__wakeup',
        '__destruct' => '__destruct',
        '__clone' => '__clone',
        '__serialize' => '__serialize',
        '__debuginfo' => '__debugInfo',
        '__tostring' => '__toString',
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
        $classDisplay = $this->operandDisplayName($class->name, 'class');
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $methodLc = strtolower($member->func->name);
            if (isset(self::NO_ARGS_MAGIC[$methodLc]) && count($member->func->params) > 0) {
                $canonical = self::NO_ARGS_MAGIC[$methodLc];
                $this->fatal(
                    $member,
                    "Method {$classDisplay}::{$canonical}() cannot take arguments"
                );
            }
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
                case '__destruct':
                    if ($this->hasExplicitReturnType($returnType)) {
                        $this->fatal(
                            $member,
                            "Method {$classDisplay}::__destruct() cannot declare a return type"
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
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidOrNeverType($returnType)) {
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
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__unserialize(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__clone':
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__clone(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__set':
                    // php-src zend_check_magic_method_implementation — void (or never) only (#26432)
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__set(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__unset':
                    // php-src zend_check_magic_method_implementation — void (or never) only (#26432)
                    if ($this->hasExplicitReturnType($returnType) && !$this->isVoidOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__unset(): Return type must be void when declared"
                        );
                    }
                    break;
                case '__isset':
                    // php-src zend_check_magic_method_implementation — bool/true/false/never (#26463)
                    if ($this->hasExplicitReturnType($returnType) && !$this->isExactBoolOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__isset(): Return type must be bool when declared"
                        );
                    }
                    break;
                case '__set_state':
                    // php-src zend_check_magic_method_implementation — object/class/self/static/parent/never (#26484)
                    if ($this->hasExplicitReturnType($returnType) && !$this->isExactObjectOrNeverType($returnType)) {
                        $this->fatal(
                            $member,
                            "{$classDisplay}::__set_state(): Return type must be object when declared"
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
                case '__tostring':
                    $this->validateToString($member, $classDisplay, $returnType);
                    break;
            }
        }
    }

    /**
     * Zend: public (or default) visibility; return type string or never when declared.
     *
     * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation (__toString)
     */
    private function validateToString(
        Op\Stmt\ClassMethod $member,
        string $classDisplay,
        ?Op\Type $returnType
    ): void {
        if (0 !== ($member->func->flags & CfgFunc::FLAG_STATIC)) {
            $this->fatal(
                $member,
                "Method {$classDisplay}::__toString() cannot be static"
            );
        }
        if (!MethodVisibility::isPublic(MethodVisibility::mask($member->func->flags))) {
            // Zend emits E_WARNING then the Stringable access-level fatal.
            $this->compileWarning(
                $member,
                "The magic method {$classDisplay}::__toString() must have public visibility"
            );
            $this->fatal(
                $member,
                "Access level to {$classDisplay}::__toString() must be public (as in class Stringable)"
            );
        }
        if ($this->hasExplicitReturnType($returnType) && !$this->isExactStringOrNeverType($returnType)) {
            $this->fatal(
                $member,
                "{$classDisplay}::__toString(): Return type must be string when declared"
            );
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

    private function isVoidOrNeverType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);

        return null !== $sig && ($sig->void || $sig->never);
    }

    private function isArrayOrNullableArrayType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);

        return null !== $sig
            && 'array' === $sig->builtinScalar
            && null === $sig->classLc;
    }

    /**
     * Exact `string` or `never` (Zend accepts never as a bottom return for __toString).
     */
    private function isExactStringOrNeverType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);
        if (null === $sig) {
            return false;
        }
        if ($sig->never) {
            return true;
        }

        return 'string' === $sig->builtinScalar
            && !$sig->nullable
            && null === $sig->classLc
            && null === $sig->unionMembers
            && null === $sig->intersectionMembers;
    }

    /**
     * Exact `bool`, standalone `true`/`false`, or `never` (Zend ZEND_TYPE_CONTAINS_CODE).
     *
     * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation (__isset)
     */
    private function isExactBoolOrNeverType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);
        if (null === $sig) {
            return false;
        }
        if ($sig->never) {
            return true;
        }
        if ($sig->nullable || null !== $sig->unionMembers || null !== $sig->intersectionMembers) {
            return false;
        }
        if ('bool' === $sig->builtinScalar && null === $sig->classLc) {
            return true;
        }
        // PHP 8.2+ true/false standalone types land as class-like names in php-cfg TypeSig.
        return null === $sig->builtinScalar
            && null !== $sig->classLc
            && ('true' === $sig->classLc || 'false' === $sig->classLc);
    }

    /**
     * Exact object-ish return: `object`, named class, `self`/`static`/`parent`, or `never`.
     *
     * php-src: Zend/zend_compile.c — zend_check_magic_method_implementation (__set_state)
     */
    private function isExactObjectOrNeverType(Op\Type $type): bool
    {
        $sig = TypeSig::fromCfgType($type);
        if (null === $sig) {
            return false;
        }
        if ($sig->never) {
            return true;
        }
        if ($sig->nullable || $sig->void
            || null !== $sig->unionMembers
            || null !== $sig->intersectionMembers) {
            return false;
        }
        if ($sig->self || $sig->static) {
            return true;
        }
        if ('object' === $sig->builtinScalar && null === $sig->classLc) {
            return true;
        }
        // Named class (including parent) — not a non-object builtin / true|false literal type.
        if (null !== $sig->builtinScalar || null === $sig->classLc) {
            return false;
        }

        return 'true' !== $sig->classLc && 'false' !== $sig->classLc;
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
