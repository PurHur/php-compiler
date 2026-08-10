<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for pecl-stats dens_* family (#29587). */
final class StatsDensTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stats_dens_family.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stats_dens_family.phpt',
            'stats_dens_family.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
