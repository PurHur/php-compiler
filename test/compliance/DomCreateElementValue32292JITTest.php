<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createElement($name, $value) textContent (#32292).
 *
 * @group llvm
 */
final class DomCreateElementValue32292JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createelement_value_textcontent.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createelement_value_textcontent.phpt',
            'dom_createelement_value_textcontent.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
