<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: runtime string ++ (#32435).
 *
 * @group llvm
 */
final class RuntimeStringIncDec32435JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'runtime_string_incdec.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/runtime_string_incdec.phpt',
            'runtime_string_incdec.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
