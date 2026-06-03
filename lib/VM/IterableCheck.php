<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend iterable pseudo-type: array or Traversable (zend_type.c IS_ITERABLE, #4829).
 */
final class IterableCheck
{
    /** Zend zend_verify_arg_type() wording for iterable parameters. */
    public const TYPE_LABEL = 'Traversable|array';

    private const TRAVERSABLE_IFACES = ['traversable', 'iterator', 'iteratoraggregate'];

    public static function isIterable(Variable $value, Context $context): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }
        $entry = $value->toObject();
        if (null !== $entry->generatorState) {
            return true;
        }
        $class = $entry->class;
        foreach (self::TRAVERSABLE_IFACES as $ifaceLc) {
            if (InterfaceCheck::entryImplements($class, $ifaceLc, $context)) {
                return true;
            }
        }

        return ForeachIterator::entryImplementsIteratorProtocol($class, $context);
    }

    public static function valueTypeName(Variable $value): string
    {
        return match ($value->resolveIndirect()->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    public static function assertParameter(Variable $value, Context $context, string $kind = 'Argument'): void
    {
        if (self::isIterable($value, $context)) {
            return;
        }

        throw new \TypeError(sprintf(
            '%s must be of type %s, %s given',
            $kind,
            self::TYPE_LABEL,
            self::valueTypeName($value)
        ));
    }
}
