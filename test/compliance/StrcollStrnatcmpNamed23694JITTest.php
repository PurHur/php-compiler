<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: strcoll/strnatcmp Zend stub names + named args (#23694).
 */
final class StrcollStrnatcmpNamed23694JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strcoll_strnatcmp_named_23694.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strcoll_strnatcmp_named_23694.phpt',
            'strcoll_strnatcmp_named_23694.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
