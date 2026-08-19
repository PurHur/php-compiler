<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOMElement::getElementsByTagName descendants (ext/dom/element.c) (#32454).
 *
 * @group llvm
 */
final class DomElementGetElementsByTagNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_element_gebtn.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_element_gebtn.phpt',
            'dom_element_gebtn.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
