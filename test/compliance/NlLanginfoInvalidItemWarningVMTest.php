<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for nl_langinfo() invalid-item warning (#29459). */
final class NlLanginfoInvalidItemWarningVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nl_langinfo.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/nl_langinfo.phpt',
            'nl_langinfo.phpt'
        );
        yield 'nl_langinfo_invalid_item_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/nl_langinfo_invalid_item_warning.phpt',
            'nl_langinfo_invalid_item_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
