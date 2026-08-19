<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array vs runtime/boxed null/bool ordered compare (#32528 leftover of #32520).
 */
final class ArrayRuntimeNullBoolUnlikeCompare32528VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_runtime_null_bool_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_runtime_null_bool_unlike_compare.phpt',
            'array_runtime_null_bool_unlike_compare.phpt'
        );
    }
}
