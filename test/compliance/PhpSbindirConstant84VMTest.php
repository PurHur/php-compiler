<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for PHP_SBINDIR Core path constant PROFILE≥8.4 (#28170, main/main.c).
 */
final class PhpSbindirConstant84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'php_sbindir_constant_forward_profile.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/php_sbindir_constant_forward_profile.phpt',
            'php_sbindir_constant_forward_profile.phpt'
        );
        yield 'php_sbindir_constant_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/php_sbindir_constant_phantom.phpt',
            'php_sbindir_constant_phantom.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
