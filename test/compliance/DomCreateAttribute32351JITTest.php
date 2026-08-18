<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createAttribute saveXML (#32351).
 *
 * @group llvm
 */
final class DomCreateAttribute32351JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createattribute_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createattribute_savexml.phpt',
            'dom_createattribute_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
