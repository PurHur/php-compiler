<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for str_ireplace(). */
final class StrIreplaceJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_ireplace_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_ireplace_jit.phpt',
            'str_ireplace_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
