<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: locale_set_default / Locale::setDefault(null) TypeError (#29932). */
final class LocaleSetDefaultNullTypeErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'locale_set_default_null_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/locale_set_default_null_typeerror.phpt',
            'locale_set_default_null_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
