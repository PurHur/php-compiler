<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: setcookie null $expires_or_options under strict_types → TypeError (#31229).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SetcookieNullExpires31229VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'setcookie_null_expires_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/setcookie_null_expires_strict.phpt',
            'setcookie_null_expires_strict.phpt'
        );
        yield 'setcookie_null_expires_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/setcookie_null_expires_soft_dep.phpt',
            'setcookie_null_expires_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
