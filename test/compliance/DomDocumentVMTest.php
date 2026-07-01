<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/dom DOMDocument tree APIs (#14335, #14336). */
final class DomDocumentVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_node_tree_nav.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_node_tree_nav.phpt',
            'dom_node_tree_nav.phpt'
        );
        yield 'dom_get_elements_by_tag_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_get_elements_by_tag_name.phpt',
            'dom_get_elements_by_tag_name.phpt'
        );
        yield 'domdocument_loadxml.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/domdocument_loadxml.phpt',
            'domdocument_loadxml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
