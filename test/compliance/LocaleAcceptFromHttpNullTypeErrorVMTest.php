<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: locale_accept_from_http / Locale::acceptFromHttp(null) TypeError (#29914). */
final class LocaleAcceptFromHttpNullTypeErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'locale_accept_from_http_null_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/locale_accept_from_http_null_typeerror.phpt',
            'locale_accept_from_http_null_typeerror.phpt'
        );
        yield 'locale_accept_from_http_null_nonstrict.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/locale_accept_from_http_null_nonstrict.phpt',
            'locale_accept_from_http_null_nonstrict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
