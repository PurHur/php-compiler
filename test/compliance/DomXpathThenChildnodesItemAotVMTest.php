<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMXPath::query() before childNodes->item() (#32620). */
final class DomXpathThenChildnodesItemAotVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_then_childnodes_item_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_then_childnodes_item_aot.phpt',
            'dom_xpath_then_childnodes_item_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
