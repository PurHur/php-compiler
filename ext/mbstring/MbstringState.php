<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * mbstring global encoding state (php-src ext/mbstring/mbstring.c MBSTRG; #13100).
 */
final class MbstringState
{
    private const MODE_CHAR = 0;
    private const MODE_NONE = 1;
    private const MODE_LONG = 2;
    private const MODE_ENTITY = 3;

    private static string $httpOutput = 'UTF-8';

    /** @var list<string> */
    private static array $detectOrder = ['ASCII', 'UTF-8'];

    private static int $substituteMode = self::MODE_CHAR;

    private static int $substituteCodepoint = 63;

    public static function httpOutput(?string $encoding = null): string|bool
    {
        if (null === $encoding) {
            return self::$httpOutput;
        }
        $canonical = MbstringEncodingRegistry::assertValid($encoding, 'mb_http_output', 0);
        self::$httpOutput = $canonical;

        return true;
    }

    /**
     * @return list<string>|bool
     */
    public static function detectOrder(null|string|Variable $order = null): array|bool
    {
        if (null === $order) {
            return self::$detectOrder;
        }
        if ($order instanceof Variable) {
            $order = self::parseDetectOrderVariable($order);
        } elseif (\is_string($order)) {
            $order = MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $order);
        }
        self::$detectOrder = $order;

        return true;
    }

  /**
     * @return int|string|bool
     */
    public static function substituteCharacter(null|int|string|Variable $substchar = null): int|string|bool
    {
        if (null === $substchar) {
            return match (self::$substituteMode) {
                self::MODE_NONE => 'none',
                self::MODE_LONG => 'long',
                self::MODE_ENTITY => 'entity',
                default => self::$substituteCodepoint,
            };
        }
        if ($substchar instanceof Variable) {
            return self::setSubstituteFromVariable($substchar);
        }
        if (\is_string($substchar)) {
            return self::setSubstituteFromString($substchar);
        }

        return self::setSubstituteFromCodepoint($substchar);
    }

    public static function hashTableFromStringList(array $strings): HashTable
    {
        $ht = new HashTable();
        foreach ($strings as $value) {
            $var = new Variable();
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }

    /**
     * @return list<string>
     */
    private static function parseDetectOrderVariable(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $var->toString());
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                self::typeLabel($var)
            ));
        }

        $order = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($elem)) {
                throw new \TypeError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                    EnumCaseSupport::typeNameForVariable($elem)
                ));
            }
            if (Variable::TYPE_STRING !== $elem->type) {
                throw new \TypeError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                    self::typeLabel($elem)
                ));
            }
            $canonical = MbstringEncodingRegistry::resolve($elem->toString());
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $elem->toString()
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }

    private static function setSubstituteFromVariable(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return self::setSubstituteFromCodepoint($var->toInt());
        }
        if (Variable::TYPE_STRING === $var->type) {
            return self::setSubstituteFromString($var->toString());
        }

        throw new \TypeError(sprintf(
            'mb_substitute_character(): Argument #1 ($substchar) must be of type int|string|null, %s given',
            self::typeLabel($var)
        ));
    }

    private static function setSubstituteFromString(string $value): bool
    {
        if (0 === strcasecmp($value, 'none')) {
            self::$substituteMode = self::MODE_NONE;

            return true;
        }
        if (0 === strcasecmp($value, 'long')) {
            self::$substituteMode = self::MODE_LONG;

            return true;
        }
        if (0 === strcasecmp($value, 'entity')) {
            self::$substituteMode = self::MODE_ENTITY;

            return true;
        }

        throw new \ValueError(
            'mb_substitute_character(): Argument #1 ($substchar) must be "none", "long", "entity" or a valid codepoint'
        );
    }

    private static function setSubstituteFromCodepoint(int $codepoint): bool
    {
        if (!self::isValidCodepoint($codepoint)) {
            throw new \ValueError(
                'mb_substitute_character(): Argument #1 ($substchar) is not a valid codepoint'
            );
        }
        self::$substituteMode = self::MODE_CHAR;
        self::$substituteCodepoint = $codepoint;

        return true;
    }

    private static function isValidCodepoint(int $cp): bool
    {
        if ($cp < 0 || $cp >= 0x110000) {
            return false;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }

        return true;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
