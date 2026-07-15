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
        return EnumCaseSupport::typeNameForVariable($value);
    }

    public static function assertParameter(Variable $value, Context $context, string $kind = 'Argument'): void
    {
        if (self::isIterable($value, $context)) {
            return;
        }

        $ctx = TypeCheck::currentParamErrorContext();
        if (null !== $ctx && 'Argument' === $kind) {
            $ctx->throwExpectedType(self::TYPE_LABEL, $value);
        }

        throw new \TypeError(sprintf(
            '%s must be of type %s, %s given',
            $kind,
            self::TYPE_LABEL,
            self::valueTypeName($value)
        ));
    }
}
