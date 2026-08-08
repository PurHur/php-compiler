<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\Variable;

/**
 * mb_convert_variables() recursive in-place encoding conversion (php-src ext/mbstring/mbstring.c).
 */
final class VmMbConvertVariables
{
    /**
     * @param list<string> $fromEncodings non-empty resolved from-encoding candidates
     *
     * @return string|false detected from encoding on success
     */
    public static function convert(Frame $frame, string $to, array $fromEncodings, array $vars): string|false
    {
        if ([] === $fromEncodings) {
            return false;
        }
        $detected = null;
        foreach ($vars as $var) {
            $used = self::convertVariable($frame, $var, $to, $fromEncodings);
            if (false === $used) {
                return false;
            }
            if (null !== $used) {
                $detected = $used;
            }
        }

        return $detected ?? $fromEncodings[0];
    }

    /**
     * @param list<string> $fromEncodings
     *
     * @return string|false|null detected encoding, false on failure, null when no string converted
     */
    private static function convertVariable(
        Frame $frame,
        Variable $target,
        string $to,
        array $fromEncodings
    ): string|false|null {
        $resolved = $target->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type) {
            [$converted, $from] = self::convertString($resolved->toString(), $to, $fromEncodings);
            if (false === $converted) {
                return false;
            }
            $replacement = new Variable();
            $replacement->string($converted);
            $target->copyFrom($replacement);

            return $from;
        }
        if (Variable::TYPE_ARRAY === $resolved->type) {
            $resolved->separateArrayForWrite();

            return self::convertArray($frame, $resolved->toArray(), $to, $fromEncodings);
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return self::convertObject($frame, $resolved->toObject(), $to, $fromEncodings);
        }

        return null;
    }

    /**
     * @param list<string> $fromEncodings
     *
     * @return string|false|null
     */
    private static function convertArray(
        Frame $frame,
        HashTable $table,
        string $to,
        array $fromEncodings
    ): string|false|null {
        $detected = null;
        foreach ($table->iterateKeyed(true) as [, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                $used = self::convertArray($frame, $value->toArray(), $to, $fromEncodings);
            } elseif (Variable::TYPE_OBJECT === $value->type) {
                $used = self::convertObject($frame, $value->toObject(), $to, $fromEncodings);
            } elseif (Variable::TYPE_STRING === $value->type) {
                [$converted, $from] = self::convertString($value->toString(), $to, $fromEncodings);
                if (false === $converted) {
                    return false;
                }
                $value->string($converted);
                $used = $from;
            } else {
                continue;
            }
            if (false === $used) {
                return false;
            }
            if (null !== $used) {
                $detected = $used;
            }
        }

        return $detected;
    }

    /**
     * @param list<string> $fromEncodings
     *
     * @return string|false|null
     */
    private static function convertObject(
        Frame $frame,
        ObjectEntry $object,
        string $to,
        array $fromEncodings
    ): string|false|null {
        $detected = null;
        $vm = $frame->vmContext->runtime->vm();
        $iterator = new ObjectPropertyIterator($object, $vm, $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $value = $iterator->currentValue(true);
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                $used = self::convertArray($frame, $value->toArray(), $to, $fromEncodings);
            } elseif (Variable::TYPE_OBJECT === $value->type) {
                $used = self::convertObject($frame, $value->toObject(), $to, $fromEncodings);
            } elseif (Variable::TYPE_STRING === $value->type) {
                [$converted, $from] = self::convertString($value->toString(), $to, $fromEncodings);
                if (false === $converted) {
                    return false;
                }
                $value->string($converted);
                $used = $from;
            } else {
                continue;
            }
            if (false === $used) {
                return false;
            }
            if (null !== $used) {
                $detected = $used;
            }
        }

        return $detected;
    }

    /**
     * @param list<string> $fromEncodings
     *
     * @return array{0: string|false, 1: string|null}
     */
    private static function convertString(string $source, string $to, array $fromEncodings): array
    {
        foreach ($fromEncodings as $from) {
            $converted = VmMbstring::convertEncoding($source, $to, $from);
            if (false !== $converted) {
                return [$converted, $from];
            }
        }

        return [false, null];
    }

    /**
     * @return list<string>
     */
    public static function coerceFromEncodingList(Variable $var, string $function, int $argIndex): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            $from = VmMbstring::coerceEncodingString($var, $function, $argIndex);
            self::assertSupportedFromEncoding($from, $function, $argIndex);

            return [$from];
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $list = [];
            foreach ($var->toArray()->iterateKeyed() as [, $elem]) {
                $elem = $elem->resolveIndirect();
                if (Variable::TYPE_STRING !== $elem->type) {
                    throw new \TypeError(\sprintf(
                        '%s(): Argument #%d ($from_encoding) must be of type array|string, %s given',
                        $function,
                        $argIndex + 1,
                        self::typeLabel($elem)
                    ));
                }
                $enc = VmMbstring::coerceEncodingString($elem, $function, $argIndex);
                self::assertSupportedFromEncoding($enc, $function, $argIndex);
                $list[] = $enc;
            }
            if ([] === $list) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($from_encoding) contains invalid encoding ""',
                    $function,
                    $argIndex + 1
                ));
            }

            return $list;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($from_encoding) must be of type array|string, %s given',
            $function,
            $argIndex + 1,
            self::typeLabel($var)
        ));
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    private static function assertSupportedFromEncoding(string $from, string $function, int $argIndex): void
    {
        if (VmMbstring::isMbConvertPseudoEncoding($from)) {
            return;
        }
        if (null === \PHPCompiler\ext\iconv\CharsetEngine::parseEncodingSpec($from)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($from_encoding) contains invalid encoding "%s"',
                $function,
                $argIndex + 1,
                $from
            ));
        }
    }
}
