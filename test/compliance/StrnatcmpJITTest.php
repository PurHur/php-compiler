<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for strnatcmp() / strnatcasecmp(). */
final class StrnatcmpJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strnatcmp_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strnatcmp_jit.phpt',
            'strnatcmp_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
