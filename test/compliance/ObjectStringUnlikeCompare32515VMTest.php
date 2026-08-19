<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: object vs string ordered compare / == (#32515 leftover of #32503).
 */
final class ObjectStringUnlikeCompare32515VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_string_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_string_unlike_compare.phpt',
            'object_string_unlike_compare.phpt'
        );
    }
}
