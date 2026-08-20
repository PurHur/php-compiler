<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DOMElement::insertAdjacentElement/Text (ext/dom/php_dom.c).
 */
final class DomInsertAdjacentAotVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_insert_adjacent_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_insert_adjacent_aot.phpt',
            'dom_insert_adjacent_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
