<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMXPath::registerNamespace null TypeError under strict_types (#30301). */
final class DomXpathRegisterNamespaceNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_register_namespace_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_register_namespace_null_strict.phpt',
            'dom_xpath_register_namespace_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
