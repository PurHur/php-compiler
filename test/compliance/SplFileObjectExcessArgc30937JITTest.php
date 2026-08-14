<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SplFileObject/FilesystemIterator excess argc (#30937). */
final class SplFileObjectExcessArgc30937JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_splfileobject_30937_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_splfileobject_30937_jit.phpt',
            'excess_argc_splfileobject_30937_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
