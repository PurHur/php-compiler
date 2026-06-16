<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** MCJIT execute smoke — guards re-close #98 / #8721 when jit-runtime-probe is green. */
final class JitMcjitExecuteSmokeTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'jit_mcjit_execute_smoke.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/jit_mcjit_execute_smoke.phpt',
            'jit_mcjit_execute_smoke.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
