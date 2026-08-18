<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: extract(['this'=>1]) throws Cannot re-assign $this (#32226, array.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ExtractThis32226VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'extract_this_reassign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/extract_this_reassign.phpt',
            'extract_this_reassign.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
