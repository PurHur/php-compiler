<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: object/array vs scalar ordered compare (#32503).
 */
final class ObjectArrayUnlikeCompare32503VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_array_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_array_unlike_compare.phpt',
            'object_array_unlike_compare.phpt'
        );
    }
}
