<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: importNode(loadXML documentElement) nodeName (#32350).
 */
final class DomImportNodeXmlNodename32350VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_importnode_xml_nodename.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_importnode_xml_nodename.phpt',
            'dom_importnode_xml_nodename.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
