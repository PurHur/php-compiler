<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: hashtable vs hashtable ordered compare / == (#32524 leftover of #32501).
 */
final class HashtableOrderedCompare32524VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hashtable_ordered_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/hashtable_ordered_compare.phpt',
            'hashtable_ordered_compare.phpt'
        );
    }
}
