<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * stream_context_create/set_options wrapper-option shape validation (ext/standard/streams.c).
 *
 * php-src: parse_context_options() — top-level values must be wrapper option arrays.
 */
final class VmStreamContextOptions
{
    public const WRAPPER_SHAPE_VALUE_ERROR =
        'Options should have the form ["wrappername"]["optionname"] = $value';

    public static function validateOptionsVariable(?Variable $optionsVar, string $functionName): void
    {
        if (null === $optionsVar) {
            return;
        }
        $resolved = $optionsVar->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            return;
        }
        self::validateOptionsHashTable($resolved->toArray(), $functionName);
    }

    public static function validateOptionsHashTable(?HashTable $options, string $functionName): void
    {
        if (null === $options) {
            return;
        }
        foreach ($options->iterateKeyed(true) as [, $valueVar]) {
            $value = $valueVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $value->type) {
                throw new \ValueError(self::WRAPPER_SHAPE_VALUE_ERROR);
            }
        }
    }
}
