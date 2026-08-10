<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: explicit *(set) + matching set(string $v) remains legal (#29672).
 *
 * Slash-free data-set name so --filter works (path-style JITTest names break the regex).
 *
 * @group llvm
 */
final class PropertyHookAvizTypedSetOkJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'property_hook_aviz_typed_set_ok.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
