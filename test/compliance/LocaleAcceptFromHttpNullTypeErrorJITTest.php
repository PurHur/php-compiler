<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: locale_accept_from_http / Locale::acceptFromHttp(null) TypeError (#29914). */
final class LocaleAcceptFromHttpNullTypeErrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'locale_accept_from_http_null_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/locale_accept_from_http_null_typeerror_jit.phpt',
            'locale_accept_from_http_null_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
