<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: warnings inside functions/methods/closures cite inner opline (#32040).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class NestedFrameWarningLine32040VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nested_frame_warning_line.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nested_frame_warning_line.phpt',
            'nested_frame_warning_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
