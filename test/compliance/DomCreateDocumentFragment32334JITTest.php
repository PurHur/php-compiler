<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createDocumentFragment saveXML (#32334).
 *
 * @group llvm
 */
final class DomCreateDocumentFragment32334JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createdocumentfragment_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createdocumentfragment_savexml.phpt',
            'dom_createdocumentfragment_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
