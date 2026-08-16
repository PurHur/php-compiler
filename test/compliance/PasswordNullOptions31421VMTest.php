<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: password_* null $options → TypeError (#31421).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PasswordNullOptions31421VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'password_null_options_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/password_null_options_typeerror.phpt',
            'password_null_options_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
