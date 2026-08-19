<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array vs null/bool ordered compare (#32520 leftover of #32503).
 */
final class ArrayNullBoolUnlikeCompare32520VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_null_bool_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_null_bool_unlike_compare.phpt',
            'array_null_bool_unlike_compare.phpt'
        );
    }
}
