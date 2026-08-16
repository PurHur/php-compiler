<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: gzcompress/gzencode/gzdeflate null $level soft Deprecated (#31445).
 */
final class GzNullLevelSoftJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gz_null_level_soft_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gz_null_level_soft_jit.phpt',
            'gz_null_level_soft_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
