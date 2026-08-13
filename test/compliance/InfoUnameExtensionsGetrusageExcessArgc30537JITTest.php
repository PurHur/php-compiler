<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: php_uname/get_loaded_extensions/getrusage excess argc → ArgumentCountError (#30537). */
final class InfoUnameExtensionsGetrusageExcessArgc30537JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_info_uname_extensions_getrusage_30537_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_info_uname_extensions_getrusage_30537_jit.phpt',
            'excess_argc_info_uname_extensions_getrusage_30537_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
