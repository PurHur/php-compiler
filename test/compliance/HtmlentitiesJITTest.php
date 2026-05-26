<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for htmlentities(). */
final class HtmlentitiesJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlentities_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlentities_jit.phpt',
            'htmlentities_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
