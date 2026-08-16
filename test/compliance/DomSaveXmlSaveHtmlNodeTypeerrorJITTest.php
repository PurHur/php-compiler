<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: saveXML/saveHTML int $node TypeError ?DOMNode (#31396). */
final class DomSaveXmlSaveHtmlNodeTypeerrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_savexml_savehtml_node_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_savexml_savehtml_node_typeerror.phpt',
            'dom_savexml_savehtml_node_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
