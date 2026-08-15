<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_pad() oversize ValueError wording (#29342).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayPadOversizeValueError29342VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_pad_oversize_valueerror_29342.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_pad_oversize_valueerror_29342.phpt',
            'array_pad_oversize_valueerror_29342.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
