<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for pecl-stats rand_* family (#29589, #29622, #29649, #29684). */
final class StatsRandTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stats_rand_family.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stats_rand_family.phpt',
            'stats_rand_family.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
