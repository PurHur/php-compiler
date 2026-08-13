<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: dechex/decoct/decbin/octdec excess argc → ArgumentCountError (#30535). */
final class BaseConvertExcessArgc30535JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_base_convert_30535_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_base_convert_30535_jit.phpt',
            'excess_argc_base_convert_30535_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
