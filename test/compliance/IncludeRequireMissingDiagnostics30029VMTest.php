<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: missing include/require Zend two-step diagnostics (#30029, fopen_wrappers.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class IncludeRequireMissingDiagnostics30029VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'include_require_missing_diagnostics.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/include_require_missing_diagnostics.phpt',
            'include_require_missing_diagnostics.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
