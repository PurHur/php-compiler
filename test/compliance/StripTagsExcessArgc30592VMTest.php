<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: strip_tags() excess argc → ArgumentCountError (#30592). */
final class StripTagsExcessArgc30592VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_strip_tags_30592.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_strip_tags_30592.phpt',
            'excess_argc_strip_tags_30592.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
