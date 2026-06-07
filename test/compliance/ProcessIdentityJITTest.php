<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for process identity builtins (#6119). */
final class ProcessIdentityJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'process_identity_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/process_identity_jit.phpt',
            'process_identity_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
