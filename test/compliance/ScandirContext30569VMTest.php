<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: scandir() optional context (#30569). */
final class ScandirContext30569VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'scandir_context_30569.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/scandir_context_30569.phpt',
            'scandir_context_30569.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
