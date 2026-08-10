<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM loadXML/loadHTML + XPath query/evaluate null TypeError under strict_types (#30041). */
final class DomLoadXpathNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_load_xpath_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_load_xpath_null_strict.phpt',
            'dom_load_xpath_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
