<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * array_multisort() argument parsing — Sorting enum + SORT_* flags (#7229, #3532).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(array_multisort)
 */
final class VmArraySort
{
    private const PARSE_ORDER = 0;
    private const PARSE_TYPE = 1;

    /**
     * @param list<Variable> $args frame calledArgs (by-ref slots)
     *
     * @return list<array{array: Variable, sortOrder: int, sortType: int}>
     */
    public static function parseMultisortEntries(array $args): array
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_multisort() expects at least 1 argument, 0 given');
        }

        $entries = [];
        $sortOrder = StdlibConstants::SORT_ASC;
        $sortType = StdlibConstants::SORT_REGULAR;
        $parseState = [self::PARSE_ORDER => 0, self::PARSE_TYPE => 0];

        for ($i = 0; $i < $argc; ++$i) {
            $arg = $args[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $arg->type) {
                if ($i > 0 && \count($entries) > 0) {
                    $last = \count($entries) - 1;
                    $entries[$last]['sortOrder'] = $sortOrder;
                    $entries[$last]['sortType'] = $sortType;
                }
                $entries[] = [
                    'array' => $args[$i],
                    'sortOrder' => StdlibConstants::SORT_ASC,
                    'sortType' => StdlibConstants::SORT_REGULAR,
                ];
                $sortOrder = StdlibConstants::SORT_ASC;
                $sortType = StdlibConstants::SORT_REGULAR;
                $parseState = [self::PARSE_ORDER => 1, self::PARSE_TYPE => 1];
                continue;
            }
            self::consumeMultisortFlag($arg, $i, $sortOrder, $sortType, $parseState);
        }

        if (\count($entries) < 1) {
            throw new \LogicException(
                'array_multisort() requires at least one array argument in this compiler build'
            );
        }

        $last = \count($entries) - 1;
        $entries[$last]['sortOrder'] = $sortOrder;
        $entries[$last]['sortType'] = $sortType;

        return $entries;
    }

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

    /**
     * @param array{0: int, 1: int} $parseState
     */
    private static function consumeMultisortFlag(
        Variable $arg,
        int $argIndex,
        int &$sortOrder,
        int &$sortType,
        array &$parseState
    ): void {
        $fromEnum = self::trySortingOrderInt($arg);
        if (null !== $fromEnum) {
            if (0 === $parseState[self::PARSE_ORDER]) {
                throw new \TypeError(self::multisortOperandTypeError(
                    $argIndex,
                    ' that has not already been specified'
                ));
            }
            $sortOrder = $fromEnum;
            $parseState[self::PARSE_ORDER] = 0;

            return;
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(self::multisortOperandTypeError($argIndex));
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(self::multisortOperandTypeError($argIndex));
        }

        $val = $arg->toInt();
        $masked = $val & ~StdlibConstants::SORT_FLAG_CASE;
        switch ($masked) {
            case StdlibConstants::SORT_ASC:
            case StdlibConstants::SORT_DESC:
                if (0 === $parseState[self::PARSE_ORDER]) {
                    throw new \TypeError(self::multisortOperandTypeError(
                        $argIndex,
                        ' that has not already been specified'
                    ));
                }
                $sortOrder = $masked;
                $parseState[self::PARSE_ORDER] = 0;
                break;

            case StdlibConstants::SORT_REGULAR:
            case StdlibConstants::SORT_NUMERIC:
            case StdlibConstants::SORT_STRING:
            case StdlibConstants::SORT_NATURAL:
            case StdlibConstants::SORT_LOCALE_STRING:
                if (0 === $parseState[self::PARSE_TYPE]) {
                    throw new \TypeError(self::multisortOperandTypeError(
                        $argIndex,
                        ' that has not already been specified'
                    ));
                }
                $sortType = $val;
                $parseState[self::PARSE_TYPE] = 0;
                break;

            default:
                throw new \ValueError(sprintf(
                    'array_multisort(): Argument #%d must be a valid sort flag',
                    $argIndex + 1
                ));
        }
    }

    private static function isSortingEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'Sorting');
    }

    public static function multisortOperandTypeError(int $argIndex, string $extra = ''): string
    {
        $argNum = $argIndex + 1;
        if (1 === $argNum) {
            return 'array_multisort(): Argument #1 ($array) must be an array or a sort flag'.$extra;
        }

        return sprintf(
            'array_multisort(): Argument #%d must be an array or a sort flag%s',
            $argNum,
            $extra
        );
    }
}
