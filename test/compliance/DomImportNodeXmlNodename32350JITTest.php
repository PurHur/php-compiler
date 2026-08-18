<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: importNode(loadXML documentElement) nodeName (#32350).
 *
 * @group llvm
 */
final class DomImportNodeXmlNodename32350JITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
