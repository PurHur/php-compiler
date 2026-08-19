<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOMImplementation::createDocument xmlNewDoc (ext/dom/php_dom.c).
 *
 * @group llvm
 */
final class DomImplementationCreateDocumentJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_implementation_createdocument.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_implementation_createdocument.phpt',
            'dom_implementation_createdocument.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
