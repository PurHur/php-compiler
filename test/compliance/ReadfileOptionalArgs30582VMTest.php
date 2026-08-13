<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: readfile() optional args + excess argc (#30582). */
final class ReadfileOptionalArgs30582VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_readfile_30582.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_readfile_30582.phpt',
            'excess_argc_readfile_30582.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
