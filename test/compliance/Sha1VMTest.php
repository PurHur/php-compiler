<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for sha1() (#2160). */
final class Sha1VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sha1.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sha1.phpt',
            'sha1.phpt'
        );
    }
}
