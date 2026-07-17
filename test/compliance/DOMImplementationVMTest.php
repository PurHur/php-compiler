<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/dom DOMImplementation (#6140). */
final class DOMImplementationVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'implementation_create_document.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/implementation_create_document.phpt',
            'implementation_create_document.phpt'
        );
        yield 'implementation_create_document_type_optional_args.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/implementation_create_document_type_optional_args.phpt',
            'implementation_create_document_type_optional_args.phpt'
        );
        yield 'document_type_properties.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/document_type_properties.phpt',
            'document_type_properties.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
