<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: NamedNodeMap length/item xmlNode->properties (ext/dom/namednodemap.c) (#32546).
 *
 * @group llvm
 */
final class DomNamedNodeMapLengthJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_namednodemap_length.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_namednodemap_length.phpt',
            'dom_namednodemap_length.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
