<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFileInfo stat/predicate excess argc (#31000). */
final class SplFileInfoStatExcessArgc31000VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splfileinfo_stat_excess_argc_31000.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splfileinfo_stat_excess_argc_31000.phpt',
            'splfileinfo_stat_excess_argc_31000.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
