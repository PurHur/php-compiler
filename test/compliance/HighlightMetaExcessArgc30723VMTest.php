<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: highlight_string/get_meta_tags ArgumentCountError wording (#30723). */
final class HighlightMetaExcessArgc30723VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_highlight_meta_30723.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_highlight_meta_30723.phpt',
            'excess_argc_highlight_meta_30723.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
