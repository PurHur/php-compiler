<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: unpack() Q short-input warning text under PROFILE=8.4 (#29484).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class UnpackQShortInputWarning29484VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unpack_q_short_input_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/unpack_q_short_input_warning.phpt',
            'unpack_q_short_input_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
