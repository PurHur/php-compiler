<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * attribute_exists() — probe whether a class declares a given attribute (#6468, #16844).
 *
 * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(attribute_exists)
 */
final class attribute_exists_ extends Internal
{
    private const OBJECT_TYPE_ERROR =
        'attribute_exists(): Argument #2 ($object) must be of type object|string, %s given';

    public function __construct()
    {
        parent::__construct('attribute_exists');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('attribute_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $attribute = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'attribute_exists', 0, 'attribute');
        self::requireValidObjectArg($frame->calledArgs[1]->resolveIndirect());
        $exists = VmReflection::attributeExistsForObjectOrClass($ctx, $frame->calledArgs[1], $attribute);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('attribute_exists() requires exactly two arguments');
        }

        return JitAttributeExists::invoke($context, $args[0], $args[1]);
    }

    private static function requireValidObjectArg(Variable $objectOrClass): void
    {
        if (Variable::TYPE_STRING === $objectOrClass->type
            || Variable::TYPE_OBJECT === $objectOrClass->type
            || Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return;
        }
        throw new \TypeError(\sprintf(self::OBJECT_TYPE_ERROR, self::vmTypeName($objectOrClass->type)));
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
