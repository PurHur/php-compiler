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
        yield 'phpversion_extension.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion_extension.phpt',
            'phpversion_extension.phpt'
        );
        yield 'phpversion_builtin_ext.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion_builtin_ext.phpt',
            'phpversion_builtin_ext.phpt'
        );
        yield 'phpversion_xml.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion_xml.phpt',
            'phpversion_xml.phpt'
        );
        yield 'phpversion_reflection_stub.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion_reflection_stub.phpt',
            'phpversion_reflection_stub.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
