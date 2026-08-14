<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DirectoryIterator/FilesystemIterator residual excess argc (#31009). */
final class DirItExcessArgc31009JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_dirit_excess_argc_31009_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_dirit_excess_argc_31009_jit.phpt',
            'spl_dirit_excess_argc_31009_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
