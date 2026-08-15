<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: dirname(null $levels) TypeError under strict_types (#31210). */
final class DirnameNullLevelsStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dirname_null_levels_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dirname_null_levels_strict.phpt',
            'dirname_null_levels_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
