<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: file_get_contents/file_put_contents ArgumentCountError wording (#30677). */
final class FgcFpcExcessArgc30677VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_file_get_put_contents_30677.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_file_get_put_contents_30677.phpt',
            'excess_argc_file_get_put_contents_30677.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
