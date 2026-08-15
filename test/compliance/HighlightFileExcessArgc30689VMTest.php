<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: highlight_file/show_source ArgumentCountError wording (#30689). */
final class HighlightFileExcessArgc30689VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_highlight_file_30689.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_highlight_file_30689.phpt',
            'excess_argc_highlight_file_30689.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
