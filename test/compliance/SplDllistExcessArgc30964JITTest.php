<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SplDoublyLinkedList / SplQueue residual excess argc (#30964). */
final class SplDllistExcessArgc30964JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_dllist_excess_argc_30964_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_dllist_excess_argc_30964_jit.phpt',
            'spl_dllist_excess_argc_30964_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
