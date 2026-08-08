<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: clone of uninitialized lazy ghost initializes both (#29171).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class LazyGhostCloneInitializesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'lazy_ghost_clone_initializes.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/lazy_ghost_clone_initializes.phpt',
            'lazy_ghost_clone_initializes.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
