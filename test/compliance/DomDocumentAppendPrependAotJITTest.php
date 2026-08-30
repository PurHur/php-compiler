<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOMDocument ParentNode::append/prepend AOT segfault (#35801). */
final class DomDocumentAppendPrependAotJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_document_append_prepend_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_document_append_prepend_aot.phpt',
            'dom_document_append_prepend_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
