<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: Uri ASCII host lowercasing (#28197).
 */
final class UriHostCaseVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'host_ascii_case.phpt';
        yield 'uri/'.$file => self::parsePHPT(
            __DIR__.'/cases/uri/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
