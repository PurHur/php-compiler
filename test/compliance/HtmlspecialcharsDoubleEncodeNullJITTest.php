<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: htmlspecialchars() null $double_encode soft-DEP+coerce on PROFILE=8.4 (#29445).
 */
final class HtmlspecialcharsDoubleEncodeNullJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_double_encode_null_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_double_encode_null_forward84_jit.phpt',
            'htmlspecialchars_double_encode_null_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
