<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SplFileObject residual excess argc (#31008). */
final class SplFileObjectExcessArgc31008JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_fileobject_excess_argc_31008_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_fileobject_excess_argc_31008_jit.phpt',
            'spl_fileobject_excess_argc_31008_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
