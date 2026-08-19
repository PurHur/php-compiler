<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DOMElement::getElementsByTagNameNS descendants (ext/dom/element.c) (#32511).
 */
final class DomElementGetElementsByTagNameNsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_element_gebtns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_element_gebtns.phpt',
            'dom_element_gebtns.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
