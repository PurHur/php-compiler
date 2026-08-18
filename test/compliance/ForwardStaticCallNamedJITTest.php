<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: forward_static_call Reflection names callback/args (#24040). */
final class ForwardStaticCallNamedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'forward_static_call_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/forward_static_call_named.phpt',
            'forward_static_call_named.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
