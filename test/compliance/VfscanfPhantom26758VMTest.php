<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: vfscanf() phantom — absent from php-src (#26758, re-#6174).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class VfscanfPhantom26758VMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
