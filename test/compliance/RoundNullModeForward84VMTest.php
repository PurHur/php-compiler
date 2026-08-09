<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: round(null $mode) DEP then ValueError on 8.4 (#29384, php-src math.c). */
final class RoundNullModeForward84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'round_null_mode_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/round_null_mode_forward84.phpt',
            'round_null_mode_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
