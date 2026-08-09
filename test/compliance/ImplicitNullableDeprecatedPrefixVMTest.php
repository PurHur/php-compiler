<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: implicit nullable E_DEPRECATED includes callable prefix (#29274).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class ImplicitNullableDeprecatedPrefixVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'implicit_nullable_parameter_deprecated_profile84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/implicit_nullable_parameter_deprecated_profile84.phpt',
            'implicit_nullable_parameter_deprecated_profile84.phpt'
        );
        yield 'implicit_nullable_parameter_file_deprecated_profile84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/implicit_nullable_parameter_file_deprecated_profile84.phpt',
            'implicit_nullable_parameter_file_deprecated_profile84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
