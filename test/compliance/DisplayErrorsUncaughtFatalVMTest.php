<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for display_errors uncaught fatal stdout mirror (#18561). */
final class DisplayErrorsUncaughtFatalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'display_errors_uncaught_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/display_errors_uncaught_fatal.phpt',
            'display_errors_uncaught_fatal.phpt'
        );
        yield 'display_errors_uncaught_fatal_off.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/display_errors_uncaught_fatal_off.phpt',
            'display_errors_uncaught_fatal_off.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
