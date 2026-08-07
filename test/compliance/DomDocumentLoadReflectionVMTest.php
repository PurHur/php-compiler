<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for DOMDocument::load* Reflection stubs (#28713). */
final class DomDocumentLoadReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'dom_document_load_reflection_bool.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/dom/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
