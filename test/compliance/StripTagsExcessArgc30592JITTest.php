<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: strip_tags() excess argc → ArgumentCountError (#30592). */
final class StripTagsExcessArgc30592JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_strip_tags_30592_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_strip_tags_30592_jit.phpt',
            'excess_argc_strip_tags_30592_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
