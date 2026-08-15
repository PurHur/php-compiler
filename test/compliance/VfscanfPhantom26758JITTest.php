<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: vfscanf() phantom — absent from php-src (#26758, re-#6174). */
final class VfscanfPhantom26758JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'vfscanf_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/vfscanf_phantom.phpt',
            'vfscanf_phantom.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
