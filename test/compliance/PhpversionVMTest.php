<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for phpversion/php_sapi_name/php_uname (#3174). */
final class PhpversionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'phpversion.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion.phpt',
            'phpversion.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
