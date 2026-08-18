<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createEntityReference saveXML (#32343).
 *
 * @group llvm
 */
final class DomCreateEntityReference32343JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createentityreference_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createentityreference_savexml.phpt',
            'dom_createentityreference_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
