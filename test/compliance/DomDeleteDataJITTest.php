<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: deleteData xmlUTF8Strsub (#32389, ext/dom/characterdata.c).
 *
 * @group llvm
 */
final class DomDeleteDataJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_deletedata.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_deletedata.phpt',
            'dom_deletedata.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
