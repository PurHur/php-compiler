<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DOMElement::removeAttributeNode (ext/dom/element.c).
 */
final class DomRemoveAttributeNodeAotVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_removeattributenode_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_removeattributenode_aot.phpt',
            'dom_removeattributenode_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
