<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMDocument::getElementsByTagName(null) TypeError under strict_types (#29959). */
final class DomGetElementsByTagNameNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_getelementsbytagname_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_getelementsbytagname_null_strict.phpt',
            'dom_getelementsbytagname_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
