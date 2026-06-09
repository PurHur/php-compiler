<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Sort order resolution for array_multisort() — Sorting enum + legacy SORT_* ints (#7229).
 *
 * php-src: ext/standard/basic_functions.stub.php — enum Sorting: int
 */
final class VmArraySort
{
    public static function resolveMultisortOrderArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::trySortingOrderInt($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                'array_multisort(): Argument must be of type array|int|Sorting, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        throw new \LogicException(
            'array_multisort() arguments must be arrays or SORT_* order flags in this compiler build'
        );
    }

    public static function trySortingOrderInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isSortingEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('Sorting case missing backing value');
        }

        return self::sortingOrderFromBacking($entry->backingValue->resolveIndirect()->toInt());
    }

    public static function sortingOrderFromBacking(int $backing): int
    {
        return match ($backing) {
            StdlibConstants::SORT_ASC, StdlibConstants::SORT_DESC => $backing,
            default => throw new \ValueError('Invalid Sorting enum value '.$backing),
        };
    }

    private static function isSortingEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'Sorting');
    }
}
