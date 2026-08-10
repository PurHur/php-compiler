<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for pecl-stats cdf_* family (#29588). */
final class StatsCdfTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stats_cdf_family.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stats_cdf_family.phpt',
            'stats_cdf_family.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
