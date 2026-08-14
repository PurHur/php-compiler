<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: early-bound duplicate function declaration CompileFatal (#31109). */
final class DuplicateFunctionRedeclare31109JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'duplicate_function_decl_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/duplicate_function_decl_fatal.phpt',
            'duplicate_function_decl_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
