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
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
