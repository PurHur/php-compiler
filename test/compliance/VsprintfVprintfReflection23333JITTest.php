<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: vsprintf/vprintf Reflection values + named values: (#23333).
 */
final class VsprintfVprintfReflection23333JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'vsprintf_vprintf_reflection_23333.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/vsprintf_vprintf_reflection_23333.phpt',
            'vsprintf_vprintf_reflection_23333.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
