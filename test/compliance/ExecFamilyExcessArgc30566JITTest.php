<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: exec family excess argc → ArgumentCountError (#30566). */
final class ExecFamilyExcessArgc30566JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exec_family_excess_argc_30566_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/exec_family_excess_argc_30566_jit.phpt',
            'exec_family_excess_argc_30566_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
