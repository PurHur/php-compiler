<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Compile-time check: generator return type must be a supertype of Generator.
 *
 * php-src: Zend/zend_compile.c — zend_mark_function_as_generator /
 * is_generator_compatible_class_type. Valid when the declared type mask includes
 * object/mixed/iterable, or any named arm is Generator / Iterator / Traversable.
 *
 * Prior: #7351 (:never), #11666 (:void). #26467 extends the same gate to scalars.
 */
final class GeneratorNeverReturnCompileCheck
{
    public const MESSAGE = 'Generator return type must be a supertype of Generator, never given';

    public const VOID_MESSAGE = 'Generator return type must be a supertype of Generator, void given';

    /** @var array<string, true> */
    private const COMPATIBLE_CLASS_NAMES = [
        'generator' => true,
        'iterator' => true,
        'traversable' => true,
    ];

    /** Builtins whose zend type mask includes MAY_BE_OBJECT (or iterable→Traversable). */
    private const COMPATIBLE_LITERALS = [
        'object' => true,
        'mixed' => true,
        'iterable' => true,
    ];

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->functions as $func) {
            $check->validateFunc($func);
        }
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\ClassLike) {
                $check->validateClassLike($child);
            }
        }
    }

    private function validateFunc(Func $func): void
    {
        $invalidType = $this->invalidGeneratorReturnTypeName($func);
        if (null === $invalidType) {
            return;
        }
        if (!$this->cfgContainsYieldOpcode($func->cfg)) {
            return;
        }
        $callable = $func->callableOp;
        throw new CompileFatal(
            $callable instanceof Op ? $callable->getFile() : 'unknown',
            $callable instanceof Op ? $callable->getLine() : 1,
            self::messageForInvalidReturnType($invalidType)
        );
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $invalidType = $this->invalidGeneratorReturnTypeName($member->func);
            if (null === $invalidType) {
                continue;
            }
            if (!$this->methodIsGenerator($member)) {
                continue;
            }
            throw new CompileFatal(
                $member->getFile(),
                $member->getLine(),
                self::messageForInvalidReturnType($invalidType)
            );
        }
    }

    private function methodIsGenerator(Op\Stmt\ClassMethod $method): bool
    {
        return $this->cfgContainsYieldOpcode($method->func->cfg);
    }

    public static function messageForInvalidReturnType(string $typeName): string
    {
        return 'never' === $typeName
            ? self::MESSAGE
            : sprintf('Generator return type must be a supertype of Generator, %s given', $typeName);
    }

    private function invalidGeneratorReturnTypeName(Func $func): ?string
    {
        $returnType = $func->returnType;
        if (null === $returnType) {
            return null;
        }
        if ($this->isGeneratorCompatibleReturnType($returnType)) {
            return null;
        }

        return $this->formatReturnType($returnType);
    }

    /**
     * Mirror zend_mark_function_as_generator's allow check.
     */
    private function isGeneratorCompatibleReturnType(Op\Type $type): bool
    {
        if ($type instanceof Op\Type\Mixed_) {
            return true;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->isGeneratorCompatibleReturnType($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $arm) {
                if ($this->isGeneratorCompatibleReturnType($arm)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Intersection) {
            // ZEND_TYPE_FOREACH: any named Generator/Iterator/Traversable arm is enough.
            foreach ($type->types as $arm) {
                $name = $this->classNameFromType($arm);
                if (null !== $name && isset(self::COMPATIBLE_CLASS_NAMES[strtolower(ltrim($name, '\\'))])) {
                    return true;
                }
            }

            return false;
        }

        return $this->isSingleTypeGeneratorCompatible($type);
    }

    private function isSingleTypeGeneratorCompatible(Op\Type $type): bool
    {
        if ($type instanceof Op\Type\Literal) {
            $lc = strtolower(ltrim($type->name, '\\'));
            if (isset(self::COMPATIBLE_LITERALS[$lc])) {
                return true;
            }

            return isset(self::COMPATIBLE_CLASS_NAMES[$lc]);
        }
        if ($type instanceof Op\Type\Reference) {
            $name = $this->classNameFromType($type);

            return null !== $name && isset(self::COMPATIBLE_CLASS_NAMES[strtolower(ltrim($name, '\\'))]);
        }
        if ($type instanceof Op\Type\Mixed_) {
            return true;
        }

        return false;
    }

    private function classNameFromType(Op\Type $type): ?string
    {
        if ($type instanceof Op\Type\Literal) {
            return $type->name;
        }
        if ($type instanceof Op\Type\Reference) {
            $decl = $type->declaration;
            if ($decl instanceof Operand\Literal && is_string($decl->value)) {
                return $decl->value;
            }
            if ($decl instanceof Operand\Variable) {
                $name = $decl->name;
                if ($name instanceof Operand\Literal && is_string($name->value)) {
                    return $name->value;
                }
            }
        }

        return null;
    }

    /**
     * Approximate zend_type_to_string for the compile-error "%s given" slot.
     */
    private function formatReturnType(Op\Type $type): string
    {
        if ($type instanceof Op\Type\Never_) {
            return 'never';
        }
        if ($type instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($type instanceof Op\Type\Mixed_) {
            return 'mixed';
        }
        if ($type instanceof Op\Type\Literal) {
            return $type->name;
        }
        if ($type instanceof Op\Type\Reference) {
            $name = $this->classNameFromType($type);

            return null !== $name ? ltrim($name, '\\') : 'object';
        }
        if ($type instanceof Op\Type\Nullable) {
            return '?'.$this->formatReturnType($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            $nonNull = [];
            $sawNull = false;
            foreach ($type->types as $arm) {
                if ($arm instanceof Op\Type\Literal && 'null' === strtolower($arm->name)) {
                    $sawNull = true;
                    continue;
                }
                if ($arm instanceof Op\Type\Nullable) {
                    $sawNull = true;
                    $nonNull[] = $arm->subtype;
                    continue;
                }
                $nonNull[] = $arm;
            }
            if ($sawNull && 1 === count($nonNull)) {
                $only = $nonNull[0];
                if (
                    $only instanceof Op\Type\Literal
                    || $only instanceof Op\Type\Reference
                    || $only instanceof Op\Type\Mixed_
                ) {
                    return '?'.$this->formatReturnType($only);
                }
            }
            $parts = [];
            foreach ($type->types as $arm) {
                $parts[] = $this->formatUnionMember($arm);
            }

            return implode('|', $parts);
        }
        if ($type instanceof Op\Type\Intersection) {
            $parts = [];
            foreach ($type->types as $arm) {
                $parts[] = $this->formatReturnType($arm);
            }

            return implode('&', $parts);
        }

        return 'unknown';
    }

    private function formatUnionMember(Op\Type $type): string
    {
        if ($type instanceof Op\Type\Intersection) {
            return '('.$this->formatReturnType($type).')';
        }

        return $this->formatReturnType($type);
    }

    private function cfgContainsYieldOpcode(?CfgBlock $entry): bool
    {
        if (null === $entry) {
            return false;
        }
        $seen = new \SplObjectStorage();
        $queue = [$entry];
        while ([] !== $queue) {
            $block = array_shift($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->children as $op) {
                if ($op instanceof Op\Expr\Yield_ || $op instanceof Op\Expr\YieldFrom) {
                    return true;
                }
                OpSubBlockAccess::enqueueSubBlocks($op, $queue);
            }
        }

        return false;
    }
}
