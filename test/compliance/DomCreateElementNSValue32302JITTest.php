<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createElementNS($ns, $name, $value) textContent (#32302).
 *
 * @group llvm
 */
final class DomCreateElementNSValue32302JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createelementns_value_textcontent.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createelementns_value_textcontent.phpt',
            'dom_createelementns_value_textcontent.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
