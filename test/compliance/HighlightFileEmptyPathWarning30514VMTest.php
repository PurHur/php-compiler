<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: highlight_file/show_source empty path E_WARNING then ValueError (#30514). */
final class HighlightFileEmptyPathWarning30514VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'highlight_file_empty_path_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/highlight_file_empty_path_warning.phpt',
            'highlight_file_empty_path_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
